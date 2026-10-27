<?php

namespace App\Services;

use App\Models\Orders;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class OrderLifecycleNotificationService
{
    public const RENEWAL_NOTICE_HOURS = 24;

    public const LOW_VOLUME_GB = 1.0;

    public const MAX_CHECKS = 200;

    private const CLAIM_TIMEOUT_MINUTES = 5;

    private array $settings = [];

    private array $lastChatSend = [];

    private int $attempts = 0;

    private bool $rateLimited = false;

    public function __construct(private readonly OrderLifecycleService $lifecycle) {}

    public function sendDueNotifications(?Carbon $now = null): array
    {
        $now ??= now();
        $schedule = app(OrderCheckSchedule::class);
        $schedule->prepare();

        $this->settings = OrderNotificationSettings::load();
        $renewEnabled = $this->settings['renew'];
        $extraEnabled = $this->settings['volume'];
        $this->attempts = 0;
        $this->rateLimited = Cache::has('orders:notifications:telegram-pause');
        $deadline = microtime(true) + 40;
        $result = [
            'checked' => 0,
            'renewal_sent' => 0,
            'volume_sent' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        if ((! $renewEnabled && ! $extraEnabled) || $this->rateLimited) {
            return $result;
        }

        $ids = $schedule->due('notice_at', self::MAX_CHECKS);
        $orders = Orders::with('user')->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            if ($this->attempts >= 100 || $this->rateLimited || microtime(true) >= $deadline) {
                break;
            }
            $order = $orders->get($id);
            if (! $order) {
                $schedule->remove($id);

                continue;
            }
            $result['checked']++;
            $user = $order->user;
            if (! $user || (string) $user->status !== '1' || empty($user->tel_id)) {
                $result['skipped']++;
                $schedule->finishNotice($id, unreachable: true);

                continue;
            }
            try {
                if ($renewEnabled && $this->renewalIsDue($order, $now)) {
                    $this->deliver($order, 'renewal_24h', $this->renewalFingerprint($order),
                        $this->renewalMessage($order, $now), $this->renewalKeyboard($order), 'renewal_sent', $result);
                }
                if ($extraEnabled && $this->volumeIsDue($order, $now)) {
                    $this->deliver($order, 'low_volume', $this->volumeFingerprint($order),
                        $this->volumeMessage($order), $this->volumeKeyboard($order), 'volume_sent', $result);
                }
                $schedule->finishNotice($id);
            } catch (Throwable $exception) {
                $result['failed']++;
                $schedule->defer($id, 'notice_at', now()->addMinutes(5));
                Log::warning('Could not check scheduled order notice', ['order_id' => $id, 'error' => $exception->getMessage()]);
            }
        }

        return $result;
    }

    private function renewalIsDue(Orders $order, Carbon $now): bool
    {
        if (! $order->expire_at || ! $this->lifecycle->canRenew($order, $now)) {
            return false;
        }

        $expireAt = Carbon::parse($order->expire_at);

        return $expireAt->gt($now)
            && $expireAt->lte($now->copy()->addHours($this->settings['hours'] ?? self::RENEWAL_NOTICE_HOURS));
    }

    private function volumeIsDue(Orders $order, Carbon $now): bool
    {
        return app(OrderNoticePolicy::class)->volumeIsDue($order, $this->settings, $now);
    }

    private function deliver(
        Orders $order,
        string $type,
        string $fingerprint,
        string $message,
        array $keyboard,
        string $resultKey,
        array &$result
    ): void {
        if ($this->rateLimited || $this->attempts >= 100) {
            return;
        }
        $token = $this->claim($order, $type, $fingerprint);
        if ($token === null) {
            $result['skipped']++;

            return;
        }

        $this->attempts++;
        try {
            $this->paceTelegram((string) $order->user->tel_id);
            $response = $this->sendTelegramMessage([
                'chat_id' => $order->user->tel_id,
                'text' => $message,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            ]);
        } catch (Throwable $exception) {
            $response = ['ok' => false, 'error' => $exception->getMessage()];
        }

        if (! empty($response['ok'])) {
            $this->completeClaim($order->id, $type, $token);
            $result[$resultKey]++;

            return;
        }

        if ((int) ($response['error_code'] ?? 0) === 429) {
            $seconds = max(1, (int) ($response['parameters']['retry_after'] ?? 60));
            Cache::put('orders:notifications:telegram-pause', true, now()->addSeconds($seconds));
            $this->rateLimited = true;
        }
        $this->releaseClaim($order->id, $type, $token);
        $result['failed']++;
        Log::warning('Order lifecycle notification failed', [
            'order_id' => $order->id,
            'type' => $type,
            'error' => $response['description'] ?? $response['error'] ?? 'unknown_error',
        ]);
    }

    private function claim(Orders $order, string $type, string $fingerprint): ?string
    {
        return DB::transaction(function () use ($order, $type, $fingerprint) {
            $lockedOrder = Orders::where('id', $order->id)->lockForUpdate()->first();
            if (! $lockedOrder || $lockedOrder->isSiteManagedDedicated() || $this->lifecycle->shouldSkipProviderRefresh($lockedOrder)) {
                return null;
            }
            // Renewal/top-up may have completed after the scan selected this row.
            $isRenewal = $type === 'renewal_24h';
            if ($isRenewal ? ! $this->renewalIsDue($lockedOrder, now()) : ! $this->volumeIsDue($lockedOrder, now())) {
                return null;
            }
            if (($isRenewal ? $this->renewalFingerprint($lockedOrder) : $this->volumeFingerprint($lockedOrder)) !== $fingerprint) {
                return null;
            }

            $detail = is_array($lockedOrder->detail) ? $lockedOrder->detail : [];
            $lifecycle = is_array($detail['lifecycle'] ?? null) ? $detail['lifecycle'] : [];
            $notifications = is_array($lifecycle['notifications'] ?? null) ? $lifecycle['notifications'] : [];
            $current = is_array($notifications[$type] ?? null) ? $notifications[$type] : [];

            if (($current['state'] ?? '') === 'sending' && $this->claimIsFresh($current)) {
                return null;
            }
            if (($current['fingerprint'] ?? null) === $fingerprint) {
                if (($current['retry_at'] ?? 0) > now()->timestamp) {
                    return null;
                }
                if (($current['state'] ?? null) === 'sent') {
                    return null;
                }

            }

            $token = (string) Str::uuid();
            $notifications[$type] = [
                'fingerprint' => $fingerprint,
                'state' => 'sending',
                'token' => $token,
                'started_at' => now()->toDateTimeString(),
                'failures' => ($current['fingerprint'] ?? null) === $fingerprint ? (int) ($current['failures'] ?? 0) : 0,
            ];
            $lifecycle['notifications'] = $notifications;
            $detail['lifecycle'] = $lifecycle;
            $lockedOrder->detail = $detail;
            $lockedOrder->save();

            return $token;
        });
    }

    private function completeClaim(int $orderId, string $type, string $token): void
    {
        DB::transaction(function () use ($orderId, $type, $token) {
            $order = Orders::where('id', $orderId)->lockForUpdate()->first();
            if (! $order) {
                return;
            }

            $detail = is_array($order->detail) ? $order->detail : [];
            $lifecycle = is_array($detail['lifecycle'] ?? null) ? $detail['lifecycle'] : [];
            $notifications = is_array($lifecycle['notifications'] ?? null) ? $lifecycle['notifications'] : [];
            $notification = is_array($notifications[$type] ?? null) ? $notifications[$type] : [];
            if (($notification['token'] ?? null) !== $token) {
                return;
            }

            $notification['state'] = 'sent';
            $notification['sent_at'] = now()->toDateTimeString();
            unset($notification['token'], $notification['started_at']);
            $notifications[$type] = $notification;
            $lifecycle['notifications'] = $notifications;
            $detail['lifecycle'] = $lifecycle;
            $order->detail = $detail;
            if ($type === 'renewal_24h') {
                $order->reminded = 1;
            }
            $order->save();
        });
    }

    private function releaseClaim(int $orderId, string $type, string $token): void
    {
        DB::transaction(function () use ($orderId, $type, $token) {
            $order = Orders::where('id', $orderId)->lockForUpdate()->first();
            if (! $order) {
                return;
            }

            $detail = is_array($order->detail) ? $order->detail : [];
            $lifecycle = is_array($detail['lifecycle'] ?? null) ? $detail['lifecycle'] : [];
            $notifications = is_array($lifecycle['notifications'] ?? null) ? $lifecycle['notifications'] : [];
            $notification = is_array($notifications[$type] ?? null) ? $notifications[$type] : [];
            if (($notification['token'] ?? null) !== $token) {
                return;
            }

            $notification['state'] = 'failed';
            $notification['failures'] = min(6, ($notification['failures'] ?? 0) + 1);
            $notification['retry_at'] = now()->addMinutes(min(60, 2 ** $notification['failures']))->timestamp;
            unset($notification['token'], $notification['started_at']);
            $notifications[$type] = $notification;
            $lifecycle['notifications'] = $notifications;
            $detail['lifecycle'] = $lifecycle;
            $order->detail = $detail;
            $order->save();
        });
    }

    private function claimIsFresh(array $notification): bool
    {
        try {
            return Carbon::parse($notification['started_at'] ?? null)
                ->gte(now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES));
        } catch (Throwable) {
            return true;
        }
    }

    private function renewalFingerprint(Orders $order): string
    {
        return app(OrderNoticePolicy::class)->renewalFingerprint($order);
    }

    private function volumeFingerprint(Orders $order): string
    {
        return app(OrderNoticePolicy::class)->volumeFingerprint($order);
    }

    private function renewalMessage(Orders $order, Carbon $now): string
    {
        $minutes = max(1, (int) ceil($now->diffInMinutes(Carbon::parse($order->expire_at), false)));
        $remaining = $minutes >= 60 ? (int) ceil($minutes / 60).' ساعت' : $minutes.' دقیقه';

        return headTitle('⏰ زمان تمدید سرویس نزدیک است')
            ."<code>{$this->escape($order->remark)}</code> · #{$order->id}\n"
            ."زمان باقی‌مانده: <b>{$remaining}</b>\nبرای تمدید، دکمهٔ زیر را بزنید.";
    }

    private function volumeMessage(Orders $order): string
    {
        $left = max(0, (float) $this->lifecycleDetail($order)['left_gb']);

        return headTitle('⚠️ حجم سرویس رو به اتمام است')
            ."<code>{$this->escape($order->remark)}</code> · #{$order->id}\n"
            ."حجم باقی‌مانده: <b>{$this->formatGb($left)} گیگ</b>\nبرای خرید حجم، دکمهٔ زیر را بزنید.";
    }

    private function renewalKeyboard(Orders $order): array
    {
        return [
            [[
                'text' => '🔄 تمدید سرویس',
                'callback_data' => "type=clientRenewOrder|id={$order->id}",
            ], [
                'text' => '📄 مشاهده سفارش',
                'callback_data' => "type=clientSingleOrder|id={$order->id}",
            ]],
        ];
    }

    private function volumeKeyboard(Orders $order): array
    {
        return [
            [[
                'text' => '➕ خرید حجم',
                'callback_data' => "type=clientBuyExtra|id={$order->id}",
            ], [
                'text' => '📄 مشاهده سفارش',
                'callback_data' => "type=clientSingleOrder|id={$order->id}",
            ]],
        ];
    }

    private function lifecycleDetail(Orders $order): array
    {
        $detail = is_array($order->detail) ? $order->detail : [];

        return is_array($detail['lifecycle'] ?? null) ? $detail['lifecycle'] : [];
    }

    private function formatGb(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected function paceTelegram(string $chatId): void
    {
        $wait = max(0.12, 1.1 - (microtime(true) - ($this->lastChatSend[$chatId] ?? 0)));
        usleep((int) ($wait * 1_000_000));
        $this->lastChatSend[$chatId] = microtime(true);
    }

    protected function sendTelegramMessage(array $params): array
    {
        return (new Telegram((string) env('TELEGRAM_BOT_TOKEN')))->sendMessage($params);
    }
}
