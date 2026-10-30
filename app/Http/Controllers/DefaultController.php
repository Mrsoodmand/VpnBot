<?php

namespace App\Http\Controllers;

use App\lib\PasarGuard;
use App\Models\Inbounds;
use App\Models\Orders;
use App\Models\Panels;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Telegram;
use Carbon\Carbon;

class DefaultController extends Controller
{
    public function runDefaultValueForNewProject()
    {

        $settings = [
            'renew' => [
                'name' => 'وضعیت تمدید',
                'value' => 1
            ],
            'extra' => [
                'name' => 'وضعیت خرید حجم اضافه',
                'value' => -1
            ],
            'sell' => [
                'name' => 'وضعیت فروش',
                'value' => 1
            ],
            'referral' => [
                'name' => 'وضعیت پورسانت',
                'value' => 0
            ],
            'cart_be_cart' => [
                'name' => 'وضعیت کارت به کارت',
                'value' => 1
            ],
            'cart_be_cart_random' => [
                'name' => 'نمایش تصادفی کارت ها',
                'value' => -1
            ],
            'support_id' => [
                'name' => 'آیدی پشتیبانی',
                'value' => '@supportId'
            ],
            'report_id' => [
                'name' => 'آیدی کانال تراکنش ها',
                'value' => '@transactionId'
            ],
            'cart_be_cart_id' => [
                'name' => 'آیدی کانال کارت به کارت',
                'value' => '@cartBeCartId'
            ],
            'channel_id' => [
                'name' => 'آیدی کانال',
                'value' => '@channelId'
            ],
            'site_address' => [
                'name' => 'آدرس سایت',
                'value' => 'https://expample.com'
            ],
            'charge_amount' => [
                'name' => 'مبالغ شارژ کیف پول',
                'value' => 0
            ],
            'home-page' => [
                'name' => 'متن منو',
                'value' => null
            ],
            'join-bot' => [
                'name' => 'ثبت نام',
                'value' => 1
            ],
            'join-with-referral' => [
                'name' => 'عضویت با رفرال',
                'value' => -1
            ],
            'channel-join' => [
                'name' => 'جوین اجباری',
                'value' => -1
            ]
        ];

        $dataMap = [];

        foreach ($settings as $key => $label) {

            $row = Setting::firstOrCreate(
                ['key' => $key],
                [
                    'name' => $label['name'],
                    'value' => $label['value']
                ]
            );

            $dataMap[$key] = $row;
        }

        Setting::firstOrCreate(
            ['key' => 'commission'],
            [
                'name' => 'درصد پورسانت',
                'value' => 0
            ]
        );
        Setting::firstOrCreate(
            ['key' => 'commission_text'],
            [
                'name' => 'متن پورسانت',
                'value' => 'درصد پورسانت به صورت پیش‌فرض اعمال می‌شود.'
            ]
        );
        $vipService = Service::where('name', 'vip')->first();
        if (is_null($vipService)) {
            $vipService = new Service();
            $vipService->name = 'vip';
            $vipService->status = 1;
            $vipService->price_per_gb = 5000;
            $vipService->save();
        }
        $vipService = Service::where('name', 'normal')->first();
        if (is_null($vipService)) {
            $vipService = new Service();
            $vipService->name = 'normal';
            $vipService->status = 1;
            $vipService->price_per_gb = 5000;
            $vipService->save();
        }
    }

