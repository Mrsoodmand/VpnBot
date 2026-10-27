<?php

namespace App\Services;

use App\Models\Orders;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderCheckSchedule
{
    public const SEED_LIMIT = 200;

    private ?array $settings = null;

    private ?bool $available = null;

    private ?string $preparedSignature = null;

    public function available(): bool
    {
        return $this->available ??= Schema::hasTable('order_checks');
    }

    public function settings(?array $settings = null): array
    {
        if ($settings !== null) {
            $this->settings = $settings;
        }

        return $this->settings ??= OrderNotificationSettings::load();
    }

    public function forgetSettings(): void
    {
        $this->settings = null;
    }

    /** Backfill old/raw-inserted orders once, in primary-key batches. */
    public function prepare(): array
    {
        $settings = $this->settings(OrderNotificationSettings::load());
        $hash = hash('sha256', json_encode($settings));
        $signature = now()->format('Y-m-d H:i').$hash;
        if ($this->preparedSignature === $signature) {
            return ['seeded' => 0, 'rescheduled' => 0];
        }
        $last = (int) Cache::get('orders:checks:seed-id', 0);
        $orders = Orders::where('id', '>', $last)->orderBy('id')->limit(self::SEED_LIMIT)->get();
        $existing = DB::table('order_checks')->whereIn('order_id', $orders->modelKeys())->pluck('order_id')->flip();
        foreach ($orders as $order) {
            if (! isset($existing[$order->id])) {
                $this->refresh($order, forceProvider: true);
            }
        }
        if ($orders->isNotEmpty()) {
            Cache::forever('orders:checks:seed-id', $orders->last()->id);
        }

        // Settings changes reschedule existing rows incrementally, never UPDATE all orders.
        $rebuild = Cache::get('orders:checks:settings', []);
        if (($rebuild['hash'] ?? '') !== $hash) {
            $wakeProvider = isset($rebuild['interval']) && $rebuild['interval'] !== $settings['interval'];
            $rebuild = ['hash' => $hash, 'cursor' => 0, 'ceiling' => (int) Orders::max('id'), 'interval' => $settings['interval'], 'wake_provider' => $wakeProvider];
        }
        $rescheduled = 0;
        if ($rebuild['cursor'] < $rebuild['ceiling']) {
            $rows = Orders::where('id', '>', $rebuild['cursor'])->where('id', '<=', $rebuild['ceiling'])
                ->orderBy('id')->limit(self::SEED_LIMIT)->get();
            foreach ($rows as $order) {
                $this->refresh($order, forceProvider: $rebuild['wake_provider'] ?? false);
            }
            $rescheduled = $rows->count();
            $rebuild['cursor'] = $rows->count() < self::SEED_LIMIT ? $rebuild['ceiling'] : $rows->last()->id;
        }
        Cache::forever('orders:checks:settings', $rebuild);
        $this->preparedSignature = $signature;

        return ['seeded' => $orders->count(), 'rescheduled' => $rescheduled];
    }

    /** Called on model saves; one indexed upsert, no order/user scan. */
    public function refresh(Orders $order, bool $forceProvider = false, bool $providerRead = false): void
    {
        if (! $this->available()) {
            return;
        } // Upload may precede the migration; backfill catches these rows.
        $settings = $this->settings();
        $now = now();
        $terminal = $order->isSiteManagedDedicated() || app(OrderLifecycleService::class)->shouldSkipProviderRefresh($order);
        $grace = $order->expire_at?->copy()->addDays(OrderLifecycleService::RENEWAL_GRACE_DAYS);
        $overGrace = $grace && $grace->lte($now);
        $stateAt = null;
        if (! $terminal && $order->expire_at) {
            if ($order->expire_at->gt($now)) {
                $stateAt = $order->expire_at;
            } elseif ($overGrace) {
                $stateAt = (string) $order->status === Orders::STATUS_INACTIVE ? null : $now;
            } elseif (! in_array((string) $order->status, ['suspended', 'expired', '2'], true)) {
                $stateAt = $now;
            } else {
                $stateAt = $grace;
            }
        }
        $row = [
            'order_id' => $order->id, 'panel_id' => $order->panel_id,
            'notice_at' => app(OrderNoticePolicy::class)->nextAt($order, $settings, $now),
            'state_at' => $stateAt,
            'cleanup_at' => ! $terminal && $overGrace ? $now : null,
            'provider_at' => ! $terminal && ! $overGrace && $order->panel_id && $order->uid
                ? ($providerRead ? $now->copy()->addMinutes($settings['interval']) : $now) : null,
        ];
        $updates = ['panel_id', 'notice_at', 'state_at'];
        $oldDetail = is_array($order->getOriginal('detail')) ? $order->getOriginal('detail') : [];
        $newDetail = is_array($order->detail) ? $order->detail : [];
        unset($oldDetail['lifecycle'], $newDetail['lifecycle']);
        $businessDetailChanged = $order->wasChanged('detail') && $oldDetail != $newDetail;
        if ($forceProvider || $providerRead || $order->wasRecentlyCreated || $businessDetailChanged || $order->wasChanged(['panel_id', 'uid', 'status', 'expire_at', 'plan']) || $terminal || $overGrace) {
            $updates[] = 'provider_at';
        }
        // Preserve an existing cleanup retry deadline during metadata/notice writes.
        if ($forceProvider || $order->wasRecentlyCreated || $order->wasChanged(['status', 'expire_at']) || ! $overGrace || $terminal) {
            $updates[] = 'cleanup_at';
        }
        foreach ($row as $key => $value) {
            if ($value instanceof Carbon) {
                $row[$key] = $value->toDateTimeString();
            }
        }
        DB::table('order_checks')->upsert([$row], ['order_id'], $updates);
    }

    public function due(string $column, int $limit, ?int $panelId = null): array
    {
        $this->checkColumn($column);

        return DB::table('order_checks')->where($column, '<=', now())
            ->when($panelId !== null, fn ($q) => $q->where('panel_id', $panelId))
            ->orderBy($column)->orderBy('order_id')->limit($limit)->pluck('order_id')->all();
    }

    public function defer(int $orderId, string $column, ?Carbon $until): void
    {
        $this->checkColumn($column);
        DB::table('order_checks')->where('order_id', $orderId)->update([$column => $until?->toDateTimeString()]);
    }

    public function finishNotice(int $orderId, bool $unreachable = false): void
    {
        DB::transaction(function () use ($orderId, $unreachable) {
            $order = Orders::whereKey($orderId)->lockForUpdate()->first();
            if (! $order) {
                $this->remove($orderId);

                return;
            }
            $next = app(OrderNoticePolicy::class)->nextAt($order, $this->settings(), now());
            $this->defer($orderId, 'notice_at', $unreachable && $next ? $next->max(now()->addHours(6)) : $next);
        });
    }

    public function remove(int $id): void
    {
        if ($this->available()) {
            DB::table('order_checks')->where('order_id', $id)->delete();
        }
    }

    private function checkColumn(string $column): void
    {
        if (! in_array($column, ['notice_at', 'state_at', 'provider_at', 'cleanup_at'], true)) {
            throw new \InvalidArgumentException('Unknown order check');
        }
    }
}
