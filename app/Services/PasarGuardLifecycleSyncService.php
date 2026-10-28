<?php

namespace App\Services;

use App\Lib\PasarGuard;
use App\Models\Orders;
use App\Models\Panels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Bounded, resumable scans of only the bot's users, with no config downloads. */
class PasarGuardLifecycleSyncService
{
    public const BATCH_SIZE = 200;

    public const MAX_REQUESTS = 5;

    public function synchronize(OrderLifecycleService $lifecycle): array
    {
        $schedule = app(OrderCheckSchedule::class);
        $schedule->settings(OrderNotificationSettings::load());
        $result = ['checked' => 0, 'failed' => 0, 'requests' => 0, 'db_checked' => 0];
        $deadline = microtime(true) + 40;
        $panels = Panels::where('system_type', 'pasarguard')->get()
            ->sortBy(fn ($panel) => Cache::get($this->key($panel->id), [])['attempted_at'] ?? 0);

        // The due index selects a bounded set even when most orders are inactive.
        for ($round = 0; $round < self::MAX_REQUESTS; $round++) {
            $progress = false;
            foreach ($panels as $panel) {
                if ($result['requests'] >= self::MAX_REQUESTS || $result['db_checked'] >= 1000 || microtime(true) >= $deadline) {
                    break 2;
                }
                $key = $this->key($panel->id);
                $state = Cache::get($key, []);
                if (($state['retry_at'] ?? 0) > now()->timestamp) {
                    continue;
                }
                $orderIds = $schedule->due('provider_at', min(self::BATCH_SIZE, 1000 - $result['db_checked']), $panel->id);
                if (! $orderIds) {
                    continue;
                }
                $progress = true;
                $result['db_checked'] += count($orderIds);
                $orders = Orders::whereIn('id', $orderIds)->get()->keyBy('id');
                $eligible = collect();
                foreach ($orderIds as $orderId) {
                    $order = $orders->get($orderId);
                    if (! $order) {
                        $schedule->remove($orderId);

                        continue;
                    }
                    if ((int) $order->panel_id !== (int) $panel->id || $order->isSiteManagedDedicated()
                        || $lifecycle->shouldSkipProviderRefresh($order)
                        || (! $lifecycle->isAwaitingConnection($order) && $order->expire_at && $order->expire_at->copy()->addDays(OrderLifecycleService::RENEWAL_GRACE_DAYS)->lte(now()))) {
                        $schedule->refresh($order);

                        continue;
                    }
                    if (! ctype_digit((string) $order->uid) || (int) $order->uid < 1) {
                        $result['failed']++;
                        $schedule->defer($orderId, 'provider_at', now()->addHour());

                        continue;
                    }
                    $eligible->push($order);
                }
                $ids = $eligible->pluck('uid')->map(fn ($id) => (string) $id)->unique()->values()->all();
                if (! $ids) {
                    continue;
                }
                $state['attempted_at'] = now()->timestamp;
                try {
                    $result['requests']++;
                    $response = $this->fetch($panel, $ids);
                    if (! is_array($response) || ($response['status'] ?? null) === false || ! is_array($response['users'] ?? null)
                        || ! array_is_list($response['users']) || ! is_numeric($response['total'] ?? null)
                        || (int) $response['total'] !== count($response['users']) || count($response['users']) > count($ids)) {
                        throw new RuntimeException('Invalid or incomplete user batch');
                    }
                    $byId = [];
                    foreach ($response['users'] as $user) {
                        if (! is_array($user) || ! in_array((string) ($user['id'] ?? ''), $ids, true)
                            || isset($byId[(string) $user['id']]) || ! isset($user['status'])
                            || ! array_key_exists('data_limit', $user) || ! is_numeric($user['used_traffic'] ?? null)) {
                            throw new RuntimeException('Unexpected user in batch response');
                        }
                        $byId[(string) $user['id']] = $user;
                    }
                    foreach ($eligible as $order) {
                        $user = $byId[(string) $order->uid] ?? null;
                        if ($user === null) {
                            $result['failed']++;
                            $schedule->defer($order->id, 'provider_at', now()->addHour());

                            continue;
                        }
                        if (! $lifecycle->applyPasarguardSnapshot($order, $user)) {
                            $schedule->defer($order->id, 'provider_at', now()->addMinute());
                        }
                        $result['checked']++;
                    }
                    unset($state['retry_at'], $state['failures']);
                } catch (Throwable $exception) {
                    $state['failures'] = min(6, ($state['failures'] ?? 0) + 1);
                    $state['retry_at'] = now()->addMinutes(min(60, 2 ** $state['failures']))->timestamp;
                    $result['failed'] += $eligible->count();
                    Log::warning('PasarGuard lifecycle batch failed', ['panel_id' => $panel->id, 'error' => $exception->getMessage()]);
                }
                Cache::put($key, $state, now()->addDays(7));
            }
            if (! $progress) {
                break;
            }
        }

        return $result;
    }

    private function key(int $panelId): string
    {
        return 'orders:lifecycle:due-panel:'.$panelId;
    }

    protected function fetch(Panels $panel, array $ids): mixed
    {
        return (new PasarGuard(['url' => $panel->url, 'username' => $panel->username, 'password' => $panel->password, 'id' => $panel->id]))->getUsersByIds($ids);
    }
}
