<?php

namespace App\Services;

use App\Models\Carts;
use App\Models\Countries;
use App\Models\ExtraBandwidth;
use App\Models\Inbounds;
use App\Models\Orders;
use App\Models\Panels;
use App\Models\Payment;
use App\Models\Plans;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\lib\PasarGuard;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class WpSyncService
{
    public function secret(): string
    {
        return (string) config('services.wp_sync.secret', '');
    }

    public function wpBaseUrl(): string
    {
        return rtrim((string) config('services.wp_sync.base_url', 'https://ip-sabet.me'), '/');
    }

    public function authorize(?string $key): bool
    {
        $secret = $this->secret();
        return $secret !== '' && is_string($key) && hash_equals($secret, $key);
    }

    /* ---------------------------------------------------------------------
     * Link handling
     * ------------------------------------------------------------------ */

    public function confirmWordPressLink(User $telegramUser, string $code): array
    {
        $payload = [
            'code' => trim($code),
            'tel_id' => (string) $telegramUser->tel_id,
            'telegram' => [
                'username' => $telegramUser->username,
                'first_name' => $telegramUser->first_name,
                'last_name' => $telegramUser->last_name,
            ],
        ];

        $response = Http::timeout(20)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-IPSABET-API-KEY' => $this->secret()])
            ->post($this->wpBaseUrl() . '/wp-json/ipsvp/v1/sync/confirm-link', $payload);

        if (!$response->successful()) {
            return [
                'ok' => false,
                'message' => $response->json('message') ?: ('خطای اتصال به سایت: HTTP ' . $response->status()),
                'body' => $response->body(),
            ];
        }

        $data = $response->json() ?: [];
        if (empty($data['ok'])) {
            return ['ok' => false, 'message' => $data['message'] ?? 'اتصال حساب تایید نشد.'];
        }

        $phone = (string) ($data['phone'] ?? '');
        $this->upsertLink($telegramUser, $phone, (int) ($data['site_user_id'] ?? 0));

        if (!empty($data['orders']) && is_array($data['orders'])) {
            foreach ($data['orders'] as $order) {
                if (is_array($order)) {
                    $this->importSiteOrder($order, $telegramUser);
                }
            }
        }

        return ['ok' => true, 'phone' => $phone, 'orders_count' => count($data['orders'] ?? [])];
    }

    public function upsertLink(User $user, string $phone, int $siteUserId = 0): void
    {
        if (!Schema::hasTable('wp_sync_links')) return;

        $now = now();
        DB::table('wp_sync_links')->updateOrInsert(
            ['tel_id' => (string) $user->tel_id],
            [
                'user_id' => $user->id,
                'phone' => $phone,
                'site_user_id' => $siteUserId ?: null,
                'linked_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function linkStatus(array $payload): array
    {
        $link = null;
        if (Schema::hasTable('wp_sync_links')) {
            if (!empty($payload['tel_id'])) {
                $link = DB::table('wp_sync_links')->where('tel_id', (string) $payload['tel_id'])->first();
            }
            if (!$link && !empty($payload['phone'])) {
                $link = DB::table('wp_sync_links')->whereIn('phone', $this->phoneCandidates((string) $payload['phone']))->first();
            }
            if (!$link && !empty($payload['site_user_id'])) {
                $link = DB::table('wp_sync_links')->where('site_user_id', (int) $payload['site_user_id'])->first();
            }
        }

        $user = $link ? User::find($link->user_id) : $this->resolveUser($payload);
        return [
            'linked' => (bool) $link,
            'link' => $link ? [
                'user_id' => $link->user_id,
                'site_user_id' => $link->site_user_id,
                'tel_id' => (string) $link->tel_id,
                'phone' => (string) $link->phone,
                'linked_at' => (string) $link->linked_at,
            ] : null,
            'user' => $user ? $this->formatUser($user) : null,
        ];
    }

    public function disconnectLink(?string $telId = null, ?string $phone = null, $siteUserId = null): int
    {
        if (!Schema::hasTable('wp_sync_links')) return 0;
        $query = DB::table('wp_sync_links');
        if ($telId) {
            $query->where('tel_id', (string) $telId);
        } elseif ($phone) {
            $query->whereIn('phone', $this->phoneCandidates((string) $phone));
        } elseif ($siteUserId) {
            $query->where('site_user_id', (int) $siteUserId);
        } else {
            return 0;
        }
        return (int) $query->delete();
    }

    public function linkedUserByPhone(?string $phone): ?User
    {
        if (!$phone || !Schema::hasTable('wp_sync_links')) return null;
        $link = DB::table('wp_sync_links')->whereIn('phone', $this->phoneCandidates($phone))->first();
        return $link ? User::find($link->user_id) : null;
    }

    public function linkedUserByTelId(?string $telId): ?User
    {
        if (!$telId) return null;
        if (Schema::hasTable('wp_sync_links')) {
            $link = DB::table('wp_sync_links')->where('tel_id', (string) $telId)->first();
            if ($link && $link->user_id) return User::find($link->user_id);
        }
        return User::where('tel_id', (string) $telId)->first();
    }

    public function resolveUser(array $payload): ?User
    {
        if (!empty($payload['user_id'])) {
            $user = User::find((int) $payload['user_id']);
            if ($user) return $user;
        }
        if (!empty($payload['tel_id'])) {
            $user = $this->linkedUserByTelId((string) $payload['tel_id']);
            if ($user) return $user;
        }
        if (!empty($payload['phone'])) {
            $user = $this->linkedUserByPhone((string) $payload['phone']);
            if ($user) return $user;
        }
        if (!empty($payload['site_user_id']) && Schema::hasTable('wp_sync_links')) {
            $link = DB::table('wp_sync_links')->where('site_user_id', (int) $payload['site_user_id'])->first();
            if ($link) return User::find($link->user_id);
        }
        return null;
    }

    public function phoneCandidates(string $phone): array
    {
        $phone = trim($phone);
        $digits = preg_replace('/\D+/', '', $phone) ?: $phone;
        $candidates = [$phone, $digits, '+' . $digits];
        if (str_starts_with($digits, '98')) {
            $candidates[] = '0' . substr($digits, 2);
            $candidates[] = substr($digits, 2);
            $candidates[] = '+98' . substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            $candidates[] = '98' . substr($digits, 1);
            $candidates[] = '+98' . substr($digits, 1);
        }
        return array_values(array_unique(array_filter($candidates)));
    }

    /* ---------------------------------------------------------------------
     * Formatting helpers
     * ------------------------------------------------------------------ */

    public function formatUser(User $user, bool $full = false): array
    {
        $link = Schema::hasTable('wp_sync_links')
            ? DB::table('wp_sync_links')->where('user_id', $user->id)->first()
            : null;

        $data = [
            'id' => $user->id,
            'tel_id' => (string) $user->tel_id,
            'username' => $user->username ? '@' . ltrim($user->username, '@') : '',
            'first_name' => (string) ($user->first_name ?? ''),
            'last_name' => (string) ($user->last_name ?? ''),
            'name' => trim((string) ($user->first_name ?? '') . ' ' . (string) ($user->last_name ?? '')),
            'balance' => (int) $user->balance,
            'status' => (string) $user->status,
            'status_label' => $this->userStatusLabel($user->status),
            'is_admin' => (int) $user->is_admin,
            'is_seller' => (int) $user->is_seller,
            'path' => (string) $user->path,
            'created_at' => optional($user->created_at)->toDateTimeString(),
            'updated_at' => optional($user->updated_at)->toDateTimeString(),
            'link' => $link ? [
                'linked' => true,
                'phone' => (string) $link->phone,
                'site_user_id' => $link->site_user_id,
                'linked_at' => (string) $link->linked_at,
            ] : ['linked' => false],
        ];

        if ($full) {
            $data['orders_count'] = Orders::where('user_id', $user->id)->count();
            $data['payments_count'] = Payment::where('user_id', $user->id)->count();
            $data['wallet'] = $this->walletSummary($user);
            $data['tel_detail'] = is_array($user->tel_detail) ? $user->tel_detail : [];
        }

        return $data;
    }

    public function formatOrder(Orders $order, bool $full = false): array
    {
        $detail = is_array($order->detail) ? $order->detail : (json_decode((string) $order->detail, true) ?: []);
        if (empty($detail['code']) && !empty($order->remark)) $detail['code'] = $order->remark;
        if (empty($detail['pg_user_id']) && !empty($order->uid)) $detail['pg_user_id'] = (string) $order->uid;
        if (empty($detail['subscription_url']) && !empty($order->sub_id)) $detail['subscription_url'] = (string) $order->sub_id;
        if (empty($detail['pg_status']) && isset($order->status)) $detail['pg_status'] = (string) $order->status;

        $panel = $order->panel_id ? Panels::find($order->panel_id) : null;
        $user = $order->user_id ? User::find($order->user_id) : null;
        $data = [
            'id' => $order->id,
            'remark' => $order->remark,
            'code' => $detail['code'] ?? $order->remark,
            'uid' => (string) $order->uid,
            'sub_id' => (string) $order->sub_id,
            'subscription_url' => (string) ($detail['subscription_url'] ?? $order->sub_id),
            'plan' => $order->plan,
            'status' => (string) $order->status,
            'status_label' => $this->orderStatusLabel($order->status),
            'panel_id' => $order->panel_id,
            'panel_name' => $panel?->name,
            'panel_url' => $panel?->url,
            'inbound_id' => $order->inbound_id,
            'system_type' => $order->system_type ?: 'bot',
            'source' => $order->system_type ?: 'bot',
            'expire_at' => optional($order->expire_at)->toDateTimeString(),
            'created_at' => optional($order->created_at)->toDateTimeString(),
            'updated_at' => optional($order->updated_at)->toDateTimeString(),
            'detail' => $detail,
            'user' => $user ? [
                'id' => $user->id,
                'tel_id' => (string) $user->tel_id,
                'username' => $user->username ? '@' . ltrim($user->username, '@') : '',
                'name' => trim((string) $user->first_name . ' ' . (string) $user->last_name),
            ] : null,
        ];

        if ($full) {
            $data['links'] = $this->extractOrderLinks($order, $detail);
            $data['payments'] = Payment::where('order_id', $order->id)->orderByDesc('id')->limit(20)->get()->map(fn ($p) => $this->formatPayment($p))->values();
        }

        return $data;
    }

    public function extractOrderLinks(Orders $order, array $detail): array
    {
        $links = [];
        foreach (['links', 'configs', 'config_links'] as $key) {
            if (!empty($detail[$key]) && is_array($detail[$key])) {
                foreach ($detail[$key] as $link) {
                    if (is_string($link)) $links[] = $link;
                }
            }
        }
        foreach (['code', 'config', 'subscription_url'] as $key) {
            if (!empty($detail[$key]) && is_string($detail[$key]) && preg_match('#^(vless|vmess|trojan|ss|http|https)://#i', $detail[$key])) {
                $links[] = $detail[$key];
            }
        }
        if (!empty($order->sub_id) && preg_match('#^https?://#i', (string) $order->sub_id)) $links[] = (string) $order->sub_id;
        return array_values(array_unique($links));
    }

    public function formatPayment(Payment $payment, bool $full = false): array
    {
        $detail = is_array($payment->detail) ? $payment->detail : (json_decode((string) $payment->detail, true) ?: []);
        $user = $payment->user_id ? User::find($payment->user_id) : null;
        $data = [
            'id' => $payment->id,
            'user_id' => $payment->user_id,
            'order_id' => $payment->order_id,
            'admin_id' => $payment->admin_id,
            'method' => (string) $payment->method,
            'method_label' => $this->paymentMethodLabel($payment->method),
            'ref_id' => (string) $payment->ref_id,
            'type' => (string) $payment->type,
            'type_label' => $this->paymentTypeLabel($payment->type),
            'price' => (int) $payment->price,
            'status' => (string) $payment->status,
            'status_label' => $this->paymentStatusLabel($payment->status),
            'created_at' => optional($payment->created_at)->toDateTimeString(),
            'updated_at' => optional($payment->updated_at)->toDateTimeString(),
            'expired_at' => optional($payment->expired_at)->toDateTimeString(),
            'detail' => $detail,
            'user' => $user ? [
                'id' => $user->id,
                'tel_id' => (string) $user->tel_id,
                'username' => $user->username ? '@' . ltrim($user->username, '@') : '',
                'name' => trim((string) $user->first_name . ' ' . (string) $user->last_name),
            ] : null,
        ];
        if ($full && $payment->order_id) {
            $order = Orders::find($payment->order_id);
            $data['order'] = $order ? $this->formatOrder($order) : null;
        }
        return $data;
    }

    public function formatPanel(Panels $panel, bool $full = false): array
    {
        $detail = is_array($panel->detail) ? $panel->detail : (json_decode((string) $panel->detail, true) ?: []);
        $data = [
            'id' => $panel->id,
            'name' => $panel->name,
            'url' => $panel->url,
            'sub_address' => $panel->sub_address ?? null,
            'panel_type' => $panel->panel_type,
            'country_id' => $panel->country_id,
            'type' => $panel->type,
            'status' => (string) $panel->status,
            'system_type' => $panel->system_type,
            'created_at' => optional($panel->created_at)->toDateTimeString(),
            'updated_at' => optional($panel->updated_at)->toDateTimeString(),
        ];
        if ($full) {
            $data['detail'] = $detail;
            $data['inbounds'] = Inbounds::where('panel_id', $panel->id)->get()->map(fn ($i) => $this->formatInbound($i))->values();
        }
        return $data;
    }

    public function formatInbound(Inbounds $inbound): array
    {
        $setting = is_array($inbound->setting) ? $inbound->setting : (json_decode((string) ($inbound->setting ?? ''), true) ?: []);
        return [
            'id' => $inbound->id,
            'panel_id' => $inbound->panel_id,
            'inbound_id' => $inbound->inbound_id,
            'country_id' => $inbound->country_id ?? null,
            'remark' => $inbound->remark,
            'port' => $inbound->port,
            'status' => (string) $inbound->status,
            'setting' => $setting,
            'created_at' => optional($inbound->created_at)->toDateTimeString(),
        ];
    }

    public function orderStatusLabel(?string $status): string
    {
        return match ((string) $status) {
            '1', 'created', 'active' => 'فعال',
            '0', 'data_exhausted', 'limited' => 'اتمام حجم',
            '2', 'suspended', 'expired', 'on_hold' => 'اتمام زمان (معلق)',
            'inactive', 'cancelled', 'disabled', '-1', '-2' => 'غیرفعال',
            'pending', 'waiting_review', 'review' => 'در انتظار بررسی',
            'deleted' => 'حذف شده',
            default => (string) $status,
        };
    }

    public function userStatusLabel($status): string
    {
        return match ((string) $status) {
            '1' => 'فعال',
            '0' => 'غیرفعال',
            '-2' => 'مسدود شده',
            '-3' => 'در انتظار فعال سازی',
            default => (string) $status,
        };
    }

    public function paymentStatusLabel($status): string
    {
        return match ((string) $status) {
            '1', 'approved' => 'تایید شده',
            '0', 'pending' => 'در انتظار بررسی',
            '-1', 'rejected' => 'رد شده',
            '-2', 'refunded' => 'برگشت به کیف پول',
            default => (string) $status,
        };
    }

    public function paymentMethodLabel($method): string
    {
        return match ((string) $method) {
            'wallet', 'wordpress_wallet' => 'کیف پول',
            'cart-be-cart', 'cart_be_cart', 'card' => 'کارت به کارت',
            'gateway', 'online' => 'درگاه پرداخت',
            'crypto' => 'ارز دیجیتال',
            'admin_credit' => 'شارژ دستی ادمین',
            'admin_debit' => 'کسر دستی ادمین',
            default => $method ? (string) $method : '-',
        };
    }

    public function paymentTypeLabel($type): string
    {
        return match ((string) $type) {
            '1', 'order' => 'خرید سرویس',
            '2', 'renew' => 'تمدید سرویس',
            '3', 'extra' => 'خرید حجم',
            '4', 'wallet', 'wallet_charge' => 'شارژ کیف پول',
            'admin_credit' => 'شارژ دستی کیف پول',
            'admin_debit' => 'کسر دستی از کیف پول',
            'wp_order' => 'خرید سایت با کیف پول',
            default => $type ? (string) $type : '-',
        };
    }

    /* ---------------------------------------------------------------------
     * Wallet
     * ------------------------------------------------------------------ */

    public function walletSummary(User $user): array
    {
        $approved = Payment::where('user_id', $user->id)->where('status', 1)->sum('price');
        $pending = Payment::where('user_id', $user->id)->where('status', 0)->sum('price');
        return [
            'balance' => (int) $user->balance,
            'approved_total' => (int) $approved,
            'pending_total' => (int) $pending,
            'transactions_count' => Payment::where('user_id', $user->id)->count(),
            'currency' => 'تومان',
        ];
    }

    public function walletTransactions(User $user, int $page = 1, int $perPage = 20): array
    {
        return $this->paginateQuery(
            Payment::query()->forWalletHistory($user)->orderByDesc('id'),
            $page,
            $perPage,
            fn ($payment) => $this->formatPayment($payment)
        );
    }

    public function creditWallet(User $user, int $amount, array $meta = []): int
    {
        $amount = max(0, $amount);
        if ($amount <= 0) return (int) $user->balance;
        $method = (string) ($meta['method'] ?? $meta['source'] ?? 'wordpress_wallet');
        $refId = (string) ($meta['ref_id'] ?? $meta['external_ref'] ?? '');

        if ($refId !== '') {
            $exists = Payment::where('user_id', $user->id)->where('method', $method)->where('ref_id', $refId)->where('status', 1)->first();
            if ($exists) return (int) $user->fresh()->balance;
        }

        DB::transaction(function () use ($user, $amount, $meta, $method, $refId) {
            $target = User::where('id', $user->id)->lockForUpdate()->first();
            $before = (int) $target->balance;
            $target->balance = $before + $amount;
            $target->save();
            if (in_array($method, ['admin_credit', 'admin_debit'], true)) {
                $meta['loyalty_approved_at'] = now()->toIso8601String();
            }
            $meta['wallet_balance_before'] = $before;
            $meta['wallet_balance_after'] = (int) $target->balance;
            Payment::create([
                'user_id' => $target->id,
                'order_id' => (int) ($meta['order_id'] ?? 0),
                'admin_id' => $meta['admin_id'] ?? null,
                'method' => $method,
                'ref_id' => $refId ?: null,
                'type' => $meta['type'] ?? 4,
                'price' => $amount,
                'status' => 1,
                'detail' => $meta,
                'expired_at' => now(),
            ]);
        });
        return (int) User::find($user->id)->balance;
    }

    public function debitWallet(User $user, int $amount, array $meta = []): array
    {
        $amount = max(0, $amount);
        if ($amount <= 0) return ['ok' => false, 'message' => 'amount_is_required', 'balance' => (int) $user->balance];
        $method = (string) ($meta['method'] ?? 'wallet');
        $refId = (string) ($meta['ref_id'] ?? $meta['external_ref'] ?? '');

        if ($refId !== '') {
            $exists = Payment::where('user_id', $user->id)->where('method', $method)->where('ref_id', $refId)->where('status', 1)->first();
            if ($exists) return ['ok' => true, 'balance' => (int) $user->fresh()->balance, 'idempotent' => true];
        }

        return DB::transaction(function () use ($user, $amount, $meta, $method, $refId) {
            $target = User::where('id', $user->id)->lockForUpdate()->first();
            if ((int) $target->balance < $amount) {
                return ['ok' => false, 'message' => 'موجودی کیف پول کافی نیست.', 'balance' => (int) $target->balance];
            }
            $before = (int) $target->balance;
            $target->balance = $before - $amount;
            $target->save();
            if (in_array($method, ['admin_credit', 'admin_debit'], true)) {
                $meta['loyalty_approved_at'] = now()->toIso8601String();
            }
            $meta['wallet_balance_before'] = $before;
            $meta['wallet_balance_after'] = (int) $target->balance;
            Payment::create([
                'user_id' => $target->id,
                'order_id' => (int) ($meta['order_id'] ?? 0),
                'admin_id' => $meta['admin_id'] ?? null,
                'method' => $method,
                'ref_id' => $refId ?: null,
                'type' => $meta['type'] ?? 'wp_order',
                'price' => $amount,
                'status' => 1,
                'detail' => $meta,
                'expired_at' => now(),
            ]);
            return ['ok' => true, 'balance' => (int) $target->balance];
        });
    }

    public function adminWalletAdjust(User $user, string $direction, int $amount, string $note = '', array $meta = []): array
    {
        $amount = max(0, $amount);
        if ($amount <= 0) return ['ok' => false, 'message' => 'amount_is_required'];
        $meta['note'] = $note;
        $meta['source'] = 'admin_adjust';
        if ($direction === 'debit') {
            $meta['method'] = 'admin_debit';
            $meta['type'] = 'admin_debit';
            return $this->debitWallet($user, $amount, $meta);
        }
        $meta['method'] = 'admin_credit';
        $meta['type'] = 'admin_credit';
        $balance = $this->creditWallet($user, $amount, $meta);
        return ['ok' => true, 'balance' => $balance];
    }

    /* ---------------------------------------------------------------------
     * Orders
     * ------------------------------------------------------------------ */

    private function normalizedPanelUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') return '';
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        $url = preg_replace('/#.*/', '', $url);
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) return rtrim($url, '/');
        $base = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (!empty($parts['port'])) $base .= ':' . (int) $parts['port'];
        $path = isset($parts['path']) ? trim($parts['path'], '/') : '';
        $path = preg_replace('#/(dashboard|login|docs|redoc|openapi\.json).*$#i', '', $path);
        $path = trim($path, '/');
        if ($path !== '') $base .= '/' . $path;
        return rtrim($base, '/');
    }

    public function findPanelForSiteOrder(array $siteOrder): ?Panels
    {
        $candidates = array_filter(array_unique([
            $this->normalizedPanelUrl($siteOrder['panel_url'] ?? ''),
            $this->normalizedPanelUrl($siteOrder['panel_url_raw'] ?? ''),
            trim((string) ($siteOrder['panel_url'] ?? '')),
            trim((string) ($siteOrder['panel_url_raw'] ?? '')),
        ]));
        foreach ($candidates as $url) {
            $panel = Panels::where('url', $url)
                ->orWhere('url', rtrim($url, '/'))
                ->orWhere('url', rtrim($url, '/') . '/')
                ->first();
            if ($panel) return $panel;
        }
        return null;
    }

    public function importSiteOrder(array $siteOrder, ?User $fallbackUser = null): ?Orders
    {
        if (($siteOrder['service_type'] ?? '') === 'dedicated') return null;

        $siteOrderId = (int) ($siteOrder['site_order_id'] ?? $siteOrder['id'] ?? 0);
        if ($siteOrderId <= 0) return null;

        $user = $fallbackUser;
        if (!$user && !empty($siteOrder['tel_id'])) $user = $this->linkedUserByTelId((string) $siteOrder['tel_id']);
        if (!$user && !empty($siteOrder['phone'])) $user = $this->linkedUserByPhone((string) $siteOrder['phone']);
        if (!$user) return null;

        $mapping = Schema::hasTable('wp_sync_site_orders') ? DB::table('wp_sync_site_orders')->where('site_order_id', $siteOrderId)->first() : null;
        $panel = $this->findPanelForSiteOrder($siteOrder);
        $detail = [
            'source' => 'wordpress',
            'site_order_id' => $siteOrderId,
            'order_code' => $siteOrder['order_code'] ?? '',
            'phone' => $siteOrder['phone'] ?? '',
            'country' => trim(($siteOrder['country_flag'] ?? '') . ' ' . ($siteOrder['country_name'] ?? '')),
            'price' => (int) ($siteOrder['price'] ?? 0),
            'subscription_url' => $siteOrder['subscription_url'] ?? '',
            'links' => $siteOrder['links'] ?? [],
            'code' => $siteOrder['pg_username'] ?? ($siteOrder['order_code'] ?? ''),
            'pg_user_id' => $siteOrder['pg_user_id'] ?? '',
            'pg_status' => $siteOrder['pg_status'] ?? '',
            'panel_url' => $siteOrder['panel_url'] ?? '',
            'panel_url_raw' => $siteOrder['panel_url_raw'] ?? '',
            'raw' => $siteOrder,
        ];
        $expireAt = !empty($siteOrder['expire_at'])
            ? Carbon::parse($siteOrder['expire_at'])
            : (!empty($siteOrder['approved_at']) ? Carbon::parse($siteOrder['approved_at'])->addDays((int) ($siteOrder['days'] ?? 0)) : now()->addDays(max(1, (int) ($siteOrder['days'] ?? 1))));

        $payload = [
            'user_id' => $user->id,
            'remark' => $siteOrder['pg_username'] ?: ($siteOrder['order_code'] ?? ('SITE-' . $siteOrderId)),
            'uid' => (string) ($siteOrder['pg_user_id'] ?? ''),
            'sub_id' => (string) ($siteOrder['subscription_url'] ?? ''),
            'plan' => trim(($siteOrder['plan_title'] ?? '') . ' | ' . ((int) ($siteOrder['gb'] ?? 0)) . 'GB / ' . ((int) ($siteOrder['days'] ?? 0)) . ' روز'),
            'status' => (string) ($siteOrder['status'] ?? 'created'),
            'panel_id' => $panel?->id,
            'inbound_id' => (int) ($siteOrder['inbound_id'] ?? 0),
            'system_type' => 'wordpress',
            'detail' => $detail,
            'expire_at' => $expireAt,
            'updated_at' => now(),
        ];

        if ($mapping && $mapping->bot_order_id) {
            $order = Orders::find($mapping->bot_order_id);
            if ($order) {
                $order->fill($payload);
                $order->save();
                return $order;
            }
        }

        $payload['created_at'] = now();
        $order = Orders::create($payload);
        if (Schema::hasTable('wp_sync_site_orders')) {
            DB::table('wp_sync_site_orders')->updateOrInsert(
                ['site_order_id' => $siteOrderId],
                [
                    'bot_order_id' => $order->id,
                    'user_id' => $user->id,
                    'phone' => (string) ($siteOrder['phone'] ?? ''),
                    'order_code' => (string) ($siteOrder['order_code'] ?? ''),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
        return $order;
    }

    public function findOrder(int $id, array $payload = []): ?Orders
    {
        if ($id <= 0) return null;
        $query = Orders::botManaged()->where('id', $id);
        $user = $this->resolveUser($payload);
        if ($user) $query->where('user_id', $user->id);
        return $query->first();
    }

    public function updateBotOrder(User $user, int $orderId, array $payload): ?Orders
    {
        $order = Orders::botManaged()->where('id', $orderId)->where('user_id', $user->id)->first();
        if (!$order) return null;
        $detail = is_array($order->detail) ? $order->detail : (json_decode((string) $order->detail, true) ?: []);
        if (!empty($payload['detail']) && is_array($payload['detail'])) {
            $detail = array_replace_recursive($detail, $payload['detail']);
        }
        $update = ['detail' => $detail, 'updated_at' => now()];
        foreach (['status', 'uid', 'sub_id', 'remark', 'plan'] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== '') $update[$field] = (string) $payload[$field];
        }
        foreach (['panel_id', 'inbound_id'] as $field) {
            if (array_key_exists($field, $payload)) $update[$field] = (int) $payload[$field];
        }
        if (!empty($payload['expire_at'])) {
            try { $update['expire_at'] = Carbon::parse($payload['expire_at']); } catch (Throwable $e) {}
        }
        $order->fill($update);
        $order->save();
        return $order;
    }

    public function renewBotOrder(User $user, int $orderId, array $payload): array
    {
        $order = Orders::botManaged()->where('id', $orderId)->where('user_id', $user->id)->first();
        if (!$order) return ['ok' => false, 'message' => 'bot_order_not_found'];
        if (!app(OrderLifecycleService::class)->canRenew($order)) {
            return ['ok' => false, 'message' => 'renewal_grace_period_ended'];
        }

        $days = max(1, (int) ($payload['days'] ?? 30));
        $gb = (int) ($payload['gb'] ?? $payload['total_gb'] ?? 0);
        $base = $order->expire_at && Carbon::parse($order->expire_at)->gt(now()) ? Carbon::parse($order->expire_at) : now();
        $newExpire = $base->copy()->addDays($days);
        $detail = is_array($order->detail) ? $order->detail : (json_decode((string) $order->detail, true) ?: []);
        $pgResponse = null;
        $configResponse = null;

        if (empty($payload['skip_pasarguard'])) {
            $panel = $order->panel_id ? Panels::find($order->panel_id) : null;
            if (!$panel) return ['ok' => false, 'message' => 'panel_not_found_for_order'];
            if (empty($order->uid)) return ['ok' => false, 'message' => 'pasarguard_user_id_not_found'];

            $pg = $this->pasarGuardForPanel($panel);
            if (!$pg || !$pg->checkConnection()) {
                return ['ok' => false, 'message' => 'pasarguard_login_failed', 'login' => $pg?->getLoginStatus()];
            }

            $updateParams = [
                'expire' => $newExpire->copy()->utc()->toISOString(),
                'status' => $payload['status'] ?? 'active',
            ];
            if ($gb > 0) {
                $updateParams['data_limit'] = $gb * 1024 * 1024 * 1024;
                $updateParams['data_limit_reset_strategy'] = $payload['data_limit_reset_strategy'] ?? 'no_reset';
            }
            $pgResponse = $pg->updateUserById($order->uid, $updateParams);
            if (!is_array($pgResponse) || ($pgResponse['status'] ?? true) === false) {
                return ['ok' => false, 'message' => 'pasarguard_renew_failed', 'pasarguard' => $pgResponse];
            }
            $configResponse = $pg->getUserConfig($order->uid, $payload['client_type'] ?? 'links');
            if (is_array($configResponse) && !empty($configResponse['status']) && !empty($configResponse['body'])) {
                $detail['links'] = preg_split('/\r\n|\r|\n/', trim((string) $configResponse['body'])) ?: [];
            }
        }

        $detail['renewed_from_wp'] = true;
        $detail['renewed_at'] = now()->toDateTimeString();
        $detail['last_renew_days'] = $days;
        $lifecycleDetail = is_array($detail['lifecycle'] ?? null) ? $detail['lifecycle'] : [];
        unset($lifecycleDetail['remote_disabled'], $lifecycleDetail['cancelled_at']);
        $detail['lifecycle'] = $lifecycleDetail;
        if ($gb > 0) $detail['last_renew_gb'] = $gb;
        if ($pgResponse !== null) $detail['last_pasarguard_response'] = $pgResponse;

        $order->expire_at = $newExpire;
        $order->status = Orders::STATUS_ACTIVE;
        $order->reminded = 0;
        $order->detail = $detail;
        $order->save();

        return ['ok' => true, 'order' => $this->formatOrder($order->fresh(), true), 'pasarguard' => $pgResponse, 'config' => $configResponse];
    }

    public function pasarGuardForPanel(Panels $panel): ?PasarGuard
    {
        return new PasarGuard([
            'id' => $panel->id,
            'url' => $panel->url,
            'username' => $panel->username,
            'password' => $panel->password,
            'verify_ssl' => false,
        ]);
    }

    /* ---------------------------------------------------------------------
     * Payments
     * ------------------------------------------------------------------ */

    public function findPayment(int $id): ?Payment
    {
        return $id > 0 ? Payment::find($id) : null;
    }

    public function approvePayment(int $paymentId, array $payload = []): array
    {
        $payment = Payment::find($paymentId);
        if (!$payment) return ['ok' => false, 'message' => 'payment_not_found'];
        if ((string) $payment->status === '1') return ['ok' => true, 'payment' => $this->formatPayment($payment, true), 'already_approved' => true];

        return DB::transaction(function () use ($payment, $payload) {
            $payment = Payment::where('id', $payment->id)->lockForUpdate()->first();
            if ((string) $payment->status === '1') return ['ok' => true, 'payment' => $this->formatPayment($payment, true), 'already_approved' => true];
            if ((string) $payment->status !== '0') return ['ok' => false, 'message' => 'payment_not_pending'];
            $detail = is_array($payment->detail) ? $payment->detail : (json_decode((string) $payment->detail, true) ?: []);
            $detail['loyalty_approved_at'] = $detail['loyalty_approved_at'] ?? now()->toIso8601String();
            $detail['approved_from_wp_api'] = true;
            $detail['approved_note'] = $payload['note'] ?? '';
            $payment->status = 1;
            $payment->admin_id = $payload['admin_id'] ?? $payment->admin_id;
            $payment->detail = $detail;
            $payment->save();

            if (!empty($payload['apply_wallet']) && (string) $payment->type === '4') {
                $user = User::find($payment->user_id);
                if ($user) {
                    $target = User::where('id', $user->id)->lockForUpdate()->first();
                    $target->balance = (int) $target->balance + (int) $payment->price;
                    $target->save();
                }
            }
            return ['ok' => true, 'payment' => $this->formatPayment($payment->fresh(), true)];
        });
    }

    public function rejectPayment(int $paymentId, array $payload = []): array
    {
        $payment = Payment::find($paymentId);
        if (!$payment) return ['ok' => false, 'message' => 'payment_not_found'];
        $detail = is_array($payment->detail) ? $payment->detail : (json_decode((string) $payment->detail, true) ?: []);
        $detail['rejected_from_wp_api'] = true;
        $detail['reject_note'] = $payload['note'] ?? '';
        $payment->status = -1;
        $payment->admin_id = $payload['admin_id'] ?? $payment->admin_id;
        $payment->detail = $detail;
        $payment->save();
        return ['ok' => true, 'payment' => $this->formatPayment($payment->fresh(), true)];
    }

    /* ---------------------------------------------------------------------
     * Lists and admin reports
     * ------------------------------------------------------------------ */

    public function usersList(int $page = 1, int $perPage = 20, string $search = ''): array
    {
        $q = User::query();
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('tel_id', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }
        return $this->paginateQuery($q->orderByDesc('id'), $page, $perPage, fn ($user) => $this->formatUser($user));
    }

    public function updateUserStatus(User $user, int $status, string $note = ''): User
    {
        $detail = is_array($user->tel_detail) ? $user->tel_detail : [];
        if ($note !== '') $detail['admin_status_note'] = $note;
        $user->status = $status;
        $user->tel_detail = $detail;
        $user->save();
        return $user->fresh();
    }

    public function ordersListForUser(User $user, int $page = 1, int $perPage = 20, string $search = ''): array
    {
        $q = Orders::botManaged()->where('user_id', $user->id);
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('remark', 'like', "%{$search}%")->orWhere('plan', 'like', "%{$search}%")->orWhere('id', 'like', "%{$search}%");
            });
        }
        return $this->paginateQuery($q->orderByDesc('id'), $page, $perPage, fn ($order) => $this->formatOrder($order));
    }

    public function ordersList(int $page = 1, int $perPage = 20, string $search = '', string $source = ''): array
    {
        $q = Orders::botManaged()->leftJoin('users as u', 'u.id', '=', 'orders.user_id')->leftJoin('panels as p', 'p.id', '=', 'orders.panel_id')
            ->select('orders.*', 'u.tel_id as user_tel_id', 'u.username as user_username', 'u.first_name as user_first_name', 'p.name as panel_name');
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('orders.id', 'like', "%{$search}%")
                    ->orWhere('orders.remark', 'like', "%{$search}%")
                    ->orWhere('orders.plan', 'like', "%{$search}%")
                    ->orWhere('u.tel_id', 'like', "%{$search}%")
                    ->orWhere('u.username', 'like', "%{$search}%");
            });
        }
        if ($source !== '') {
            if ($source === 'bot') $q->where(function ($w) { $w->whereNull('orders.system_type')->orWhere('orders.system_type', '')->orWhere('orders.system_type', '!=', 'wordpress'); });
            else $q->where('orders.system_type', $source);
        }
        return $this->paginateQuery($q->orderByDesc('orders.id'), $page, $perPage, function ($row) {
            $order = Orders::find($row->id);
            $data = $order ? $this->formatOrder($order) : [];
            $data['user_label'] = $row->user_username ? '@' . ltrim($row->user_username, '@') : ($row->user_first_name ?: $row->user_tel_id);
            $data['panel_name'] = $row->panel_name ?: ($data['panel_name'] ?? null);
            return $data;
        });
    }

    public function paymentsListForUser(User $user, int $page = 1, int $perPage = 20, string $search = ''): array
    {
        $q = Payment::query()->where('user_id', $user->id);
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('id', 'like', "%{$search}%")->orWhere('ref_id', 'like', "%{$search}%")->orWhere('method', 'like', "%{$search}%");
            });
        }
        return $this->paginateQuery($q->orderByDesc('id'), $page, $perPage, fn ($payment) => $this->formatPayment($payment));
    }

    public function paymentsList(int $page = 1, int $perPage = 20, string $search = '', $status = null): array
    {
        $q = Payment::query()->leftJoin('users as u', 'u.id', '=', 'payments.user_id')->select('payments.*', 'u.tel_id as user_tel_id', 'u.username as user_username', 'u.first_name as user_first_name');
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('payments.id', 'like', "%{$search}%")
                    ->orWhere('payments.ref_id', 'like', "%{$search}%")
                    ->orWhere('u.tel_id', 'like', "%{$search}%")
                    ->orWhere('u.username', 'like', "%{$search}%");
            });
        }
        if ($status !== null && $status !== '') $q->where('payments.status', $status);
        return $this->paginateQuery($q->orderByDesc('payments.id'), $page, $perPage, function ($row) {
            $payment = Payment::find($row->id);
            $data = $payment ? $this->formatPayment($payment) : [];
            $data['user_label'] = $row->user_username ? '@' . ltrim($row->user_username, '@') : ($row->user_first_name ?: $row->user_tel_id);
            return $data;
        });
    }

    public function adminStats(string $start, string $end, string $group = 'day'): array
    {
        try { $startDate = Carbon::parse($start)->startOfDay(); } catch (Throwable $e) { $startDate = now()->subDays(29)->startOfDay(); }
        try { $endDate = Carbon::parse($end)->endOfDay(); } catch (Throwable $e) { $endDate = now()->endOfDay(); }
        $group = $group === 'month' ? 'month' : 'day';
        $periodPayments = $group === 'month' ? "DATE_FORMAT(COALESCE(updated_at, created_at), '%Y-%m')" : 'DATE(COALESCE(updated_at, created_at))';
        $periodOrders = $group === 'month' ? "DATE_FORMAT(created_at, '%Y-%m')" : 'DATE(created_at)';
        $periodUsers = $periodOrders;
        $series = [];

        $payments = DB::table('payments')
            ->selectRaw("{$periodPayments} period_key, COALESCE(SUM(price),0) revenue, COUNT(*) payments")
            ->where('status', 1)
            ->where(function ($q) {
                $q->whereNull('method')
                    ->orWhereNotIn('method', ['wallet', 'admin_credit', 'admin_debit']);
            })
            ->whereDate(DB::raw('COALESCE(updated_at, created_at)'), '>=', $startDate->toDateString())
            ->whereDate(DB::raw('COALESCE(updated_at, created_at)'), '<=', $endDate->toDateString())
            ->groupBy('period_key')
            ->get();
        foreach ($payments as $row) {
            $series[(string) $row->period_key] = [
                'revenue' => (int) $row->revenue,
                'payments' => (int) $row->payments,
                'orders' => 0,
                'registrations' => 0,
            ];
        }

        $orders = DB::table('orders')
            ->selectRaw("{$periodOrders} period_key, COUNT(*) orders")
            ->whereDate('created_at', '>=', $startDate->toDateString())
            ->whereDate('created_at', '<=', $endDate->toDateString())
            ->groupBy('period_key')
            ->get();
        foreach ($orders as $row) {
            $key = (string) $row->period_key;
            if (!isset($series[$key])) $series[$key] = ['revenue' => 0, 'payments' => 0, 'orders' => 0, 'registrations' => 0];
            $series[$key]['orders'] = (int) $row->orders;
        }

        $users = DB::table('users')
            ->selectRaw("{$periodUsers} period_key, COUNT(*) registrations")
            ->whereDate('created_at', '>=', $startDate->toDateString())
            ->whereDate('created_at', '<=', $endDate->toDateString())
            ->groupBy('period_key')
            ->get();
        foreach ($users as $row) {
            $key = (string) $row->period_key;
            if (!isset($series[$key])) $series[$key] = ['revenue' => 0, 'payments' => 0, 'orders' => 0, 'registrations' => 0];
            $series[$key]['registrations'] = (int) $row->registrations;
        }
        ksort($series);
        return $series;
    }

    public function adminList(string $type, int $page = 1, string $search = '', int $perPage = 20): array
    {
        return match ($type) {
            'users' => $this->usersList($page, $perPage, $search),
            'payments', 'receipts' => $this->paymentsList($page, $perPage, $search),
            'panels', 'plans', 'services', 'countries', 'inbounds', 'carts', 'extra_bandwidths', 'links', 'site_orders', 'settings' => $this->catalogList($type, $page, $perPage, $search),
            default => $this->ordersList($page, $perPage, $search),
        };
    }

    public function fullUserPayload(User $user): array
    {
        return [
            'user' => $this->formatUser($user, true),
            'wallet' => $this->walletSummary($user),
            'orders' => $this->ordersListForUser($user, 1, 100),
            'payments' => $this->paymentsListForUser($user, 1, 100),
        ];
    }

    /* ---------------------------------------------------------------------
     * Catalog
     * ------------------------------------------------------------------ */

    public function catalogList(string $type, int $page = 1, int $perPage = 50, string $search = '', array $filters = []): array
    {
        [$query, $formatter] = $this->catalogQuery($type, $search, $filters);
        return $this->paginateQuery($query, $page, $perPage, $formatter);
    }

    public function catalogShow(string $type, int $id): ?array
    {
        [$query, $formatter] = $this->catalogQuery($type, '', []);
        if (method_exists($query, 'getModel')) {
            $item = $query->where($query->getModel()->getTable() . '.id', $id)->first();
        } else {
            $item = $query->where('id', $id)->first();
        }
        return $item ? $formatter($item) : null;
    }

    private function catalogQuery(string $type, string $search = '', array $filters = []): array
    {
        return match ($type) {
            'services' => [$this->searchQuery(Service::query(), $search, ['name', 'id'])->orderByDesc('id'), fn ($i) => ['id' => $i->id, 'name' => $i->name, 'status' => (string) $i->status, 'price_per_gb' => (int) ($i->price_per_gb ?? 0), 'created_at' => optional($i->created_at)->toDateTimeString()]],
            'plans' => [$this->searchQuery(Plans::query(), $search, ['name', 'type', 'id'])->orderBy('id'), fn ($i) => ['id' => $i->id, 'name' => $i->name, 'bandwidth' => $i->bandwidth, 'days' => $i->days, 'price' => (int) $i->price, 'discount' => (int) $i->discount, 'type' => $i->type, 'status' => (string) $i->status]],
            'countries' => [$this->searchQuery(Countries::query(), $search, ['name', 'type', 'id'])->orderByRaw("CASE WHEN status = '1' THEN 0 ELSE 1 END")->orderByDesc('id'), fn ($i) => ['id' => $i->id, 'name' => $i->name, 'type' => $i->type, 'status' => (string) $i->status]],
            'panels' => [$this->searchQuery(Panels::query(), $search, ['name', 'url', 'system_type', 'id'])->orderByDesc('id'), fn ($i) => $this->formatPanel($i)],
            'inbounds' => [$this->searchQuery(Inbounds::query(), $search, ['remark', 'port', 'id'])->orderByDesc('id'), fn ($i) => $this->formatInbound($i)],
            'extra_bandwidths' => [$this->searchQuery(ExtraBandwidth::query(), $search, ['name', 'type', 'id'])->orderByDesc('id'), fn ($i) => ['id' => $i->id, 'name' => $i->name, 'type' => $i->type, 'status' => (string) $i->status, 'discount' => (int) $i->discount]],
            'carts' => [$this->searchQuery(Carts::query(), $search, ['name', 'cart', 'sheba', 'id'])->orderByDesc('id'), fn ($i) => ['id' => $i->id, 'name' => $i->name, 'cart' => $i->cart, 'sheba' => $i->sheba, 'status' => (string) $i->status, 'is_default' => (int) $i->is_default]],
            'settings' => [$this->searchQuery(Setting::query(), $search, ['key', 'name', 'value'])->orderBy('id'), fn ($i) => ['id' => $i->id, 'key' => $i->key, 'name' => $i->name, 'value' => $i->value]],
            'links' => [$this->tableQuery('wp_sync_links', $search, ['phone', 'tel_id', 'site_user_id'])->orderByDesc('id'), fn ($i) => (array) $i],
            'site_orders' => [$this->tableQuery('wp_sync_site_orders', $search, ['phone', 'order_code', 'site_order_id', 'bot_order_id'])->orderByDesc('id'), fn ($i) => (array) $i],
            default => [Orders::query()->orderByDesc('id'), fn ($i) => $this->formatOrder($i)],
        };
    }

    private function searchQuery(Builder $query, string $search, array $fields): Builder
    {
        if ($search !== '') {
            $query->where(function ($w) use ($fields, $search) {
                foreach ($fields as $field) $w->orWhere($field, 'like', "%{$search}%");
            });
        }
        return $query;
    }

    private function tableQuery(string $table, string $search, array $fields)
    {
        $query = DB::table($table);
        if ($search !== '') {
            $query->where(function ($w) use ($fields, $search) {
                foreach ($fields as $field) $w->orWhere($field, 'like', "%{$search}%");
            });
        }
        return $query;
    }

    public function paginateQuery($query, int $page, int $perPage, callable $formatter): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage ?: 20));
        $total = (clone $query)->count();
        $items = $query->forPage($page, $perPage)->get()->map($formatter)->values();
        return [
            'items' => $items,
            'total' => (int) $total,
            'per_page' => $perPage,
            'page' => $page,
            'last_page' => (int) max(1, ceil($total / $perPage)),
        ];
    }
}
