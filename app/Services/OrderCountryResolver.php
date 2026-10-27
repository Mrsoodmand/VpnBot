<?php

namespace App\Services;

use App\Models\Countries;
use App\Models\Inbounds;
use App\Models\Orders;
use App\Models\Panels;
use App\Models\PreOrder;
use Illuminate\Support\Collection;

class OrderCountryResolver
{
    private const UNKNOWN_COUNTRY = '🌍 نامشخص';

    private const ALL_COUNTRIES = '🌍 همه کشورها';

    /**
     * Resolve the displayable country for a page of orders without N+1 queries.
     *
     * @param  iterable<Orders>  $orders
     * @return array<int, string>
     */
    public function resolve(iterable $orders): array
    {
        $orders = collect($orders);
        $metadata = $orders->mapWithKeys(function (Orders $order) {
            $detail = $this->asArray($order->detail);

            return [(int) $order->id => [
                'current_name' => $this->cleanName($detail['current_country']['label'] ?? null),
                'direct_name' => $this->countryNameFrom($detail),
                'country_id' => $this->positiveIntFrom($detail, ['country-id', 'country_id', 'countryId']),
                'pre_order_id' => $this->positiveIntFrom($detail, ['preOrderId', 'pre_order_id', 'pre-order-id']),
                'inbound_id' => $this->positiveInt($order->inbound_id),
                'panel_id' => $this->positiveInt($order->panel_id),
                'is_all_countries' => $this->isAllCountries($detail) || $this->isLegacyAllCountriesOrder($order),
            ]];
        });

        $preOrders = PreOrder::whereIn(
            'id',
            $metadata->pluck('pre_order_id')->filter()->unique()->values()
        )->get()->keyBy('id');

        foreach ($metadata as $orderId => $item) {
            $preOrder = $preOrders->get($item['pre_order_id']);
            $preOrderData = $preOrder ? $this->asArray($preOrder->data) : [];

            $metadata[$orderId] = array_merge($item, [
                'pre_order_name' => $this->countryNameFrom($preOrderData),
                'pre_order_country_id' => $this->positiveIntFrom($preOrderData, ['country-id', 'country_id', 'countryId']),
                'is_all_countries' => $item['is_all_countries'] || $this->isAllCountries($preOrderData),
            ]);
        }

        $inbounds = Inbounds::whereIn(
            'id',
            $metadata->pluck('inbound_id')->filter()->unique()->values()
        )->get(['id', 'country_id'])->keyBy('id');

        // Some legacy orders stored the remote inbound_id instead of the local row id.
        $legacyInbounds = Inbounds::whereIn(
            'inbound_id',
            $metadata->pluck('inbound_id')->filter()->unique()->values()
        )->whereIn(
            'panel_id',
            $metadata->pluck('panel_id')->filter()->unique()->values()
        )->get(['id', 'inbound_id', 'panel_id', 'country_id'])
            ->keyBy(fn (Inbounds $inbound) => "{$inbound->panel_id}:{$inbound->inbound_id}");

        $panels = Panels::whereIn(
            'id',
            $metadata->pluck('panel_id')->filter()->unique()->values()
        )->get(['id', 'country_id'])->keyBy('id');

        $countryIds = $metadata->flatMap(function (array $item) use ($inbounds, $legacyInbounds, $panels) {
            return [
                $item['country_id'],
                $item['pre_order_country_id'],
                $this->inboundCountryId($item, $inbounds, $legacyInbounds),
                $panels->get($item['panel_id'])?->country_id,
            ];
        })->filter(fn ($id) => $this->positiveInt($id) !== null)->unique()->values();

        $countryNames = Countries::whereIn('id', $countryIds)
            ->pluck('name', 'id');

        return $metadata->map(function (array $item) use ($inbounds, $legacyInbounds, $panels, $countryNames) {
            if ($item['current_name']) {
                return $item['current_name'];
            }
            if ($item['is_all_countries']) {
                return self::ALL_COUNTRIES;
            }

            if ($item['direct_name']) {
                return $item['direct_name'];
            }

            if ($item['pre_order_name']) {
                return $item['pre_order_name'];
            }

            $candidateIds = [
                $item['country_id'],
                $item['pre_order_country_id'],
                $this->inboundCountryId($item, $inbounds, $legacyInbounds),
                $panels->get($item['panel_id'])?->country_id,
            ];

            foreach ($candidateIds as $countryId) {
                $name = $this->cleanName($countryNames->get($countryId));
                if ($name) {
                    return $name;
                }
            }

            return self::UNKNOWN_COUNTRY;
        })->all();
    }

    private function inboundCountryId(array $item, Collection $inbounds, Collection $legacyInbounds): mixed
    {
        $localCountryId = $inbounds->get($item['inbound_id'])?->country_id;
        if ($localCountryId) {
            return $localCountryId;
        }

        return $legacyInbounds->get("{$item['panel_id']}:{$item['inbound_id']}")?->country_id;
    }

    private function countryNameFrom(array $data): ?string
    {
        foreach (['country', 'country_name', 'country-name', 'countryName'] as $key) {
            $name = $this->cleanName($data[$key] ?? null);
            if ($name) {
                $flag = $this->cleanName($data['country_flag'] ?? $data['country-flag'] ?? $data['countryFlag'] ?? null);

                return $flag && !str_contains($name, $flag) ? "{$flag} {$name}" : $name;
            }
        }

        $raw = $this->asArray($data['raw'] ?? []);
        if ($raw !== []) {
            return $this->countryNameFrom($raw);
        }

        return null;
    }

    private function isAllCountries(array $data): bool
    {
        foreach (['pasarguard-id', 'pasarguard_id', 'pasarguardId'] as $key) {
            if ($this->positiveInt($data[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    private function isLegacyAllCountriesOrder(Orders $order): bool
    {
        return strtolower((string) $order->system_type) === 'pasarguard'
            && (int) $order->inbound_id === 0;
    }

    private function positiveIntFrom(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $this->positiveInt($data[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (!is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function cleanName(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim($value));
        if (!is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, 48);
    }

    private function asArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
