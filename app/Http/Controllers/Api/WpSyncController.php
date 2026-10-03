<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WpSyncService;
use Illuminate\Http\Request;

class WpSyncController extends Controller
{
    private function guard(Request $request, WpSyncService $sync)
    {
        if (!$sync->authorize($request->header('X-IPSABET-API-KEY'))) {
            return response()->json(['ok' => false, 'message' => 'unauthorized'], 403);
        }
        return null;
    }

    private function json(array $data, int $status = 200)
    {
        return response()->json($data, $status);
    }

    public function ping(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        return $this->json([
            'ok' => true,
            'message' => 'ip-sabet-wp-sync-ready',
            'version' => '2.0.0',
            'time' => now()->toDateTimeString(),
        ]);
    }

    /* Account link */

    public function linkStatus(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $result = $sync->linkStatus($request->all());
        return $this->json(['ok' => true] + $result);
    }

    public function disconnectLink(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $result = $sync->disconnectLink($request->input('tel_id'), $request->input('phone'), $request->input('site_user_id'));
        return $this->json(['ok' => true, 'deleted' => $result]);
    }

    public function customerLoyaltyEvents(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->resolveUser($request->all());
        if (!$user) return $this->json(['ok' => false, 'message' => 'user_not_found'], 404);
        if ((int) $user->is_seller === 1) return $this->json(['ok' => false, 'message' => 'customer_only'], 422);
        return $this->json(['ok' => true, 'events' => app(\App\Services\CustomerLoyaltyService::class)->events($user)]);
    }

    /* Wallet */

    public function walletBalance(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->resolveUser($request->all());
        if (!$user) return $this->json(['ok' => false, 'message' => 'user_not_found_or_not_linked'], 404);
        return $this->json(['ok' => true, 'wallet' => $sync->walletSummary($user)]);
    }

    public function walletTransactions(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->resolveUser($request->all());
        if (!$user) return $this->json(['ok' => false, 'message' => 'user_not_found_or_not_linked'], 404);
        return $this->json(['ok' => true] + $sync->walletTransactions($user, (int) $request->input('page', 1), (int) $request->input('per_page', 20)));
    }

    public function walletCredit(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->resolveUser($request->all());
        if (!$user) return $this->json(['ok' => false, 'message' => 'user_not_found_or_not_linked'], 404);
        $balance = $sync->creditWallet($user, (int) $request->input('amount', 0), $request->all());
        return $this->json(['ok' => true, 'balance' => $balance, 'wallet' => $sync->walletSummary($user->fresh())]);
    }

    public function walletDebit(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->resolveUser($request->all());
        if (!$user) return $this->json(['ok' => false, 'message' => 'user_not_found_or_not_linked'], 404);
        $result = $sync->debitWallet($user, (int) $request->input('amount', 0), $request->all());
        return $this->json($result, !empty($result['ok']) ? 200 : 422);
    }

    /* Users */

    public function usersList(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        return $this->json(['ok' => true] + $sync->usersList((int) $request->input('page', 1), (int) $request->input('per_page', 20), (string) $request->input('search', '')));
    }

    public function usersShow(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->resolveUser($request->all());
        if (!$user) return $this->json(['ok' => false, 'message' => 'user_not_found_or_not_linked'], 404);
        return $this->json(['ok' => true, 'user' => $sync->formatUser($user, true)]);
    }

    public function usersUpdateStatus(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->resolveUser($request->all());
        if (!$user) return $this->json(['ok' => false, 'message' => 'user_not_found_or_not_linked'], 404);
        $user = $sync->updateUserStatus($user, (int) $request->input('status', 1), (string) $request->input('note', ''));
        return $this->json(['ok' => true, 'user' => $sync->formatUser($user, true)]);
    }

    public function usersWalletAdjust(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->resolveUser($request->all());
        if (!$user) return $this->json(['ok' => false, 'message' => 'user_not_found_or_not_linked'], 404);
        $result = $sync->adminWalletAdjust($user, (string) $request->input('direction', 'credit'), (int) $request->input('amount', 0), (string) $request->input('note', ''), $request->all());
        return $this->json($result, !empty($result['ok']) ? 200 : 422);
    }

    /* Orders */

    public function ordersList(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->resolveUser($request->all());
        if ($user) {
            return $this->json(['ok' => true] + $sync->ordersListForUser($user, (int) $request->input('page', 1), (int) $request->input('per_page', 20), (string) $request->input('search', '')));
        }
        return $this->json(['ok' => true] + $sync->ordersList((int) $request->input('page', 1), (int) $request->input('per_page', 20), (string) $request->input('search', ''), (string) $request->input('source', '')));
    }