    public function exportData()
    {
        set_time_limit(9999999);
//        $filePath = base_path('oldData/user.json');
//        $json = file_get_contents($filePath);
//        $users = json_decode($json, true);
//        if (json_last_error() !== JSON_ERROR_NONE) {
//            dd('JSON Error: ' . json_last_error_msg());
//        }
//        foreach ($users as $user) {
//            if (empty($user['user_id'])) {
//                continue;
//            }
//            $check = User::where('tel_id', $user['user_id'])->first();
//            if (!is_null($check)) {
//                continue;
//            }
//            User::updateOrCreate(
//                [
//                    'tel_id' => $user['user_id'],
//                ],
//                [
//                    'username' => !empty($user['name'])
//                        ? ltrim($user['name'], '@')
//                        : null,
//
//                    'balance' => isset($user['balance'])
//                        ? (float)$user['balance'].'000'
//                        : 0,
//
//                    'status' => 1,
//                ]
//            );
//
//        }
//
//        $filePath = base_path('oldData/bot_user.json');
//        $json = file_get_contents($filePath);
//        $users = json_decode($json, true);
//        if (json_last_error() !== JSON_ERROR_NONE) {
//            dd('JSON Error: ' . json_last_error_msg());
//        }
//
//        foreach ($users as $user) {
//            if (empty($user['user_id'])) {
//                continue;
//            }
//            $check = User::where('tel_id', $user['user_id'])->first();
//            if (!is_null($check)) {
//                continue;
//            }
//            User::updateOrCreate(
//                [
//                    'tel_id' => $user['user_id'],
//                ],
//                [
//                    'username' => !empty($user['name'])
//                        ? ltrim($user['name'], '@')
//                        : null,
//                    'status' => 1,
//                ]
//            );
//        }


//        $filePath = base_path('oldData/plans.json');
//        $json = file_get_contents($filePath);
//        $plans = json_decode($json, true);
//        if (json_last_error() !== JSON_ERROR_NONE) {
//            dd('JSON Error: ' . json_last_error_msg());
//        }
//
//        foreach ($plans as $plan) {
//            $newPlan = Plans::where('name',$plan['name'])->first();
//            if (is_null($newPlan)){
//                $service = Service::where('name',$plan['plan_type'])->first();
//                $newPlan = new Plans();
//                $newPlan->name = $plan['name'];
//                $newPlan->bandwidth = $plan['volume_gb'];
//                $newPlan->days = $plan['days'];
//                $newPlan->price = $plan['price'];
//                $newPlan->status = 1;
//                $newPlan->type = !is_null($service) ? $service->id : '';
//                $newPlan->save();
//            }
//        }


        $filePath = base_path('oldData/orders.json');

        $json = file_get_contents($filePath);
        $orders = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            dd('JSON Error: ' . json_last_error_msg());
        }

        $panel = Panels::find(1);

        $pasarGuard = new PasarGuard([
            'url' => $panel->url,
            'username' => $panel->username,
            'password' => $panel->password,
            'id' => $panel->id,
        ]);

        if (!$pasarGuard->checkConnection()) {
            return [
                'status' => false,
                'message' => $pasarGuard->getLoginStatus()['message'],
            ];
        }

        foreach ($orders as $order) {
            $checkOrder = Orders::where('remark', $order['email'])->first();
            if (!is_null($checkOrder)) {
                continue;
            }

            $result = $pasarGuard->getUser($order['email']);
            if (array_key_exists('username', $result)) {


                $user = User::where('tel_id', $order['vendor_id'])->first();
                $activeGroup = Inbounds::where('inbound_id', $result['group_ids'][0])
                    ->where('panel_id', $panel->id)
                    ->first();
                Orders::updateOrCreate(
                    [
                        'remark' => $order['email'],
                    ],
                    [
                        'user_id' => !is_null($user) ? $user->id : 0,
                        'remark' => $order['email'],
                        'uid' => $result['id'],
                        'sub_id' => $result['subscription_url'],
                        'plan' => 0,
                        'panel_id' => $panel->id,
                        'inbound_id' => $activeGroup->id,
                        'system_type' => 'pasarguard',
                        'expire_at' => Carbon::parse($result['expire'])->format('Y-m-d H:i:s'),
                        'status' => 1,
                        'detail' => [],
                    ]
                );
            }
        }
    }

    public function setWebhook()
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        $telegram = new Telegram($token);
        $url=(string)config('services.telegram.webhook_url');
        $secret=(string)config('services.telegram.webhook_secret');
        if(parse_url($url,PHP_URL_SCHEME)!=='https'||!preg_match('/^[A-Za-z0-9_-]{32,256}$/D',$secret))abort(503,'secure_webhook_configuration_required');
        return $telegram->setWebhook(['url'=>$url,'secret_token'=>$secret,'drop_pending_updates'=>false]);
    }

    public function deleteWebhook()
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        $telegram = new Telegram($token);
        return $telegram->deleteWebhook([
            'drop_pending_updates' => true
        ]);
    }

    public function importUsers()
    {
        $username = '';
        $password = '';
        $path = '';
        $url = 'https://38.180.81.220';
        $session = null;

        $loginData = [
            'username' => $username,
            'password' => $password,
            'url' => $url . $path,
        ];

        $session = loginToSanaie($loginData)['sessionCookie'];
        dd($session);
    }

}
