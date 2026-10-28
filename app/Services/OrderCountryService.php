<?php

namespace App\Services;

use App\Lib\PasarGuard;
use App\Models\Countries;
use App\Models\Inbounds;
use App\Models\Orders;
use App\Models\Panels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Current provider location, separate from the original purchase receipt. */
class OrderCountryService
{
    private array $catalogs = [];

    public function groups(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 200) {
            return null;
        }
        $ids = [];
        foreach ($value as $id) {
            if ((! is_int($id) && ! is_string($id)) || ! ctype_digit((string) $id) || (int) $id < 1) {
                return null;
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    private function catalog(int $panelId): array
    {
        if (isset($this->catalogs[$panelId])) {
            return $this->catalogs[$panelId];
        }
        $routes = DB::table('inbounds as i')->leftJoin('countries as c', 'c.id', '=', 'i.country_id')
            ->where('i.panel_id', $panelId)->get(['i.id', 'i.inbound_id', 'i.country_id', 'i.status', 'c.name']);

        return $this->catalogs[$panelId] = ['routes' => $routes, 'panel' => Panels::find($panelId)];
    }

    public function location(int $panelId, array $ids): ?array
    {
        $ids = $this->groups($ids);
        if (! $ids) {
            return null;
        }
        ['routes' => $routes, 'panel' => $panel] = $this->catalog($panelId);
        if (! $panel || $panel->system_type !== 'pasarguard') {
            return null;
        }
        $matches = $routes->whereIn('inbound_id', $ids);
        if ($matches->pluck('inbound_id')->unique()->count() !== count($ids)) {
            return null;
        }
        // Ambiguous catalog assignments must never silently choose a country.
        foreach ($matches->groupBy('inbound_id') as $group) {
            if ($group->pluck('country_id')->unique()->count() !== 1) {
                return null;
            }
        }
        $countries = $matches->pluck('country_id')->unique()->filter()->values();
        $activeGroups = $this->groups($routes->where('status', 1)->pluck('inbound_id')->all());
        $all = $countries->count() > 1 && ($panel->detail['status'] ?? 0) == 1 && $activeGroups === $ids;
        if ($matches->contains(fn ($r) => ! $r->country_id || ! $r->name) || (! $all && $countries->count() !== 1)) {
            return null;
        }
        $label = $all ? '🌍 همه کشورها' : (string) $matches->first()->name;
        preg_match('/[\x{1F1E6}-\x{1F1FF}]{2}/u', $label, $flag);

        return ['country_id' => $all ? 0 : (int) $countries->first(), 'label' => $label,
            'name' => $all ? 'همه کشورها' : trim(str_replace($flag[0] ?? '', '', $label)), 'flag' => $all ? '🌍' : ($flag[0] ?? ''),
            'is_all_countries' => $all, 'group_ids' => $ids,
            'inbound_id' => $all ? 0 : (int) $matches->first()->id];
    }

    /** Use an already-fetched provider response: no additional network request. */
    public function observe(Orders $order, array $remote, array $actor = []): bool
    {
        $groups = $this->groups($remote['group_ids'] ?? null);
        if (! $groups || ! $order->panel_id || $order->isSiteManagedDedicated()) {
            return false;
        }
        $location = $this->location((int) $order->panel_id, $groups);
        if (! $location) {
            return false;
        }
        $detail = is_array($order->detail) ? $order->detail : [];
        $previous = $detail['current_country'] ?? null;
        if (is_array($previous) && ($previous['group_ids'] ?? null) === $groups && ($previous['label'] ?? null) === $location['label']) {
            return false;
        }
        $oldLabel = is_array($previous) ? ($previous['label'] ?? '') : ($detail['country'] ?? $detail['raw']['country_name'] ?? '');
        if (! $oldLabel) {
            $oldLabel = $this->catalog((int) $order->panel_id)['routes']->firstWhere('id', $order->inbound_id)?->name ?? '';
        }
        $detail['current_country'] = $location + ['at' => now()->toIso8601String(), 'source' => $actor ? 'admin' : 'pasarguard'];
        $history = is_array($detail['country_history'] ?? null) ? $detail['country_history'] : [];
        $history[] = ['from' => $oldLabel, 'to' => $location['label'], 'from_groups' => $previous['group_ids'] ?? [],
            'to_groups' => $groups, 'at' => now()->toIso8601String(), 'actor' => $actor ?: ['source' => 'pasarguard']];
        $detail['country_history'] = array_slice($history, -30);
        // Clear only obsolete connection caches; the subscription identity stays intact.
        if ($previous || $oldLabel !== $location['label']) {
            unset($detail['links'], $detail['configs'], $detail['config_links']);
            if (preg_match('~^(vless|vmess|trojan|ss)://~i', (string) ($detail['code'] ?? ''))) {
                unset($detail['code']);
            }
        }
        $order->detail = $detail;

        return true;
    }

    public function options(Orders $order): array
    {
        $panel = $this->panel($order);
        $routes = Inbounds::where('panel_id', $panel->id)->where('status', 1)->get();
        $countries = Countries::whereIn('id', $routes->pluck('country_id'))->where('status', 1)->get();
        $options = [];
        foreach ($countries as $country) {
            $groups = $this->groups($routes->where('country_id', $country->id)->pluck('inbound_id')->all());
            if ($groups && ($this->location((int) $panel->id, $groups)['country_id'] ?? null) === (int) $country->id) {
                $options[] = ['country_id' => (int) $country->id, 'label' => $country->name, 'group_ids' => $groups];
            }
        }
        if (($panel->detail['status'] ?? 0) == 1 && $routes->pluck('country_id')->filter()->unique()->count() > 1) {
            $groups = $this->groups($routes->pluck('inbound_id')->all());
            if ($groups && ($this->location((int) $panel->id, $groups)['is_all_countries'] ?? false)) {
                $options[] = ['country_id' => 0, 'label' => '🌍 همه کشورها', 'group_ids' => $groups];
            }
        }

        return $options;
    }

    private function panel(Orders $order): Panels
    {
        $panel = Panels::find($order->panel_id);
        if (! $panel || $panel->system_type !== 'pasarguard' || ! $order->uid || $order->isSiteManagedDedicated()) {
            throw new RuntimeException('تغییر کشور فقط برای سرویس متصل به پاسارگارد در دسترس است.');
        }
        if (app(OrderLifecycleService::class)->shouldSkipProviderRefresh($order)) {
            throw new RuntimeException('این سرویس لغو یا غیرفعال شده است.');
        }

        return $panel;
    }

    protected function client(Panels $panel): PasarGuard
    {
        return new PasarGuard(['id' => $panel->id, 'url' => $panel->url, 'username' => $panel->username, 'password' => $panel->password]);
    }

    private function read(PasarGuard $client, Orders $order): array
    {
        $remote = $client->getUserById($order->uid);
        if (! is_array($remote) || ($remote['status'] ?? null) === false
            || (string) ($remote['id'] ?? '') !== (string) $order->uid || $this->groups($remote['group_ids'] ?? null) === null) {
            throw new RuntimeException('گروه فعلی سرویس از پاسارگارد دریافت نشد؛ دوباره تلاش کنید.');
        }

        return $remote;
    }

    public function preview(Orders $order, int $countryId, array $actor): array
    {
        $option = collect($this->options($order))->firstWhere('country_id', $countryId);
        if (! $option) {
            throw new RuntimeException('کشور انتخاب‌شده روی پنل این سرویس فعال نیست.');
        }
        $remote = $this->read($this->client($this->panel($order)), $order);
        $from = $this->location((int) $order->panel_id, $this->groups($remote['group_ids']));
        $token = bin2hex(random_bytes(12));
        $intent = ['order_id' => $order->id, 'panel_id' => $order->panel_id, 'uid' => (string) $order->uid,
            'expected' => $this->groups($remote['group_ids']), 'target' => $option, 'actor' => $actor];
        Cache::put('order:country:confirm:'.$token, $intent, now()->addMinutes(10));

        return ['token' => $token, 'from' => $from['label'] ?? ($remote['group_ids'] === [] ? 'بدون گروه در پاسارگارد' : 'گروه فعلی بدون کشور مشخص'), 'to' => $option['label']];
    }

    public function confirm(string $token, array $actor): Orders
    {
        $intent = Cache::get('order:country:confirm:'.$token);
        if (! is_array($intent) || $intent['actor'] !== $actor) {
            throw new RuntimeException('تأیید منقضی شده؛ کشور را دوباره انتخاب کنید.');
        }
        $lock = Cache::lock('order:country:'.$intent['panel_id'].':'.$intent['uid'], 180);
        if (! $lock->get()) {
            throw new RuntimeException('تغییر این سرویس در حال انجام است؛ کمی بعد دوباره تلاش کنید.');
        }
        try {
            $order = Orders::findOrFail($intent['order_id']);
            if ((int) $order->panel_id !== (int) $intent['panel_id'] || (string) $order->uid !== $intent['uid']) {
                throw new RuntimeException('اتصال سفارش تغییر کرده؛ صفحه را دوباره باز کنید.');
            }
            $option = collect($this->options($order))->firstWhere('country_id', $intent['target']['country_id']);
            if (! $option || $option['group_ids'] !== $intent['target']['group_ids']) {
                throw new RuntimeException('تنظیمات کشور تغییر کرده؛ دوباره انتخاب کنید.');
            }
            $client = $this->client($this->panel($order));
            $remote = $this->read($client, $order);
            $groups = $this->groups($remote['group_ids']);
            if ($groups !== $option['group_ids']) {
                if ($groups !== $intent['expected']) {
                    throw new RuntimeException('کشور سرویس هم‌زمان تغییر کرده؛ صفحه را دوباره باز کنید.');
                }
                $response = $client->updateUserById($order->uid, ['group_ids' => $option['group_ids']]);
                // A timeout is ambiguous. Read back before reporting success or retrying a write.
                $remote = is_array($response) && (string) ($response['id'] ?? '') === (string) $order->uid
                    && $this->groups($response['group_ids'] ?? null) === $option['group_ids'] ? $response : $this->read($client, $order);
                if ($this->groups($remote['group_ids'] ?? null) !== $option['group_ids']) {
                    throw new RuntimeException('تغییر کشور در پنل تأیید نشد؛ اطلاعات سفارش تغییر نکرد.');
                }
            }
            $saved = DB::transaction(function () use ($order, $remote, $actor) {
                $fresh = Orders::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ((int) $fresh->panel_id !== (int) $order->panel_id || (string) $fresh->uid !== (string) $order->uid) {
                    throw new RuntimeException('اتصال سفارش تغییر کرده؛ دوباره بررسی کنید.');
                }
                $this->observe($fresh, $remote, $actor);
                $fresh->save();

                return $fresh;
            });
            if ((int) ($saved->detail['site_order_id'] ?? 0) > 0) {
                $synced = app(WpSyncService::class)->pushOrderCountry($saved);
                $saved = DB::transaction(function () use ($saved, $synced) {
                    $fresh = Orders::whereKey($saved->id)->lockForUpdate()->firstOrFail();
                    $detail = $fresh->detail;
                    $detail['country_site_sync'] = ['ok' => $synced, 'at' => now()->toIso8601String()];
                    $fresh->detail = $detail;
                    $fresh->save();

                    return $fresh;
                });
            }

            // Retaining the intent briefly makes duplicate confirmation idempotent.
            return $saved;
        } finally {
            $lock->release();
        }
    }
}