    public function ordersShow(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $order = $sync->findOrder((int) $request->input('order_id', $request->input('id', 0)), $request->all());
        if (!$order) return $this->json(['ok' => false, 'message' => 'order_not_found'], 404);
        return $this->json(['ok' => true, 'order' => $sync->formatOrder($order, true)]);
    }

    public function importSiteOrder(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $order = $request->input('order', []);
        if (!is_array($order)) return $this->json(['ok' => false, 'message' => 'invalid_order_payload'], 422);
        if (($order['service_type'] ?? '') === 'dedicated') {
            return $this->json(['ok' => true, 'skipped' => 'site_managed_dedicated_order']);
        }
        $saved = $sync->importSiteOrder($order);
        if (!$saved) return $this->json(['ok' => false, 'message' => 'linked_user_not_found_or_invalid_order'], 404);
        return $this->json(['ok' => true, 'bot_order_id' => $saved->id, 'order' => $sync->formatOrder($saved, true)]);
    }

    public function updateBotOrder(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->resolveUser($request->all());
        if (!$user) return $this->json(['ok' => false, 'message' => 'user_not_found_or_not_linked'], 404);
        $order = $sync->updateBotOrder($user, (int) $request->input('bot_order_id', $request->input('order_id', 0)), (array) $request->input('order', []));
        if (!$order) return $this->json(['ok' => false, 'message' => 'bot_order_not_found'], 404);
        return $this->json(['ok' => true, 'order' => $sync->formatOrder($order, true)]);
    }

    public function renewBotOrder(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->resolveUser($request->all());
        if (!$user) return $this->json(['ok' => false, 'message' => 'user_not_found_or_not_linked'], 404);
        $result = $sync->renewBotOrder($user, (int) $request->input('bot_order_id', $request->input('order_id', 0)), $request->all());
        return $this->json($result, !empty($result['ok']) ? 200 : 422);
    }

    /* Payments */

    public function paymentsList(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->resolveUser($request->all());
        if ($user) {
            return $this->json(['ok' => true] + $sync->paymentsListForUser($user, (int) $request->input('page', 1), (int) $request->input('per_page', 20), (string) $request->input('search', '')));
        }
        return $this->json(['ok' => true] + $sync->paymentsList((int) $request->input('page', 1), (int) $request->input('per_page', 20), (string) $request->input('search', ''), $request->input('status')));
    }

    public function paymentsShow(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $payment = $sync->findPayment((int) $request->input('payment_id', $request->input('id', 0)));
        if (!$payment) return $this->json(['ok' => false, 'message' => 'payment_not_found'], 404);
        return $this->json(['ok' => true, 'payment' => $sync->formatPayment($payment, true)]);
    }

    public function paymentsApprove(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $result = $sync->approvePayment((int) $request->input('payment_id', $request->input('id', 0)), $request->all());
        return $this->json($result, !empty($result['ok']) ? 200 : 422);
    }

    public function paymentsReject(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $result = $sync->rejectPayment((int) $request->input('payment_id', $request->input('id', 0)), $request->all());
        return $this->json($result, !empty($result['ok']) ? 200 : 422);
    }

    /* Admin reports */

    public function adminStats(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $series = $sync->adminStats((string) $request->input('start'), (string) $request->input('end'), (string) $request->input('group', 'day'));
        return $this->json(['ok' => true, 'series' => $series]);
    }

    public function adminList(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $result = $sync->adminList((string) $request->input('type', 'orders'), (int) $request->input('page', 1), (string) $request->input('search', ''), (int) $request->input('per_page', 20));
        return $this->json(['ok' => true] + $result);
    }

    public function syncUserFull(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->resolveUser($request->all());
        if (!$user) return $this->json(['ok' => false, 'message' => 'user_not_found_or_not_linked'], 404);
        return $this->json(['ok' => true] + $sync->fullUserPayload($user));
    }

    /* Catalog / admin resources */

    public function catalogList(Request $request, WpSyncService $sync, string $type)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        return $this->json(['ok' => true] + $sync->catalogList($type, (int) $request->input('page', 1), (int) $request->input('per_page', 50), (string) $request->input('search', ''), $request->all()));
    }

    public function catalogShow(Request $request, WpSyncService $sync, string $type, int $id)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $item = $sync->catalogShow($type, $id);
        if (!$item) return $this->json(['ok' => false, 'message' => 'item_not_found'], 404);
        return $this->json(['ok' => true, 'item' => $item]);
    }
}
