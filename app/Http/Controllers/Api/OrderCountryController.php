<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Orders;
use App\Services\OrderCountryService;
use App\Services\WpSyncService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderCountryController extends Controller
{
    public function handle(Request $request, string $action, WpSyncService $sync, OrderCountryService $countries)
    {
        if (! $sync->authorize($request->header('X-IPSABET-API-KEY'))) {
            return response()->json(['ok' => false], 403);
        }
        $input = $request->validate([
            'order_id' => 'sometimes|integer|min:1', 'site_order_id' => 'sometimes|integer|min:1',
            'country_id' => 'sometimes|integer|min:0', 'token' => 'sometimes|string|regex:/^[a-f0-9]{24}$/',
            'admin_id' => 'sometimes|integer|min:1', 'admin_name' => 'sometimes|string|max:100',
            'uid' => 'sometimes|string|max:50', 'group_ids' => 'sometimes|array|max:200', 'group_ids.*' => 'integer|min:1',
        ]);
        try {
            if ($action === 'sync') {
                if (empty($input['site_order_id']) || empty($input['uid']) || ! $countries->groups($input['group_ids'] ?? null)) {
                    throw new \RuntimeException('اطلاعات کشور معتبر نیست.');
                }
                $mapping = DB::table('wp_sync_site_orders')->where('site_order_id', $input['site_order_id'])->first();
                if (! $mapping) {
                    return response()->json(['ok' => true, 'skipped' => 'not_linked']);
                }

                return DB::transaction(function () use ($mapping, $input, $countries) {
                    $order = Orders::whereKey($mapping->bot_order_id)->lockForUpdate()->firstOrFail();
                    if ($order->isSiteManagedDedicated()) {
                        throw new \RuntimeException('این سرویس فقط از سایت مدیریت می‌شود.');
                    }
                    if ((string) $order->uid !== $input['uid']) {
                        throw new \RuntimeException('اتصال سفارش یکسان نیست.');
                    }
                    if (! $countries->location((int) $order->panel_id, $input['group_ids'])) {
                        throw new \RuntimeException('گروه به کشور مشخصی در ربات وصل نیست.');
                    }
                    $countries->observe($order, ['group_ids' => $input['group_ids']], ['source' => 'website', 'id' => (string) ($input['admin_id'] ?? ''), 'name' => $input['admin_name'] ?? '']);
                    $order->save();

                    return response()->json(['ok' => true, 'country' => $order->detail['current_country'] ?? null]);
                });
            }
            if (empty($input['admin_id'])) {
                throw new \RuntimeException('شناسهٔ مدیر لازم است.');
            }
            $actor = ['source' => 'website', 'id' => (string) $input['admin_id'], 'name' => $input['admin_name'] ?? ''];
            if ($action === 'confirm') {
                $order = $countries->confirm($input['token'] ?? '', $actor);

                return response()->json(['ok' => true, 'order' => $sync->formatOrder($order)]);
            }
            $order = Orders::botManaged()->findOrFail($input['order_id'] ?? 0);
            if ($action === 'options') {
                return response()->json(['ok' => true, 'options' => $countries->options($order), 'order' => $sync->formatOrder($order)]);
            }
            if ($action === 'preview') {
                return response()->json(['ok' => true] + $countries->preview($order, (int) ($input['country_id'] ?? -1), $actor));
            }

            return response()->json(['ok' => false], 404);
        } catch (ModelNotFoundException) {
            return response()->json(['ok' => false, 'message' => 'سفارش پیدا نشد.'], 404);
        } catch (\RuntimeException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 409);
        }
    }
}
