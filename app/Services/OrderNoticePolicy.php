<?php

namespace App\Services;

use App\Models\Orders;
use Carbon\Carbon;
use Throwable;

/** Pure calculation: no DB reads, writes or remote requests. */
class OrderNoticePolicy
{
    public function nextAt(Orders $order, array $settings, Carbon $now): ?Carbon
    {
        if ($order->isSiteManagedDedicated() || app(OrderLifecycleService::class)->shouldSkipProviderRefresh($order)
            || app(OrderLifecycleService::class)->isAwaitingConnection($order)
            || ! in_array((string) $order->status, ['active', 'data_exhausted', 'suspended', '1', '0', '2', 'created', 'limited', 'expired', 'on_hold'], true)) {
            return null;
        }
        $dates = [];
        if ($settings['renew'] && $order->expire_at && $order->expire_at->gt($now)) {
            $date = $order->expire_at->copy()->subHours($settings['hours'])->max($now);
            $date = $this->afterDeliveryState($order, 'renewal_24h', $this->renewalFingerprint($order), $date, $now);
            if ($date && $date->lt($order->expire_at)) {
                $dates[] = $date;
            }
        }
        if ($settings['volume'] && $this->volumeIsDue($order, $settings, $now)) {
            $date = $this->afterDeliveryState($order, 'low_volume', $this->volumeFingerprint($order), $now->copy(), $now);
            if ($date) {
                $dates[] = $date;
            }
        }
        usort($dates, fn ($a, $b) => $a->timestamp <=> $b->timestamp);

        return $dates[0] ?? null;
    }

    public function volumeIsDue(Orders $order, array $settings, Carbon $now): bool
    {
        if (app(OrderLifecycleService::class)->isAwaitingConnection($order)
            || ! in_array((string) $order->status, ['active', 'data_exhausted', '1', '0', 'created', 'limited'], true)
            || ($order->expire_at && $order->expire_at->lte($now))) {
            return false;
        }
        $detail = is_array($order->detail['lifecycle'] ?? null) ? $order->detail['lifecycle'] : [];
        $checked = $detail['usage_checked_at'] ?? $detail['last_checked_at'] ?? null;
        if (! is_numeric($detail['total_gb'] ?? null) || $detail['total_gb'] <= 0
            || ! is_numeric($detail['left_gb'] ?? null) || $detail['left_gb'] > $settings['gb'] || ! $checked) {
            return false;
        }
        try {
            return Carbon::parse($checked)->gte($now->copy()->subMinutes($settings['interval'] + 5));
        } catch (Throwable) {
            return false;
        }
    }

    private function afterDeliveryState(Orders $order, string $type, string $fingerprint, Carbon $date, Carbon $now): ?Carbon
    {
        $state = $order->detail['lifecycle']['notifications'][$type] ?? [];
        $state = is_array($state) ? $state : [];
        if (($state['state'] ?? '') === 'sending') {
            try {
                $date = $date->max(Carbon::parse($state['started_at'] ?? null)->addMinutes(5));
            } catch (Throwable) {
                $date = $date->max($now->copy()->addMinutes(5));
            }
        }
        if (($state['fingerprint'] ?? null) !== $fingerprint) {
            return $date;
        }
        if (($state['state'] ?? '') === 'sent') {
            return null;
        }
        if (isset($state['retry_at'])) {
            $date = $date->max(Carbon::createFromTimestamp($state['retry_at'], config('app.timezone')));
        }

        return $date;
    }

    public function renewalFingerprint(Orders $order): string
    {
        return 'expire:'.$order->expire_at->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public function volumeFingerprint(Orders $order): string
    {
        $detail = is_array($order->detail['lifecycle'] ?? null) ? $order->detail['lifecycle'] : [];
        $expire = $order->expire_at ? $order->expire_at->copy()->utc()->format('Y-m-d\TH:i:s\Z') : 'no-expiry';

        return $expire.'|total:'.number_format((float) ($detail['total_gb'] ?? 0), 3, '.', '')
            .(! empty($detail['usage_cycle']) ? '|cycle:'.$detail['usage_cycle'] : '');
    }
}
