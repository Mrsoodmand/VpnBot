<?php

namespace App\Services;

use App\Models\Orders;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** WordPress owns policy, overrides and calculation. This adapter never writes money. */
class CustomerLoyaltyService
{
    private array $profiles = [];
    public function enabled(): bool
    {
        return (bool) config('loyalty.enabled');
    }

    public function request(string $action, array $payload = []): array
    {
        $sync = app(WpSyncService::class);
        try {
            $response = Http::timeout(15)->acceptJson()->asJson()
                ->withHeaders(['X-IPSABET-API-KEY' => $sync->secret()])
                ->post($sync->wpBaseUrl().'/wp-json/ipsvp/v1/sync/customer-loyalty/'.$action, $payload);
            $data = $response->json();
            if (!$response->successful() || !is_array($data) || ($data['ok'] ?? false) !== true) {
                throw new RuntimeException('invalid_loyalty_response');
            }
            return $data;
        } catch (\Throwable $e) {
            throw new RuntimeException('استعلام یا ذخیره تخفیف سایت انجام نشد؛ دوباره تلاش کنید.', 0, $e);
        }
    }

    public function profile(User $user): array
    {
        if (isset($this->profiles[$user->id])) return $this->profiles[$user->id];
        $data = $this->request('profile', ['tel_id' => (string) $user->tel_id, 'events' => $this->events($user)]);
        $p = $data['profile'] ?? [];
        if (!is_array($p) || ($p['kind'] ?? '') !== 'customer' || !is_numeric($p['percent'] ?? null)
            || $p['percent'] < 0 || $p['percent'] > 100 || ($data['currency'] ?? '') !== 'IRT'
            || !in_array($p['source'] ?? null, ['manual', 'automatic'], true)
            || !is_int($p['stars'] ?? null) || $p['stars'] < 0 || $p['stars'] > 4
            || !is_int($p['total'] ?? null) || $p['total'] < 0
            || (isset($p['expires_at']) && !is_int($p['expires_at']))
            || (isset($p['next_amount']) && (!is_int($p['next_amount']) || $p['next_amount'] < 0))) {
            throw new RuntimeException('پاسخ تخفیف حساب معتبر نیست.');
        }
        return $this->profiles[$user->id] = $p;
    }

    public function quote(User $user, int $serviceId, int|float $amount): array
    {
        $quote = ['amount' => $amount, 'base_amount' => $amount, 'percent' => 0, 'source' => 'none', 'currency' => 'IRT'];
        if (!$this->enabled() || (int) $user->is_seller === 1
            || !in_array($serviceId, config('loyalty.shared_service_ids', []), true)) {
            return $quote;
        }
        $p = $this->profile($user);
        $base = max(0, (int) $amount);
        $bps = (int) round($p['percent'] * 100);
        return ['amount' => (int) ceil($base * (10000 - $bps) / 10000), 'base_amount' => $base,
            'percent' => $p['percent'], 'source' => $p['source'], 'currency' => 'IRT', 'quoted_at' => now()->toIso8601String()];
    }

    /** Only original bot events; site-origin entries are read from the site's receipts. */
    public function events(User $user): array
    {
        $rows = [];
        $seen = [];
        foreach (Payment::where('user_id', $user->id)->where('status', 1)
            ->whereIn('method', ['cart-be-cart', 'admin_credit', 'admin_debit'])->orderBy('id')->cursor() as $p) {
            $d = $p->detail ?? [];
            if (!empty($d['receipt_id']) || str_starts_with((string) ($d['source'] ?? ''), 'wordpress')
                || !empty($d['refunded_at']) || !empty($d['refund_applied']) || !empty($d['wallet_refunded']) || empty($d['loyalty_approved_at'])) {
                continue;
            }
            $type = match ((string) $p->type) {
                '1' => 'new', '2' => 'renewal', '3' => 'extra_volume', '4' => 'wallet',
                'admin_credit' => 'wallet_admin_credit', 'admin_debit' => 'wallet_admin_debit', default => null,
            };
            if (!$type || (int) $p->price <= 0) continue;
            if (!is_string($d['loyalty_approved_at']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $d['loyalty_approved_at'])) continue;
            try {
                $at = CarbonImmutable::parse($d['loyalty_approved_at'])->utc()->timestamp;
            } catch (\Throwable $e) {
                continue;
            }
            $key = $p->ref_id ? $p->method.':'.$p->ref_id : 'id:'.$p->id;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            // Keep 60 days: a recent correction can consume an older, now expired charge first.
            if ($at <= now()->subDays(60)->timestamp) continue;
            $rows[] = ['id' => (int) $p->id, 'type' => $type, 'amount' => (int) $p->price,
                'approved_at' => $at, 'key' => 'bot:'.$p->id];
        }
        return ['rows' => $rows, 'first_purchase' => Orders::where('user_id', $user->id)->whereIn('status', ['1','0','2','active','data_exhausted','suspended','inactive'])->exists(), 'currency' => 'IRT'];
    }

    public function profileText(User $user): string
    {
        if (!$this->enabled() || (int) $user->is_seller === 1) return '';
        try {
            $p = $this->profile($user);
            $source = $p['source'] === 'manual' ? 'دستی' : 'پلکانی';
            $text = "\n⭐ تعداد ستاره‌ها: {$p['stars']} | تخفیف فعال: {$p['percent']}٪ ({$source})";
            $text .= "\nپرداخت معتبر ۳۰ روز: ".number_format($p['total']).' تومان';
            if ($p['source'] !== 'manual' && isset($p['next_amount'])) $text .= "\nتا پله بعدی: ".number_format($p['next_amount']).' تومان';
            if (!empty($p['expires_at'])) $text .= "\nافت سطح فعلی: ".CarbonImmutable::createFromTimestamp($p['expires_at'])->setTimezone('Asia/Tehran')->format('Y/m/d H:i').' (تهران)';
            return $text;
        } catch (RuntimeException $e) {
            return "\nاطلاعات تخفیف موقتاً در دسترس نیست.";
        }
    }
}
