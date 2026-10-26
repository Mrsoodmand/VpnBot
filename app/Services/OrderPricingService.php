<?php

namespace App\Services;

use App\Models\Orders;
use App\Models\Panels;
use App\Models\PreOrder;

class OrderPricingService
{
    public function isAllCountries(Orders $order, Panels $panel): bool
    {
        $detail = is_array($order->detail) ? $order->detail : [];
        $raw = is_array($detail['raw'] ?? null) ? $detail['raw'] : [];
        // Site imports can carry a group/inbound ID even for all-country orders.
        foreach ([$detail, $raw] as $data) {
            if (array_key_exists('is_all_countries', $data)) {
                return filter_var($data['is_all_countries'], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $preOrderId = $detail['preOrderId'] ?? $detail['pre_order_id'] ?? $detail['pre-order-id'] ?? null;
        $selection = $preOrderId
            ? (PreOrder::where('user_id', $order->user_id)->find($preOrderId)?->data ?? []) : [];
        foreach ([$selection, $detail, $raw] as $data) {
            foreach (['pasarguard-id', 'pasarguard_id', 'pasarguardId'] as $key) {
                if ((int) ($data[$key] ?? 0) > 0) return true;
            }
        }
        foreach ([$selection, $detail, $raw] as $data) {
            // A known single country takes precedence over a missing legacy inbound.
            if ((int) ($data['country-id'] ?? $data['country_id'] ?? 0) > 0) return false;
            $name = preg_replace('/[\s\x{200c}]+/u', '', (string) ($data['country'] ?? $data['country_name'] ?? ''));
            if (str_contains($name, 'همهکشورها')) return true;
        }
        return $panel->system_type === 'pasarguard' && (int) $order->inbound_id === 0;
    }

    public function percent(?Panels $allCountriesPanel): float
    {
        $value = $allCountriesPanel?->detail['percent'] ?? 0;
        return is_numeric($value) && is_finite((float) $value) ? max(0, (float) $value) : 0;
    }

    /** Apply the configured surcharge before the product and customer discounts. */
    public function amount(int|float $base, int|float $discount = 0, ?Panels $allCountriesPanel = null): int
    {
        $amount = max(0, $base) * (100 + $this->percent($allCountriesPanel)) / 100;
        return (int) ceil($amount * (100 - min(100, max(0, $discount))) / 100);
    }
}
