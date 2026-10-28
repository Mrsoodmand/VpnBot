<?php

namespace App\Services;

use App\Lib\PasarGuard;
use App\Models\Inbounds;
use App\Models\Panels;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PanelCatalogSyncService
{
    private function credentials(Panels $panel): array
    {
        if (! $panel->url || ! $panel->username || ! $panel->password) {
            throw new RuntimeException('آدرس، نام کاربری و رمز پنل کامل نیست.');
        }

        return ['id' => $panel->id, 'url' => $panel->url, 'username' => $panel->username, 'password' => $panel->password];
    }

    public function check(Panels $panel): void
    {
        $login = $this->credentials($panel);
        try {
            if ($panel->system_type === 'pasarguard') {
                Cache::forget('pasarguard-token-'.$panel->id);
                $client = new PasarGuard($login);
                $ok = $client->checkConnection() === true;
            } elseif ($panel->system_type === 'sanaie') {
                $detail = $panel->detail ?? [];
                unset($detail['Expires']);
                $panel->detail = $detail;
                $panel->save();
                $result = loginToSanaie($login);
                $ok = ! empty($result['session']);
            } else {
                throw new RuntimeException('سیستم پنل مشخص نیست.');
            }
        } catch (ConnectionException $e) {
            throw new RuntimeException('پاسخی از پنل دریافت نشد؛ دوباره تلاش کنید.', 0, $e);
        }
        if (! $ok) {
            throw new RuntimeException('اتصال برقرار نشد؛ آدرس و اطلاعات ورود پنل را بررسی کنید.');
        }
        // Never overwrite the all-country surcharge/status or other panel settings.
    }

    public function sync(Panels $panel): int
    {
        // Lock before fetching, so a delayed older snapshot cannot undo a newer import.
        $lock = Cache::lock('panel:catalog:sync:'.$panel->id, 300);
        if (! $lock->get()) {
            throw new RuntimeException('دریافت گروه‌های این پنل در حال انجام است؛ کمی بعد دوباره تلاش کنید.');
        }
        try {
            return $this->import($panel);
        } finally {
            $lock->release();
        }
    }

    private function groups(PasarGuard $client): array
    {
        $items = [];
        $seen = [];
        $total = null;
        $deadline = microtime(true) + 180;
        do {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('دریافت گروه‌ها بیش از حد طول کشید؛ اطلاعات قبلی حفظ شد.');
            }
            $response = $client->getGroups(['offset' => count($items), 'limit' => 100]);
            if (! is_array($response) || ($response['status'] ?? true) === false
                || ! is_array($response['groups'] ?? null) || ! array_is_list($response['groups'])) {
                throw new RuntimeException('دریافت گروه‌ها ناموفق بود؛ هیچ اتصالی تغییر نکرد.');
            }
            // PasarGuard 5.5 supplies total. Without it a partial page is not a deletion snapshot.
            $count = $response['total'] ?? null;
            if (! is_int($count) || $count < 0 || $count > 10000 || ($total !== null && $count !== $total)) {
                throw new RuntimeException('تعداد کل گروه‌ها معتبر یا ثابت نیست؛ اطلاعات قبلی حفظ شد. دوباره دریافت کنید.');
            }
            $total = $count;
            foreach ($response['groups'] as $item) {
                $id = is_array($item) ? filter_var($item['id'] ?? null, FILTER_VALIDATE_INT) : false;
                if (! $id || $id < 1 || isset($seen[$id]) || ! is_string($item['name'] ?? null)) {
                    throw new RuntimeException('ساختار یا صفحه‌بندی فهرست گروه‌ها معتبر نیست؛ اطلاعات قبلی حفظ شد.');
                }
                $seen[$id] = true;
                $items[] = $item;
            }
            if (count($items) > $total || (! $response['groups'] && count($items) !== $total)) {
                throw new RuntimeException('فهرست گروه‌ها کامل نیست؛ اطلاعات قبلی حفظ شد.');
            }
        } while (count($items) < $total);

        return $items;
    }

    private function import(Panels $panel): int
    {
        $login = $this->credentials($panel);
        try {
            if ($panel->system_type === 'pasarguard') {
                $client = new PasarGuard($login);
                $items = $this->groups($client);
            } elseif ($panel->system_type === 'sanaie') {
                $session = loginToSanaie($login);
                if (empty($session['session'])) {
                    throw new RuntimeException('ورود به پنل انجام نشد.');
                }
                $response = getInbounds(['session' => $session['session'], 'url' => $panel->url]);
                if (empty($response['status']) || ! is_array($response['inbounds'] ?? null)) {
                    throw new RuntimeException('دریافت اینباندها انجام نشد؛ هیچ اتصالی تغییر نکرد.');
                }
                $items = $response['inbounds'];
            } else {
                throw new RuntimeException('سیستم پنل مشخص نیست.');
            }
        } catch (ConnectionException $e) {
            throw new RuntimeException('پاسخی از پنل دریافت نشد؛ اطلاعات قبلی حفظ شد.', 0, $e);
        }
        $ids = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! filter_var($item['id'] ?? null, FILTER_VALIDATE_INT) || (int) $item['id'] < 1 || in_array((int) $item['id'], $ids, true)
                || ! is_string($item[$panel->system_type === 'pasarguard' ? 'name' : 'remark'] ?? null)) {
                throw new RuntimeException('ساختار فهرست اتصال‌ها معتبر نیست؛ اطلاعات قبلی حفظ شد.');
            }
            $ids[] = (int) $item['id'];
        }

        return DB::transaction(function () use ($panel, $items, $ids) {
            // Serialize imports for one panel; preserve local country assignments and sale switches.
            Panels::whereKey($panel->id)->lockForUpdate()->first();
            foreach ($items as $item) {
                $route = Inbounds::where('panel_id', $panel->id)->where('inbound_id', $item['id'])->first();
                if (! $route) {
                    $route = new Inbounds;
                    $route->panel_id = $panel->id;
                    $route->inbound_id = $item['id'];
                    $route->status = -1;
                }
                $route->remark = mb_substr($item[$panel->system_type === 'pasarguard' ? 'name' : 'remark'], 0, 255);
                if ($panel->system_type === 'sanaie') {
                    $route->port = $item['port'] ?? 0;
                    $route->setting = json_encode($item);
                    if (isset($item['enable']) && ! $item['enable']) {
                        $route->status = -1;
                    }
                }
                if ($panel->system_type === 'pasarguard') {
                    $route->setting = json_encode($item);
                    if (! empty($item['is_disabled']) || (array_key_exists('inbound_tags', $item) && empty($item['inbound_tags']))) {
                        $route->status = -1;
                    }
                }
                $route->save();
            }
            // Keep IDs and country assignments for old orders; exclude only from the live catalog.
            foreach (Inbounds::where('panel_id', $panel->id)->whereNotIn('inbound_id', $ids)->get() as $missing) {
                $setting = json_decode((string) $missing->setting, true) ?: [];
                $setting['_bot_catalog_missing'] = true;
                $missing->setting = json_encode($setting);
                $missing->status = -1;
                $missing->save();
            }

            return count($items);
        });
    }
}
