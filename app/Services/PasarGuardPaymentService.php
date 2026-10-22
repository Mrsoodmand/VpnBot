<?php

namespace App\Services;

use App\Lib\PasarGuard;
use App\Models\Payment;
use Carbon\Carbon;

/** Saved operation targets let an admin reconcile a lost response before retrying. */
class PasarGuardPaymentService
{
    private function saveOperation(Payment $payment, string $key, array $operation): void
    {
        $payment->refresh();
        $detail = $payment->detail ?? [];
        $detail['fulfillment_operations'][$key] = $operation;
        $payment->detail = $detail;
        $payment->save();
    }

    public function createAccount(PasarGuard $client, Payment $payment, int $item, array $params, int $panelId): array
    {
        $key = 'create_'.$item;
        $operation = $payment->fresh()->detail['fulfillment_operations'][$key] ?? null;
        if ($operation) {
            if (($operation['panel_id'] ?? null) !== $panelId) {
                return ['status' => false, 'message' => 'Order panel changed since the first attempt; manual review required'];
            }
            $params = $operation['params'];
            $remote = $client->getUser($params['username']);
            if (is_array($remote) && !empty($remote['id']) && ($remote['status'] ?? false) !== false) {
                if (($remote['note'] ?? '') !== $params['note']) {
                    return ['status' => false, 'message' => 'Username exists but does not belong to this payment; manual review required'];
                }
                return $client->configForCreatedUser($remote, $params['client_type'] ?? 'links');
            }
            if (!is_array($remote) || ($remote['http_code'] ?? null) !== 404) {
                return is_array($remote) && ($remote['status'] ?? null) === false
                    ? $remote : ['status' => false, 'message' => 'Cannot verify previously requested account'];
            }
        } else {
            $params['note'] = "Telegram payment:{$payment->id} item:{$item}";
            $this->saveOperation($payment, $key, ['params' => $params, 'panel_id' => $panelId]);
        }

        // Reuse the saved username, so a late first request cannot create a second account.
        return $client->createUserAndGetConfig($params);
    }

    public function updateAccount(PasarGuard $client, Payment $payment, string $userId, array $current, array $params, int $panelId): array
    {
        $operation = $payment->fresh()->detail['fulfillment_operations']['update'] ?? null;
        if ($operation) {
            if ((string) $operation['user_id'] !== $userId || ($operation['panel_id'] ?? null) !== $panelId) {
                return ['status' => false, 'message' => 'Order user changed since the first attempt; manual review required'];
            }
            $params = $operation['params'];
            if (!empty($operation['completed']) || $this->matches($current, $params)) {
                return $current + ['_applied_params' => $params];
            }
            if (!$this->matches($current, $operation['before'])) {
                return ['status' => false, 'message' => 'Service changed since the first attempt; automatic retry stopped to avoid duplicate traffic or renewal'];
            }
        } else {
            $before = array_intersect_key($current, $params);
            $operation = ['panel_id' => $panelId, 'user_id' => $userId, 'before' => $before, 'params' => $params];
            $this->saveOperation($payment, 'update', $operation);
        }

        $result = $client->updateUserById($userId, $params);
        if (is_array($result) && !empty($result['id']) && ($result['status'] ?? false) !== false) {
            $operation['completed'] = true;
            $this->saveOperation($payment, 'update', $operation);
            return $result + ['_applied_params' => $params];
        }
        $failure = is_array($result) && ($result['status'] ?? null) === false
            ? $result : ['status' => false, 'message' => 'Invalid PasarGuard update response'];
        $failure['refund_safe'] = in_array($failure['http_code'] ?? null, [400, 403, 404, 422], true);
        return $failure;
    }

    private function matches(array $current, array $expected): bool
    {
        foreach ($expected as $field => $value) {
            // Status may change automatically with usage/time; compare purchased limits.
            if ($field === 'status') continue;
            if (!array_key_exists($field, $current)) return false;
            if ($field === 'expire' && $value && $current[$field]) {
                if (!Carbon::parse($value)->equalTo(Carbon::parse($current[$field]))) return false;
            } elseif ($current[$field] != $value) {
                return false;
            }
        }
        return true;
    }
}
