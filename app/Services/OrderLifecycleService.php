<?php

namespace App\Services;

use App\Lib\PasarGuard;
use App\Models\Orders;
use App\Models\Panels;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderLifecycleService
{
    public const RENEWAL_GRACE_DAYS = 7;

    private const INACTIVE_VALUES = [
        Orders::STATUS_INACTIVE,
        'cancelled',
        'disabled',
        'deleted',
        '-1',
        '-2',
    ];

    /**
     * Time-based transitions are local and cheap, so run them before every list.
     */
    public function reconcileTimeStatuses(?int $userId = null, ?Carbon $now = null): void
    {
        $now ??= now();
        $graceEndedAt = $now->copy()->subDays(self::RENEWAL_GRACE_DAYS);

        $expiredQuery = Orders::query()
            ->when($userId !== null, fn (Builder $query) => $query->where('user_id', $userId))
            ->whereNotNull('expire_at')
            ->whereNotIn('status', self::INACTIVE_VALUES);

        (clone $expiredQuery)
            ->where('expire_at', '<=', $graceEndedAt)
            ->update(['status' => Orders::STATUS_INACTIVE]);

        (clone $expiredQuery)
            ->where('expire_at', '>', $graceEndedAt)
            ->where('expire_at', '<=', $now)
            ->update(['status' => Orders::STATUS_SUSPENDED]);
    }

    public function refreshTimeStatus(Orders $order, ?Carbon $now = null): Orders
    {
        $now ??= now();
        $expireAt = $order->expire_at ? Carbon::parse($order->expire_at) : null;

        if (!$expireAt || $this->shouldSkipProviderRefresh($order)) {
            return $order;
        }

        $newStatus = null;
        if ($expireAt->copy()->addDays(self::RENEWAL_GRACE_DAYS)->lte($now)) {
            $newStatus = Orders::STATUS_INACTIVE;
        } elseif ($expireAt->lte($now)) {
            $newStatus = Orders::STATUS_SUSPENDED;
        } elseif ($this->normalizeStatus($order->status) === Orders::STATUS_SUSPENDED) {
            $newStatus = Orders::STATUS_ACTIVE;
        }

        if ($newStatus !== null && $newStatus !== (string) $order->status) {
            $order->status = $newStatus;
            $order->save();
        }

        return $order;
    }

    public function canRenew(Orders $order, ?Carbon $now = null): bool
    {
        if ($order->isSiteManagedDedicated()) return false;

        $this->refreshTimeStatus($order, $now);

        return $this->normalizeStatus($order->status) !== Orders::STATUS_INACTIVE;
    }

    public function canBuyExtra(Orders $order, ?Carbon $now = null): bool
    {
        if ($order->isSiteManagedDedicated()) return false;

        $this->refreshTimeStatus($order, $now);

        return in_array($this->normalizeStatus($order->status), [
            Orders::STATUS_ACTIVE,
            Orders::STATUS_DATA_EXHAUSTED,
        ], true);
    }

    /**
     * Persist bandwidth/provider information obtained while opening an order.
     */
    public function applyConfigDetail(Orders $order, array $configDetail): Orders
    {
        if (!($configDetail['status'] ?? false) || !($configDetail['data']['remote_available'] ?? false)) {
            return $order;
        }

        if ($this->shouldSkipProviderRefresh($order)) {
            return $order;
        }

        $data = $configDetail['data'];
        if (!empty($data['expire'])) {
            try {
                $order->expire_at = Carbon::parse($data['expire'])->timezone(config('app.timezone'));
            } catch (Throwable) {
                // Keep the valid local expiry when the provider value is invalid.
            }
        }

        $detail = is_array($order->detail) ? $order->detail : [];
        $lifecycleDetail = is_array($detail['lifecycle'] ?? null) ? $detail['lifecycle'] : [];
        $detail['lifecycle'] = array_merge($lifecycleDetail, [
            'last_checked_at' => now()->toDateTimeString(),
            'total_gb' => $data['totalGb'] ?? null,
            'used_gb' => $data['totalUsed'] ?? null,
            'left_gb' => $data['left'] ?? null,
            'provider_status' => $data['provider_status'] ?? null,
        ]);

        $order->status = $this->resolveProviderOrderStatus(
            $order,
            $data['provider_status'] ?? null,
            is_numeric($data['left'] ?? null) && (float) $data['left'] <= 0
        );

        $order->detail = $detail;
        $order->save();

        return $order;
    }

    /**
     * Only a confirmed cancellation may suppress a provider request. The plain
     * `inactive` value is intentionally not enough: older code also used it for
     * expired orders that are still renewable.
     */
    public function shouldSkipProviderRefresh(Orders $order): bool
    {
        if (in_array((string) $order->status, ['cancelled', 'disabled', 'deleted', '-1', '-2'], true)) {
            return true;
        }

        $detail = is_array($order->detail) ? $order->detail : [];
        $lifecycle = is_array($detail['lifecycle'] ?? null) ? $detail['lifecycle'] : [];

        return ($lifecycle['remote_disabled'] ?? false) === true
            || !empty($lifecycle['cancelled_at'])
            || $this->providerIsInactive($lifecycle['provider_status'] ?? null);
    }

    public function statusMeta(Orders $order, ?Carbon $now = null): array
    {
        $this->refreshTimeStatus($order, $now);

        return match ($this->normalizeStatus($order->status)) {
            Orders::STATUS_ACTIVE => ['key' => Orders::STATUS_ACTIVE, 'icon' => '🟢', 'label' => 'فعال', 'priority' => 1],
            Orders::STATUS_DATA_EXHAUSTED => ['key' => Orders::STATUS_DATA_EXHAUSTED, 'icon' => '🟠', 'label' => 'اتمام حجم', 'priority' => 2],
            Orders::STATUS_SUSPENDED => ['key' => Orders::STATUS_SUSPENDED, 'icon' => '🔵', 'label' => 'اتمام زمان (معلق)', 'priority' => 3],
            default => ['key' => Orders::STATUS_INACTIVE, 'icon' => '🔴', 'label' => 'غیرفعال', 'priority' => 4],
        };
    }

    public function orderByStatus(Builder $query): Builder
    {
        return $query->orderByRaw("CASE
            WHEN status IN ('active', '1', 'created') THEN 1
            WHEN status IN ('data_exhausted', '0', 'limited') THEN 2
            WHEN status IN ('suspended', 'expired', '2', 'on_hold') THEN 3
            ELSE 4 END");
    }

    /**
     * Refresh the statuses shown in a customer's order list.
     *
     * PasarGuard is queried once per panel (not once per order), and panels that
     * only contain confirmed cancelled orders are never queried.
     */
    public function refreshPasarguardListStatuses(int $userId): array
    {
        $this->reconcileTimeStatuses($userId);

        $panelIds = Panels::query()
            ->where('system_type', 'pasarguard')
            ->pluck('id');

        if ($panelIds->isEmpty()) {
            return ['checked' => 0, 'updated' => 0, 'failed' => 0, 'requests' => 0];
        }

        $ordersByPanel = Orders::query()
            ->where('user_id', $userId)
            ->whereIn('panel_id', $panelIds)
            ->whereNotNull('uid')
            ->get()
            ->reject(fn (Orders $order) => $this->shouldSkipProviderRefresh($order))
            ->groupBy('panel_id');

        if ($ordersByPanel->isEmpty()) {
            return ['checked' => 0, 'updated' => 0, 'failed' => 0, 'requests' => 0];
        }

        $panels = Panels::query()
            ->whereIn('id', $ordersByPanel->keys())
            ->get()
            ->keyBy('id');

        $checked = 0;
        $updated = 0;
        $failed = 0;
        $requests = 0;

        foreach ($ordersByPanel as $panelId => $orders) {
            $panel = $panels->get($panelId);
            if (!$panel) {
                $failed += $orders->count();
                continue;
            }

            try {
                $requests++;
                $response = $this->fetchPasarguardUsers($panel);
                $usersById = $this->pasarguardUsersById($response);

                if ($usersById === null) {
                    $failed += $orders->count();
                    Log::warning('Could not parse PasarGuard users while refreshing order list', [
                        'panel_id' => $panel->id,
                        'user_id' => $userId,
                    ]);
                    continue;
                }

                foreach ($orders as $order) {
                    $remoteUser = $usersById[(string) $order->uid] ?? null;
                    if (!is_array($remoteUser)) {
                        $failed++;
                        continue;
                    }

                    $before = (string) $order->status;
                    $this->applyPasarguardListStatus($order, $remoteUser);
                    $checked++;
                    if ($before !== (string) $order->status) {
                        $updated++;
                    }
                }
            } catch (Throwable $exception) {
                $failed += $orders->count();
                Log::warning('PasarGuard order-list status refresh failed', [
                    'panel_id' => $panel->id,
                    'user_id' => $userId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return compact('checked', 'updated', 'failed', 'requests');
    }

    protected function fetchPasarguardUsers(Panels $panel): mixed
    {
        $pasarGuard = new PasarGuard([
            'url' => $panel->url,
            'username' => $panel->username,
            'password' => $panel->password,
            'id' => $panel->id,
        ]);

        return $pasarGuard->getAllUsers();
    }

    private function pasarGuardUsersById(mixed $response): ?array
    {
        if (!is_array($response) || ($response['status'] ?? null) === false) {
            return null;
        }

        $users = array_is_list($response) ? $response : null;
        foreach (['users', 'items', 'data'] as $key) {
            if (isset($response[$key]) && is_array($response[$key]) && array_is_list($response[$key])) {
                $users = $response[$key];
                break;
            }
        }

        if ($users === null) {
            return null;
        }

        $usersById = [];
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            $id = $user['id'] ?? $user['uid'] ?? $user['user_id'] ?? $user['uuid'] ?? null;
            if ($id !== null && $id !== '') {
                $usersById[(string) $id] = $user;
            }
        }

        return $usersById;
    }

    private function applyPasarguardListStatus(Orders $order, array $remoteUser): void
    {
        if (!empty($remoteUser['expire'])) {
            try {
                $order->expire_at = Carbon::parse($remoteUser['expire'])->timezone(config('app.timezone'));
            } catch (Throwable) {
                // An invalid remote expiry must not erase the valid local value.
            }
        }

        $order->status = $this->resolveProviderOrderStatus(
            $order,
            $remoteUser['status'] ?? null,
            $this->pasarguardDataIsExhausted($remoteUser)
        );

        $detail = is_array($order->detail) ? $order->detail : [];
        $lifecycleDetail = is_array($detail['lifecycle'] ?? null) ? $detail['lifecycle'] : [];
        $detail['lifecycle'] = array_merge($lifecycleDetail, [
            'last_checked_at' => now()->toDateTimeString(),
            'provider_status' => $remoteUser['status'] ?? null,
        ]);
        $order->detail = $detail;
        $order->save();
    }

    private function resolveProviderOrderStatus(
        Orders $order,
        mixed $providerStatus,
        bool $dataIsExhausted
    ): string
    {
        $providerStatus = strtolower(trim((string) $providerStatus));

        if ($this->providerIsInactive($providerStatus)) {
            return Orders::STATUS_INACTIVE;
        }

        if (in_array($providerStatus, ['suspended', 'expired', 'on_hold'], true)) {
            return $this->expiryStatus($order) === Orders::STATUS_INACTIVE
                ? Orders::STATUS_INACTIVE
                : Orders::STATUS_SUSPENDED;
        }

        if ($providerStatus === 'limited' || $dataIsExhausted) {
            return Orders::STATUS_DATA_EXHAUSTED;
        }

        if (in_array($providerStatus, ['active', 'created', '1'], true)) {
            return Orders::STATUS_ACTIVE;
        }

        return $this->expiryStatus($order) ?? $this->normalizeStatus($order->status);
    }

    private function expiryStatus(Orders $order, ?Carbon $now = null): ?string
    {
        if (!$order->expire_at) {
            return null;
        }

        $now ??= now();
        $expireAt = Carbon::parse($order->expire_at);

        if ($expireAt->copy()->addDays(self::RENEWAL_GRACE_DAYS)->lte($now)) {
            return Orders::STATUS_INACTIVE;
        }

        return $expireAt->lte($now) ? Orders::STATUS_SUSPENDED : null;
    }

    private function pasarguardDataIsExhausted(array $remoteUser): bool
    {
        $limit = $remoteUser['data_limit'] ?? null;
        $used = $remoteUser['used_traffic'] ?? null;

        return is_numeric($limit)
            && is_numeric($used)
            && (float) $limit > 0
            && (float) $used >= (float) $limit;
    }

    /**
     * Scheduler entry point: enforce time transitions, disable cancelled clients,
     * and refresh volume status for renewable orders.
     */
    public function synchronizeAll(): array
    {
        $this->reconcileTimeStatuses();
        $checked = 0;
        $disabled = 0;
        $failed = 0;

        Orders::query()
            ->where('status', Orders::STATUS_INACTIVE)
            ->whereNotNull('expire_at')
            ->where('expire_at', '<=', now()->subDays(self::RENEWAL_GRACE_DAYS))
            ->chunkById(100, function ($orders) use (&$disabled, &$failed) {
                foreach ($orders as $order) {
                    $detail = is_array($order->detail) ? $order->detail : [];
                    $lifecycleDetail = is_array($detail['lifecycle'] ?? null) ? $detail['lifecycle'] : [];
                    if (($lifecycleDetail['remote_disabled'] ?? false) === true) {
                        continue;
                    }

                    if ($this->disableRemoteOrder($order)) {
                        $lifecycleDetail['remote_disabled'] = true;
                        $lifecycleDetail['cancelled_at'] = now()->toDateTimeString();
                        $detail['lifecycle'] = $lifecycleDetail;
                        $order->detail = $detail;
                        $order->save();
                        $disabled++;
                    } else {
                        $failed++;
                    }
                }
            });

        Orders::query()
            ->whereIn('status', [Orders::STATUS_ACTIVE, Orders::STATUS_DATA_EXHAUSTED, '1', '0', 'created'])
            ->where(function (Builder $query) {
                $query->whereNull('expire_at')->orWhere('expire_at', '>', now());
            })
            ->chunkById(100, function ($orders) use (&$checked, &$failed) {
                foreach ($orders as $order) {
                    try {
                        $configDetail = getConfigDetail($order);
                        if ($configDetail['status'] ?? false) {
                            $this->applyConfigDetail($order, $configDetail);
                            $checked++;
                        } else {
                            $failed++;
                        }
                    } catch (Throwable $exception) {
                        $failed++;
                        Log::warning('Order lifecycle synchronization failed', [
                            'order_id' => $order->id,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        return compact('checked', 'disabled', 'failed');
    }

    private function disableRemoteOrder(Orders $order): bool
    {
        $panel = $order->panel_id ? Panels::find($order->panel_id) : null;
        if (!$panel || !$order->uid) {
            return false;
        }

        if ($panel->system_type === 'pasarguard') {
            $pasarGuard = new PasarGuard([
                'url' => $panel->url,
                'username' => $panel->username,
                'password' => $panel->password,
                'id' => $panel->id,
            ]);

            if (!$pasarGuard->checkConnection()) {
                return false;
            }

            $result = $pasarGuard->updateUserById($order->uid, ['status' => 'disabled']);

            return is_array($result) && ($result['status'] ?? true) !== false;
        }

        $session = loginToSanaie([
            'username' => $panel->username,
            'password' => $panel->password,
            'url' => $panel->url,
        ]);
        if (empty($session['status']) || empty($session['session'])) {
            return false;
        }

        $clientResult = getClient([
            'sessionCookie' => $session['session'],
            'serverUrl' => $panel->url,
            'uuid' => $order->uid,
        ]);
        $client = is_array($clientResult) ? ($clientResult['obj'][0] ?? null) : null;
        if (!is_array($client)) {
            return false;
        }

        $result = updateClient([
            'serverUrl' => $panel->url,
            'sessionCookie' => $session['session'],
            'inboundId' => $client['inboundId'],
            'uuid' => $order->uid,
            'email' => $client['email'] ?? $order->remark,
            'expiryTimestamp' => $client['expiryTime'] ?? 0,
            'limitIp' => $client['limitIp'] ?? 0,
            'subId' => $client['subId'] ?? $order->sub_id,
            'totalGB' => $client['total'] ?? 0,
            'enable' => false,
        ]);

        return is_array($result) && ($result['success'] ?? false) === true;
    }

    private function normalizeStatus(mixed $status): string
    {
        return match ((string) $status) {
            '1', 'active', 'created' => Orders::STATUS_ACTIVE,
            '0', 'data_exhausted', 'limited' => Orders::STATUS_DATA_EXHAUSTED,
            '2', 'suspended', 'expired', 'on_hold' => Orders::STATUS_SUSPENDED,
            default => Orders::STATUS_INACTIVE,
        };
    }

    private function providerIsInactive(mixed $status): bool
    {
        return in_array(strtolower((string) $status), ['disabled', 'inactive', 'cancelled', 'deleted'], true);
    }
}
