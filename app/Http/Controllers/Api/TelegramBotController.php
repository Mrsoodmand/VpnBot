<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Lib\Jdf;
use App\Lib\PasarGuard;
use App\Models\Carts;
use App\Models\Countries;
use App\Models\ExtraBandwidth;
use App\Models\Inbounds;
use App\Models\Message;
use App\Models\Orders;
use App\Models\Panels;
use App\Models\Payment;
use App\Models\Plans;
use App\Models\PreOrder;
use App\Models\Seller;
use App\Models\SellerInbound;
use App\Models\Service;
use App\Models\Setting;
use App\Models\TelegramData;
use App\Models\User;
use App\Services\OrderCountryResolver;
use App\Services\OrderLifecycleService;
use App\Services\Telegram;
use App\Services\WpSyncService;
use App\Services\CustomerLoyaltyService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TelegramBotController extends Controller
{
    protected $chatId;
    protected $panel;
    protected $messageId;
    protected $method;

    protected $token;
    protected $telData;
    protected $text;
    protected $type;
    protected $user;
    protected $callbackData;
    protected $callbackId;
    protected $telegramSdk;
    protected $actorId;
    protected $isSeller;
    protected $isAdmin;
    protected $from;
    protected $isJoined = false;
    protected $isPhoto = false;
    protected $fileId = false;
    protected $file_unique_id;
    protected $customData = [];


    public function __construct(Request $request)
    {
        $data = $request->all();

        $telData = new TelegramData();
        $telData->data = json_encode($data);
        $telData->path = 'before';
        $telData->save();

        $this->token = env('TELEGRAM_BOT_TOKEN');
        $this->telegramSdk = new Telegram($this->token);

        if (array_key_exists('message', $data)) {

            $this->messageId = $data['message']['message_id'];
            $this->chatId = $data['message']['chat']['id'];
            $this->actorId = $data['message']['from']['id'] ?? $this->chatId;
            $this->type = 'text';
            if (array_key_exists('photo', $data['message'])) {
                $this->text = "photo";
                $this->fileId = $data['message']['photo'][2]['file_id'];
                $this->file_unique_id = $data['message']['photo'][2]['file_unique_id'];
            } elseif (array_key_exists('text', $data['message'])) {
                $this->text = PersianNumToEn($data['message']['text']);
            }

        } elseif (array_key_exists('callback_query', $data)) {

            $this->chatId = $data['callback_query']['message']['chat']['id'];
            $this->messageId = $data['callback_query']['message']['message_id'];
            $this->callbackData = $data['callback_query']['data'];
            $this->callbackId = $data['callback_query']['id'];
            $this->type = 'callback_query';
            $this->from = $data['callback_query']['from'];
            $this->actorId = $data['callback_query']['from']['id'] ?? $this->chatId;

            if (array_key_exists('photo', $data['callback_query']['message'])) {
                $this->text = $data['callback_query']['message']['caption'];
                $this->isPhoto = true;
            } elseif (array_key_exists('text', $data['callback_query']['message'])) {
                $this->text = PersianNumToEn($data['callback_query']['message']['text']);
            }
        } elseif (array_key_exists('my_chat_member', $data)) {
            $this->chatId = $data['my_chat_member']['chat']['id'];
            $this->customData = [
                'oldStatus' => $data['my_chat_member']['old_chat_member']['status'],
                'newStatus' => $data['my_chat_member']['new_chat_member']['status'],
                $this->type = 'my_chat_member'
            ];
        }

        $this->telData = $data;

        $this->checkUser();
        $user = $this->user;
        $this->isSeller = $user->is_seller;
        $this->isAdmin = $user->is_admin;
    }

    public function index()
    {
        $user = $this->user;
        if ($user->path == 'disabled') {
            return $this->botIsNotActive();
        }

        if ($user->status != 1) {
            return $this->accountIsDisabled();
        }

        if ($this->type === 'callback_query') {
            $this->method = 'edit';
            return $this->callbackQueryAction();
        }

        if ($this->chatId > 0) {
            switch ($this->type) {
                case "text":
                    $this->method = 'send';
                    return $this->NormalTextAction();
                    break;
                case "my_chat_member":
                    return $this->checkChatMember();
                    break;
            }
        }
    }

    protected function callbackQueryAction()
    {
//        try {
        $data = explode('|', $this->callbackData);

        if ($this->callbackData == 'ignore') {
            return $this->ignore();
        }
        foreach ($data as $key => $item) {
            list($name, $id) = explode('=', $item);
            $type[$name] = $id;
        }

        $callbackType = (string) ($type['type'] ?? '');
        if (str_starts_with(strtolower($callbackType), 'admin') && !$this->isAdmin) {
            return $this->denyAdminAccess();
        }

        if (array_key_exists('action', $type)) {
            switch ($type['action']) {
                case 'delete':
                    $this->deleteChat();
                    $this->method = 'toUser';
                    break;
            }
        }

        $this->updatePath($type['type']);

        $telData = new TelegramData();
        $telData->data = json_encode($this->telData);
        $telData->tel_id = $this->chatId;
        $telData->path = $type['type'];
        $telData->types = json_encode($type);
        $telData->save();
        switch ($type['type']) {
            case 'adminCustomerLoyalty': return $this->adminCustomerLoyalty($type);
            case 'adminCustomerDiscount': return $this->adminCustomerDiscount($type);
            case 'adminCustomerDiscountClear': return $this->adminCustomerDiscountSave($type);
            // all access
            case 'home':
                return $this->home($type);
                break;
            case 'checkUserIsJoined':
                return $this->checkUserIsJoined($type);
                break;
            case 'clientService':
                return $this->clientService($type);
                break;
            case 'clientSelectCountry':
                return $this->clientSelectCountry($type);
                break;
            case 'clientSelectPlan':
                return $this->clientSelectPlan($type);
                break;
            case 'clientSelectExtra':
                // Old Telegram messages may still point to the removed extra-volume step.
                return $this->clientSelectCount($type);
                break;
            case 'clientSelectCount':
            case 'CSC':
                return $this->clientSelectCount($type);
                break;
            case 'CLN':
            case 'clientSelectName':
                return $this->clientSelectName($type);
                break;
            case 'clientFinalStep':
                return $this->clientFinalStep($type);
                break;
            case 'paymentCartBeCart':
                return $this->paymentCartBeCart($type);
                break;
            case 'paymentSendReceipt':
                return $this->paymentSendReceipt($type);
                break;
            case 'paymentWallet':
                return $this->paymentWallet($type);
                break;
            case 'paymentCrypto':
                return $this->sendTemporaryMessage('پرداخت کریپتو فعلاً فعال نیست.');
                break;

            // Profile
            case "profile":
                $this->profile($type);
                break;
            case "addFund":
                $this->addFund($type);
                break;
            case "addFundStepOne":
                $this->addFundStepOne($type);
                break;
            case "addFundStepTwo":
                $this->addFundStepTwo($type);
                break;
            case "addFundCustomAmount":
                $this->addFundCustomAmount($type);
                break;
            case "clientWalletTransactions":
                return $this->clientWalletTransactions($type);
                break;

            // Order
            case "clientOrders":
                return $this->clientOrders($type);
                break;
            case "clientSingleOrder":
                return $this->clientSingleOrder($type);
                break;
            case "clientOrderTransactions":
                return $this->clientOrderTransactions($type);
                break;
            case "clientChangeConfigName":
                $this->method = 'toUser';
                return $this->clientChangeConfigName($type);
                break;
            case "clientChangeConfigUid":
                return $this->clientChangeConfigUid($type);
                break;
            case "clientRenewOrder":
                return $this->clientRenewOrder($type);
                break;
            case "clientSubmitRenew":
                return $this->clientSubmitRenew($type);
                break;

            case "clientBuyExtra":
                return $this->clientBuyExtra($type);
                break;
            case "clientSubmitExtra":
                return $this->clientSubmitExtra($type);
                break;

            // Seller Access

            // Admin Panel Admin Access

            case "admin-home":
                return $this->adminMenu($type);
                break;

            case "adminPanelMenu":
                return $this->adminPanelMenu($type);
                break;
            case "admin-panels":
                return $this->adminPanels($type);
                break;
            case "adminCreatePanel":
                return $this->adminCreatePanel($type);
                break;
            case "adminPanelDetail":
                return $this->adminPanelDetail($type);
                break;
            case "adminEditPanel":
                return $this->adminEditPanel($type);
                break;
            case "adminUpdatePanel":
                return $this->adminUpdatePanel($type);
                break;
            case "adminConnectPanel":
                return $this->adminConnectPanel($type);
                break;
            case "adminGetInbounds":
                return $this->adminGetInbounds($type);
                break;
            case "adminPanelDeleteDetail":
                return $this->adminPanelDeleteDetail($type);
                break;
            case "adminPanelDeleteSubmit":
                return $this->adminPanelDeleteSubmit($type);
                break;
            case "adminPasarGuardSellSetting":
                return $this->adminPasarGuardSellSetting($type);
                break;

            case "adminUserList":
                return $this->adminUserList($type);
                break;
            case "adminUserSearch":
                return $this->adminUserSearch($type);
                break;
            case "adminUserDetail":
                return $this->adminUserDetail($type);
                break;
            case "adminUserBalance":
                return $this->adminUserBalance($type);
                break;
            case "adminWalletTransactions":
            case "adminUserTransactions":
                return $this->adminWalletTransactions($type);
                break;
            case "adminUserBalanceAction":
                return $this->adminUserBalanceAction($type);
                break;
            case "adminUserSettings":
                return $this->adminUserSettings($type);
                break;
            case "adminUserIsAdmin":
                return $this->adminUserIsAdmin($type);
                break;
            case "adminUserIsSeller":
                return $this->adminUserIsSeller($type);
                break;
            case "adminUserSellerDiscount":
                return $this->adminUserSellerDiscount($type);
                break;
            case "adminUserSellerInbounds":
                return $this->adminUserSellerInbounds($type);
                break;
            case "adminUserSellerChangeInbound":
                return $this->adminUserSellerChangeInbound($type);
                break;
            case "adminToggleUserStatus":
                return $this->adminToggleUserStatus($type);
                break;

            case "adminPlans":
                return $this->adminPlans($type);
                break;
            case "adminPlanCreate":
                return $this->adminPlanCreate($type);
                break;
            case "adminPlanDetail":
                return $this->adminPlanDetail($type);
                break;
            case "adminEditPlan":
                return $this->adminEditPlan($type);
                break;
            case "adminUpdatePlan":
                return $this->adminUpdatePlan($type);
                break;
            case "adminPlanDeleteDetail":
                return $this->adminPlanDeleteDetail($type);
                break;
            case "adminPlanDeleteSubmit":
                return $this->adminPlanDeleteSubmit($type);
                break;

            case "adminSetting":
                return $this->adminSetting($type);
                break;
            case "adminSettingSell":
                return $this->adminSettingSell($type);
                break;
            case "adminToggleSetting":
                return $this->adminToggleSetting($type);
                break;
            case "adminSettingCommission":
                return $this->adminSettingCommission($type);
                break;
            case "adminSettingCommissionText":
                return $this->adminSettingCommissionText($type);
                break;
            case "adminPaymentSetting":
                return $this->adminPaymentSetting($type);
                break;
            case "adminCartBeCartStatus":
                return $this->adminCartBeCartStatus($type);
                break;
            case "adminCartBeCartRandom":
                return $this->adminCartBeCartRandom($type);
                break;
            case "adminCartSetting":
                return $this->adminCartSetting($type);
                break;

            case "adminBotSetting":
                return $this->adminBotSetting($type);
                break;
            case "adminChangeSetting":
                return $this->adminChangeSetting($type);
                break;
            case "adminChangeSettingSubmit":
                return $this->adminChangeSettingSubmit($type);
                break;
            case "adminSettingChangeValue":
                return $this->adminSettingChangeValue($type);
                break;

            case "adminCountries":
                return $this->adminCountries($type);
                break;
            case "adminCountriesCreate":
                return $this->adminCountriesCreate($type);
                break;
            case "adminCountriesDetail":
                return $this->adminCountriesDetail($type);
                break;
            case "adminCountriesEdit":
                return $this->adminCountriesEdit($type);
                break;
            case "adminCountriesUpdate":
                return $this->adminCountriesUpdate($type);
                break;

            case "adminService":
                return $this->adminService($type);
                break;
            case "adminServiceCreate":
                return $this->adminServiceCreate($type);
                break;
            case "adminServiceDetail":
                return $this->adminServiceDetail($type);
                break;
            case "adminServiceEdit":
                return $this->adminServiceEdit($type);
                break;
            case "adminServiceUpdate":
                return $this->adminServiceUpdate($type);
                break;
            case "adminServiceDeleteDetail":
                return $this->adminServiceDeleteDetail($type);
                break;
            case "adminServiceDeleteSubmit":
                return $this->adminServiceDeleteSubmit($type);
                break;

            case "adminExtraBandwidths":
                return $this->adminExtraBandwidths($type);
                break;
            case "adminExtraBandwidthsCreate":
                return $this->adminExtraBandwidthsCreate($type);
                break;
            case "adminExtraBandwidthsDetail":
                return $this->adminExtraBandwidthsDetail($type);
                break;
            case "adminExtraBandwidthsEdit":
                return $this->adminExtraBandwidthsEdit($type);
                break;
            case "adminExtraBandwidthsUpdate":
                return $this->adminExtraBandwidthsUpdate($type);
                break;
            case "adminExtraBandwidthsDelete":
                return $this->adminExtraBandwidthsDelete($type);
                break;
            case "adminExtraBandwidthsDeleteSubmit":
                return $this->adminExtraBandwidthsDeleteSubmit($type);
                break;

            case "adminCartList":
                return $this->adminCartList($type);
                break;
            case "adminCartCreate":
                return $this->adminCartCreate($type);
                break;
            case "adminCartDetail":
                return $this->adminCartDetail($type);
                break;
            case "adminCartEdit":
                return $this->adminCartEdit($type);
                break;
            case "adminCartUpdate":
                return $this->adminCartUpdate($type);
                break;
            case "adminCartBeCartText":
                return $this->adminCartBeCartText($type);
                break;

            case "adminRejectCartReceipt":
                return $this->adminRejectCartReceipt($type);
                break;
            case "adminConfirmCartReceipt":
                return $this->adminConfirmCartReceipt($type);
                break;

            case "adminInbounds":
                return $this->adminInbounds($type);
                break;
            case "adminInboundList":
                return $this->adminInboundList($type);
                break;
            case "adminPasarGuardGroups":
                return $this->adminPasarGuardGroups($type);
                break;
            case "AdminPGGDA":
                return $this->adminPasarGuardGroupDetail($type);
                break;
            case "adminPasarGuardGroupsEdit":
                return $this->adminPasarGuardGroupsEdit($type);
                break;
            case "adminPasarGuardGroupsUpdate":
                return $this->adminPasarGuardGroupsUpdate($type);
                break;
            case "adminToggleInboundStatus":
                return $this->adminToggleInboundStatus($type);
                break;
            case "adminPGSellChangeStatus":
                return $this->adminPGSellChangeStatus($type);
                break;
            case "adminPGSellChangePercent":
                return $this->adminPGSellChangePercent($type);
                break;

            case "adminChargeAmount":
                return $this->adminChargeAmount($type);
                break;
            case "adminChargeAmountAdd":
                return $this->adminChargeAmountAdd($type);
                break;
            case "adminChargeAmountDelete":
                return $this->adminChargeAmountDelete($type);
                break;

            case "adminOrdersList":
                return $this->adminOrdersList($type);
                break;
            case "adminOrderSearch":
                return $this->adminOrderSearch($type);
                break;
            case "adminOrderSingle":
                return $this->adminOrderSingle($type);
                break;
            case "adminOrderTransactions":
                return $this->adminOrderTransactions($type);
                break;
            case "adminOrderChangeBw":
                return $this->adminOrderChangeBw($type);
                break;
            case "adminOrderChangeTime":
                return $this->adminOrderChangeTime($type);
                break;
            case "adminOrderShowCode":
                return $this->adminOrderShowCode($type);
                break;

            case "ipsvp":
                return $this->ipsvp($type);
                break;
            case "connectAccount":
                return $this->connectAccount($type);
                break;
        }

//        } catch (\Exception $exception) {
//            $this->telData['errors'] = $exception->getMessage() . '-LINE:' . $exception->getLine();
//            $telData = new TelegramData();
//            $telData->data = json_encode($this->telData);
//            $telData->tel_id = $this->chatId;
//            $telData->path = 'error';
//            $telData->types = isset($type) ? json_encode($type) : '';
//            $telData->save();
//        }
    }

    protected function NormalTextAction()
    {

        $telData = new TelegramData();
        $telData->data = json_encode($this->telData);
        $telData->tel_id = $this->chatId;
        $telData->path = $this->text;
        $telData->save();
        $this->method = 'toUser';

        if (str_starts_with($this->text, '/connectAccount')) {
            $parts = preg_split('/\s+/', trim($this->text));
            $code = $parts[1] ?? null;
            return $this->connectAccount(['code' => $code]);
        }

        $setting = Setting::where('key', 'channel-join')->first();
        if (!is_null($setting) && $setting->value == 1) {
            $this->ifUserIsJoined();
            if (!$this->isJoined) {
                return $this->joinFirst();
            }
        }

        switch ($this->text) {
            case '/start':
                if ($this->isAdmin) {
                    return $this->adminMenu(['id' => null]);
                }
                return $this->home();
                break;
            case '/connectAccount':
                return $this->connectAccount();
                break;
            case '/services':
                return $this->services();
                break;
            case "/profile":
                $this->profile();
                break;
            default:
                $this->checkPath();
                break;
        }
    }

    protected function checkPath()
    {
        $user = $this->user;

        switch ($user->path) {
            case 'adminCustomerLoyaltySave': return $this->adminCustomerLoyaltySave();
            case 'adminCustomerDiscountSave': return $this->adminCustomerDiscountSave();
            case 'adminUpdatePanel':
                return $this->adminUpdatePanel();
                break;
            case 'adminUpdatePlan':
                return $this->adminUpdatePlan();
                break;
            case 'adminUserList':
                $type['search'] = $this->text;
                return $this->adminUserList($type);
                break;
            case 'adminOrdersList':
                $type['search'] = $this->text;
                return $this->adminOrdersList($type);
                break;
            case 'adminUserBalanceActionBalance':
                $type['search'] = $this->text;
                return $this->adminUserBalanceActionBalance($type);
                break;
            case 'adminUserSellerDiscountAmount':
                $type['search'] = $this->text;
                return $this->adminUserSellerDiscountAmount($type);
                break;
            case 'adminSettingCommissionTextEdit':
                return $this->adminSettingCommissionTextEdit();
                break;
            case 'adminSettingCommission':
                return $this->adminSettingCommissionEdit();
                break;
            case 'adminCountriesUpdate':
                return $this->adminCountriesUpdate();
                break;
            case 'adminServiceUpdate':
                return $this->adminServiceUpdate();
                break;
            case 'adminExtraBandwidthsUpdate':
                return $this->adminExtraBandwidthsUpdate();
                break;
            case 'adminCartUpdate':
                return $this->adminCartUpdate();
                break;
            case 'adminCartBeCartTextUpdate':
                return $this->adminCartBeCartTextUpdate();
                break;
            case 'adminChangeSettingSubmit':
                $type['value'] = $this->text;
                return $this->adminChangeSettingSubmit($type);
                break;
            case 'clientFinalStep':
                $type['name'] = $this->text;
                return $this->clientFinalStep($type);
                break;
            case 'sendCartBeCartReceipt':
                return $this->paymentSubmitCartBeCartReceipt();
                break;
            case 'clientOrders':
                $type['search'] = $this->text;
                return $this->clientOrders($type);
                break;
            case 'clientChangeConfigNameSubmit':
                $type['name'] = $this->text;
                return $this->clientChangeConfigNameSubmit($type);
                break;
            case 'adminPasarGuardGroupUpdate':
                $type['name'] = $this->text;
                return $this->adminPasarGuardGroupUpdate($type);
                break;
            case 'adminChargeAmountSubmit':
                $type['text'] = $this->text;
                return $this->adminChargeAmountSubmit($type);
                break;
            case 'adminOrderChangeBwSubmit':
                $type['text'] = $this->text;
                return $this->adminOrderChangeBwSubmit($type);
                break;
            case 'adminOrderChangeTimeSubmit':

                $type['text'] = $this->text;
                return $this->adminOrderChangeTimeSubmit($type);
                break;
            case 'addFundCustomAmountSubmit':
                $type['amount'] = $this->text;
                return $this->addFundCustomAmountSubmit($type);
                break;
            case 'disabled':
                return $this->botIsNotActive();
                break;
            case 'adminPGSellChangePercentSubmit':
                return $this->adminPGSellChangePercentSubmit();
                break;
        }
    }

    protected function botIsNotActive()
    {
        $text = headTitle('اطلاعیه');
        $text .= "👋 کاربر گرامی

در حال حاضر امکان ثبت نام کاربر جدید وجود ندارد.

⏳ به محض فعال شدن مجدد ثبت نام، از طریق ربات به شما اطلاع رسانی خواهد شد.

از صبر و شکیبایی شما سپاسگزاریم 🙏";
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function accountIsDisabled()
    {
        $text = headTitle('اطلاعیه');
        $text .= "👋 کاربر گرامی
حساب کاربری شما غیرفعال شده است.";
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function checkChatMember()
    {
        $customData = $this->customData;

        if (
            $customData['oldStatus'] == 'member' &&
            $customData['newStatus'] == 'kicked'
        ) {
            User::where('tel_id', $this->chatId)
                ->update([
                    'status' => -2
                ]);
        }
        if (
            $customData['oldStatus'] == 'kicked' &&
            $customData['newStatus'] == 'member'
        ) {
            User::where('tel_id', $this->chatId)
                ->update([
                    'status' => 1
                ]);
        }
    }


    // Functions
    private function checkUser()
    {
        // In a group/channel callback, chat.id belongs to the conversation while
        // from.id identifies the person who actually pressed the button.
        $telegramUserId = $this->actorId ?? $this->chatId;
        $user = User::where('tel_id', $telegramUserId)->first();
        if (is_null($user)) {
            $setting = Setting::where('key', 'join-bot')->first();

            $path = 'disabled';
            $status = -3;
            if ($setting->value == 1) {
                $path = 'start';
                $status = 1;
            }
            $firstName = null;
            $lastName = null;
            $username = null;

            if (array_key_exists('message', $this->telData)) {
                $firstName = array_key_exists('first_name', $this->telData['message']['from']) ? $this->telData['message']['from']['first_name'] : null;
                $lastName = array_key_exists('last_name', $this->telData['message']['from']) ? $this->telData['message']['from']['last_name'] : null;
                $username = array_key_exists('username', $this->telData['message']['from']) ? $this->telData['message']['from']['username'] : null;
            } elseif (array_key_exists('callback_query', $this->telData)) {
                $callbackUser = $this->telData['callback_query']['from'] ?? [];
                $firstName = $callbackUser['first_name'] ?? null;
                $lastName = $callbackUser['last_name'] ?? null;
                $username = $callbackUser['username'] ?? null;
            }

            $user = new User();
            $user->first_name = $firstName;
            $user->last_Name = $lastName;
            $user->username = $username;
            $user->tel_id = $telegramUserId;
            $user->path = $path;
            $user->balance = 0;
            $user->status = $status;
            $user->save();
        }
        $this->user = $user;
    }

    private function ifUserIsJoined()
    {
        $channel_id = Setting::where('key', 'channel_id')->first();
        if (!is_null($channel_id)) {
            if ($channel_id->value != 0) {
                $data = [
                    'chat_id' => $channel_id->value,
                    'user_id' => $this->chatId,
                ];
                $result = $this->telegramSdk->getChatMember($data);
                $status = $result['result']['status'] ?? null;
                if (!$result['ok']) {
                    return $this->ifUserIsJoined();
                }
                if (in_array($status, ['member', 'administrator', 'creator'])) {
                    return $this->isJoined = true;
                }
            }
        }
        return $this->isJoined = false;
    }

    private function checkUserIsJoined()
    {
        $this->ifUserIsJoined();
        if (!$this->isJoined) {
            return $this->telegramSdk->answerCallback([
                'callback_query_id' => $this->callbackId,
                'text' => "لطفا ابتدا وارد کانال شوید.",
                'show_alert' => true,
                'cache_time' => 1,
            ]);
        }
        $this->method = 'edit';
        $this->updatePath('start');
        return $this->home();
    }

    protected function joinFirst()
    {
        $channel_id = Setting::where('key', 'channel_id')->first();
        $channel_id = str_replace(['https://t.me/', 'http://t.me/', '@'], '', $channel_id->value);

        $text = headTitle('اطلاعیه');
        $text .= "برای استفاده از ربات لطفا وارد کانال شوید";
        $buttons[][] = ['text' => "کانال", 'url' => "https://t.me/$channel_id"];
        $buttons[][] = ['text' => "عضو شدم", 'callback_data' => "type=checkUserIsJoined"];

        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ]),
            'parse_mode' => 'HTML',
        ];
        $this->method = 'toUser';
        return $this->sendMessage($data, 'message');

    }

    private function createInlineKeyboard(array $rows): array
    {
        $keyboard = ['inline_keyboard' => []];

        foreach ($rows as $rowButtons) {

            $row = [];

            foreach ($rowButtons as $button) {

                $btn = [
                    'text' => $button['key']
                ];

                switch ($button['type']) {

                    case 'callback_data':
                        $btn['callback_data'] = $button['data'];
                        break;

                    case 'url':
                        $btn['url'] = $button['data'];
                        break;

                    case 'web_app':
                        $btn['web_app'] = ['url' => $button['data']];
                        break;
                }

                $row[] = $btn;
            }

            $keyboard['inline_keyboard'][] = $row;
        }

        return $keyboard;
    }

    private function sendMessage($data, $messageMethod)
    {
        if (is_null($messageMethod)) {
            $messageMethod = 'message';
        }

        if ($messageMethod == 'message') {
            if ($this->method == 'edit') {
                $data['message_id'] = $this->messageId;
                return $this->telegramSdk->editMessage($data);
            } elseif ($this->method == 'temp') {
                return $this->telegramSdk->sendMessage($data);
            } elseif ($this->method == 'toUser') {
                return $this->telegramSdk->sendMessage($data);
            } else {
                $this->deleteChat();
                return $this->telegramSdk->sendMessage($data);
            }
        } elseif ($messageMethod == 'photo') {
            $this->deleteChat();
            $this->telegramSdk->sendPhoto($data);
        }
    }

    private function sendTemporaryMessage($text)
    {
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        $this->method = 'temp';
        $result = $this->sendMessage($data, 'message');

        if (!empty($result['ok'])) {
            sleep(5);
            $this->messageId = $result['result']['message_id'];
            $this->deleteChat();
        }

        return;
    }

    private function deleteChat()
    {
        $data = [
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId
        ];
        $this->telegramSdk->deleteMessage($data);
    }

    private function escapeHtml(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function cardShebaLine(mixed $sheba): string
    {
        $sheba = trim((string) $sheba);

        return $sheba === ''
            ? ''
            : '🏦 شماره شبا: <code>' . $this->escapeHtml($sheba) . "</code>\n";
    }

    private function cardNumberLine(mixed $cardNumber): string
    {
        $cardNumber = trim((string) $cardNumber);

        return $cardNumber === ''
            ? ''
            : '💳 شماره کارت: <code>' . $this->escapeHtml($cardNumber) . "</code>\n";
    }

    private function paymentOrderRemark(Payment $payment): ?string
    {
        $detail = is_array($payment->detail)
            ? $payment->detail
            : (json_decode((string) $payment->detail, true) ?: []);
        $remark = trim((string) ($detail['remark'] ?? ''));
        if ($remark !== '') {
            return $remark;
        }

        if (!in_array((string) $payment->type, ['2', '3', 'renew', 'extra'], true)) {
            return null;
        }

        return Orders::where('id', $payment->order_id)
            ->where('user_id', $payment->user_id)
            ->value('remark');
    }

    private function orderTransactionsQuery(Orders $order)
    {
        return Payment::query()->forOrderHistory($order);
    }

    private function walletTransactionsQuery(User $user)
    {
        return Payment::query()->forWalletHistory($user);
    }

    private function transactionTypeLabel(Payment $payment): string
    {
        return match ((string) $payment->method) {
            'admin_credit' => 'شارژ دستی کیف پول',
            'admin_debit' => 'کسر دستی از کیف پول',
            default => app(WpSyncService::class)->paymentTypeLabel($payment->type),
        };
    }

    private function transactionStatusMeta(Payment $payment): array
    {
        return match ((string) $payment->status) {
            '1', 'approved' => ['icon' => '✅', 'label' => 'تأیید شده'],
            '0', 'pending' => ['icon' => '⏳', 'label' => 'در انتظار بررسی'],
            '-1', 'rejected' => ['icon' => '❌', 'label' => 'رد شده'],
            '-2', 'refunded' => ['icon' => '↩️', 'label' => 'برگشت به کیف پول'],
            default => ['icon' => 'ℹ️', 'label' => (string) $payment->status],
        };
    }

    private function transactionSelectionLabel(Payment $payment): ?string
    {
        $detail = is_array($payment->detail)
            ? $payment->detail
            : (json_decode((string) $payment->detail, true) ?: []);

        $planId = (int) ($detail['plan-id'] ?? 0);
        if ($planId > 0) {
            $plan = Plans::find($planId);
            if ($plan) {
                return "تعرفه: {$plan->name} | {$plan->bandwidth} گیگ | {$plan->days} روز";
            }

            return "شناسه تعرفه: {$planId}";
        }

        $extraId = (int) ($detail['extra-id'] ?? 0);
        if ($extraId > 0) {
            $extra = ExtraBandwidth::find($extraId);

            return $extra ? "حجم افزوده: {$extra->name} گیگ" : "شناسه بسته حجم: {$extraId}";
        }

        $note = trim((string) ($detail['note'] ?? ''));

        return $note !== '' ? "توضیح: {$note}" : null;
    }

    private function isWalletDebit(Payment $payment): bool
    {
        if ((string) $payment->status === '-2') {
            return false;
        }

        if ((string) $payment->method === 'admin_debit') {
            return true;
        }

        return (string) $payment->method === 'wallet'
            && !in_array((string) $payment->type, ['4', 'wallet', 'wallet_charge', 'admin_credit'], true);
    }

    private function refundPaymentToWallet(Payment $payment, User $targetUser, string $reason): bool
    {
        return DB::transaction(function () use ($payment, $targetUser, $reason) {
            $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->first();
            $lockedUser = User::where('id', $targetUser->id)->lockForUpdate()->first();
            if (
                !$lockedPayment
                || !$lockedUser
                || (int) $lockedPayment->user_id !== (int) $lockedUser->id
                || (string) $lockedPayment->status !== '1'
            ) {
                return false;
            }

            $before = (int) $lockedUser->balance;
            $lockedUser->balance = $before + (int) $lockedPayment->price;
            $lockedUser->save();

            $detail = is_array($lockedPayment->detail) ? $lockedPayment->detail : [];
            $detail['refund_reason'] = $reason;
            $detail['refunded_at'] = now()->toDateTimeString();
            $detail['wallet_balance_before_refund'] = $before;
            $detail['wallet_balance_after_refund'] = (int) $lockedUser->balance;
            $lockedPayment->status = -2;
            $lockedPayment->detail = $detail;
            $lockedPayment->save();

            return true;
        });
    }

    private function formatTransactionEntry(Payment $payment, bool $admin = false, bool $wallet = false): string
    {
        $detail = is_array($payment->detail)
            ? $payment->detail
            : (json_decode((string) $payment->detail, true) ?: []);
        $status = $this->transactionStatusMeta($payment);
        $type = $this->escapeHtml($this->transactionTypeLabel($payment));
        $method = $this->escapeHtml(app(WpSyncService::class)->paymentMethodLabel($payment->method));
        $amountPrefix = $wallet ? ($this->isWalletDebit($payment) ? '➖' : '➕') : '💰';
        $createdAt = $payment->created_at?->format('Y-m-d H:i') ?? 'نامشخص';
        $updatedAt = $payment->updated_at?->format('Y-m-d H:i') ?? 'نامشخص';
        $remark = $this->paymentOrderRemark($payment);
        $selection = $this->transactionSelectionLabel($payment);

        $text = "{$status['icon']} <b>تراکنش #{$payment->id}</b> — {$type}\n";
        if ($remark !== null) {
            $text .= "🏷 ریمارک: <code>" . $this->escapeHtml($remark) . "</code>\n";
        }
        $text .= "{$amountPrefix} مبلغ: <code>" . number_format((float) $payment->price) . "</code> تومان\n";
        $text .= "💳 روش: {$method}\n";
        $text .= "📌 وضعیت: {$status['label']}\n";
        if ($selection !== null) {
            $text .= "🧾 " . $this->escapeHtml($selection) . "\n";
        }

        if ($admin) {
            $text .= "👤 شناسه کاربر: <code>{$payment->user_id}</code>\n";
            $text .= "📦 شناسه مرجع سفارش: <code>" . ((int) $payment->order_id ?: '—') . "</code>\n";
            $text .= "👨‍💻 شناسه ادمین: <code>" . ($payment->admin_id ?: '—') . "</code>\n";
            $text .= "🔖 کد مرجع: <code>" . $this->escapeHtml($payment->ref_id ?: '—') . "</code>\n";
            if (!empty($detail['cart-number'])) {
                $text .= $this->cardNumberLine($detail['cart-number']);
                $text .= $this->cardShebaLine($detail['cart-sheba'] ?? null);
            }
            if (array_key_exists('wallet_balance_before', $detail) || array_key_exists('wallet_balance_after', $detail)) {
                $text .= "💼 موجودی: <code>" . number_format((float) ($detail['wallet_balance_before'] ?? 0))
                    . "</code> ← <code>" . number_format((float) ($detail['wallet_balance_after'] ?? 0)) . "</code>\n";
            }
            if (array_key_exists('wallet_balance_before_refund', $detail) || array_key_exists('wallet_balance_after_refund', $detail)) {
                $text .= "↩️ موجودی پس از برگشت: <code>" . number_format((float) ($detail['wallet_balance_before_refund'] ?? 0))
                    . "</code> ← <code>" . number_format((float) ($detail['wallet_balance_after_refund'] ?? 0)) . "</code>\n";
            }
            if ($payment->expired_at) {
                $text .= "⌛ انقضای پرداخت: <code>{$payment->expired_at->format('Y-m-d H:i')}</code>\n";
            }
            $text .= "🕒 ایجاد: <code>{$createdAt}</code> | بروزرسانی: <code>{$updatedAt}</code>\n";
        } else {
            $text .= "🕒 تاریخ: <code>{$createdAt}</code>\n";
        }

        return $text;
    }

    private function sendTransactionsPage($payments, string $title, string $callback, string $backCallback, bool $admin = false, bool $wallet = false)
    {
        $text = headTitle($title);
        if ($payments->isEmpty()) {
            $text .= "تراکنشی برای نمایش وجود ندارد.";
        } else {
            foreach ($payments as $payment) {
                $text .= $this->formatTransactionEntry($payment, $admin, $wallet) . "\n━━━━━━━━━━━━━━━\n";
            }
        }

        $keyboard = [];
        $pagination = $this->paginationFooterButton($payments, $payments->currentPage(), $callback);
        if (!empty($pagination)) {
            $keyboard[] = $pagination;
        }
        $keyboard[] = [[
            'text' => '🔙 بازگشت',
            'callback_data' => $backCallback,
        ]];

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ], 'message');
    }

    private function denyAdminAccess()
    {
        if (!$this->callbackId) {
            return $this->telegramSdk->sendMessage([
                'chat_id' => $this->chatId,
                'text' => 'دسترسی غیرمجاز است.',
            ]);
        }

        return $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => 'دسترسی غیرمجاز است.',
            'show_alert' => true,
            'cache_time' => 1,
        ]);
    }

    private function countryToFlag($flag)
    {
        $countryCode = strtoupper($flag);
        return mb_chr(ord($countryCode[0]) + 127397)
            . mb_chr(ord($countryCode[1]) + 127397);
    }

    private function updatePath($path)
    {
        $user = $this->user;
        $user->path = $path;
        $user->save();
    }

    private function adminFooterButtons($path = null)
    {
        if ($path == null) {

            return [
                [
                    'text' => '🔙 بازگشت',
                    'callback_data' => 'type=admin-home',
                    'style' => 'danger'
                ],
            ];

        } else {

            return [
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=admin-home',
                    'style' => 'primary'
                ],
                [
                    'text' => '🔙 بازگشت',
                    'callback_data' => $path,
                    'style' => 'danger'
                ],
            ];
        }
    }

    private function ignore()
    {
        return $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => "دکمه اشتباهی رو کلیک کردی!",
            'show_alert' => true,
            'cache_time' => 1,
        ]);
    }

    private function clientFooterButtons($path = null)
    {
        if ($path == null) {

            return [
                [
                    'text' => '🔙 بازگشت',
                    'callback_data' => 'type=home',
//                    'style' => 'danger'
                ],
            ];

        } else {

            return [
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=home',
//                    'style' => 'primary'
                ],
                [
                    'text' => '🔙 بازگشت',
                    'callback_data' => $path,
//                    'style' => 'danger'
                ],
            ];
        }
    }

    private function paginationFooterButton($list, $page, $type)
    {
        $pagination = [];

        if ($list->lastPage() > 1) {

            $nextCallback = $prevCallback = 'ignore';
            $nextCallbackStyle = $prevCallbackStyle = 'danger';

            if ($list->currentPage() > 1) {
                $prevCallback = "type=$type|page=" . ($page - 1);
                $prevCallbackStyle = '';

            }

            $pagination[] = [
                'text' => '⬅️ قبلی',
                'callback_data' => $prevCallback,
                'style' => $prevCallbackStyle
            ];

            $pagination[] = [
                'text' => "📄 {$list->currentPage()} / {$list->lastPage()}",
                'callback_data' => 'ignore',
                'style' => 'primary'
            ];

            if ($list->hasMorePages()) {
                $nextCallback = "type=$type|page=" . ($page + 1);
                $nextCallbackStyle = '';
            }

            $pagination[] = [
                'text' => 'بعدی ➡️',
                'callback_data' => $nextCallback,
                'style' => $nextCallbackStyle
            ];

            return $pagination;
        }
    }

    protected function home($type = null)
    {
        $user = $this->user;
        $homePage = Setting::where('key', 'home-page')->first();
        $supportId = Setting::where('key', 'support_id')->first();

        $buttons[][] = ['text' => "📦 خرید سرویس", 'callback_data' => 'type=clientService', 'style' => 'success'];
        $buttons[] = [
            ['text' => "📑 سفارشات من", 'callback_data' => 'type=clientOrders'],
            ['text' => "💰 شارژ کیف پول", 'callback_data' => 'type=addFund'],
        ];

        $setting = Setting::where('key', 'channel-join')->first();
        if (!is_null($setting) && $setting->value == 1) {
            $this->ifUserIsJoined();
            if (!$this->isJoined) {
                return $this->joinFirst();
            }
        }
        if (!is_null($supportId) && !is_null($supportId->value)) {
            $buttons[] = [
                ['text' => "👤 حساب کاربری", 'callback_data' => 'type=profile'],
                ['text' => "🌐 پشتیبانی", 'url' => "https://t.me/$supportId->value"],
            ];
        } else {
            $buttons[] = [
                ['text' => "👤 حساب کاربری", 'callback_data' => 'type=profile'],
            ];
        }


        if ($user->is_admin) {
            $buttons[] = [
                ['text' => "پنل مدیریت", 'callback_data' => 'type=admin-home'],
            ];
        }

        if (!is_null($homePage) && !is_null($homePage->value)) {
            $text = $homePage->value;
        } else {
            $text = "🚀 به ربات ما خوش آمدید

📱 یک پلتفرم کامل برای خرید و مدیریت کانفیگ‌های VPN با سرعت بالا و دسترسی امن به اینترنت آزاد

✨ خدمات ما:
• 🔐 کانفیگ VPN پرسرعت (V2Ray)
• 🌍 سرورهای متنوع از کشورهای مختلف
• ⚡ اتصال پایدار و بدون قطعی
• 📱 مناسب برای موبایل، ویندوز و مک
• 🔄 تحویل آنی پس از خرید
• 💰 قیمت مناسب و مقرون‌به‌صرفه
• 📊 پنل مدیریت سفارش‌ها
• 🔒 امنیت بالا و حفظ حریم خصوصی

🔐 چرا ربات ما؟
سریع • امن • پایدار • پشتیبانی ۲۴ ساعته • تحویل فوری

👉 برای شروع، یکی از سرویس‌های زیر را انتخاب کنید:";
        }
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function addFund($data)
    {
        $setting = Setting::where('key', 'channel-join')->first();
        if (!is_null($setting) && $setting->value == 1) {
            $this->ifUserIsJoined();
            if (!$this->isJoined) {
                return $this->joinFirst();
            }
        }

        $cartBeCart = Setting::where('key', 'cart_be_cart')->first();
        $gateway = Setting::where('key', 'gateway')->first();

        if (!is_null($gateway) && $gateway->value == 1) {
            $buttons[][] = ['text' => "📦 درگاه", 'callback_data' => 'type=addFundStepOne|value=Online'];
        }

        if (!is_null($cartBeCart) && $cartBeCart->value == 1) {
            $buttons[][] = ['text' => "📦 کارت به کارت", 'callback_data' => 'type=addFundStepOne|value=Cart'];
        }

        $buttons[][] = ['text' => "📋 لیست تراکنش‌ها", 'callback_data' => 'type=clientWalletTransactions'];
        $buttons[][] = ['text' => "برگشت", 'callback_data' => 'type=home',];

        $balance = number_format($this->user->balance);
        $data = [
            'chat_id' => $this->chatId,
            'text' => "شارژ کیف پول \n موجودی کیف پول: {$balance}",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function addFundStepOne($data)
    {
        $method = $data['value'];
        if (!in_array($method, ['Online', 'Cart'], true)) {
            return $this->sendTemporaryMessage('روش پرداخت انتخاب‌شده فعلاً فعال نیست.');
        }

        $setting = Setting::where('key', 'charge_amount')->first();
        if (!is_null($setting) && !empty($setting->value)) {

            $amounts = explode(',', $setting->value);

            $row = [];

            foreach ($amounts as $key => $amount) {

                $amount = number_format(trim($amount));

                if ($amount === '') {
                    continue;
                }

                $row[] = [
                    'text' => "💰 {$amount} T",
                    'callback_data' => "type=addFundStepTwo|amount={$amount}|key=$method",
                ];

                // دو ستون در هر ردیف
                if (count($row) == 2) {
                    $keyboard['inline_keyboard'][] = $row;
                    $row = [];
                }
            }

            // اگر تعداد فرد بود
            if (!empty($row)) {
                $keyboard['inline_keyboard'][] = $row;
            }
        }
        $keyboard['inline_keyboard'][][] = [
            'text' => "💰 مبلغ دلخواه",
            'callback_data' => "type=addFundCustomAmount|key=$method",
        ];
        $keyboard['inline_keyboard'][] = $this->clientFooterButtons("type=addFund");

        $data = [
            'chat_id' => $this->chatId,
            'text' => "شارژ کیف پول",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function addFundStepTwo($data)
    {
        $key = $data['key'];
        $amount = $data['amount'];

        switch ($key) {
            case "Online":
                return $this->addFundOnline($data);
                break;
            case "Cart":
                return $this->addFundCart($data);
                break;
            default:
                return $this->sendTemporaryMessage('روش پرداخت انتخاب‌شده فعلاً فعال نیست.');
        }
    }

    protected function addFundCustomAmount($data)
    {
        $key = $data['key'];
        if (!in_array($key, ['Online', 'Cart'], true)) {
            return $this->sendTemporaryMessage('روش پرداخت انتخاب‌شده فعلاً فعال نیست.');
        }

        $user = $this->user;
        $tel_detail = $user->tel_detail;
        $tel_detail['add-fund-method'] = $key;
        $user->tel_detail = $tel_detail;
        $user->save();

        $this->updatePath('addFundCustomAmountSubmit');

        $keyboard['inline_keyboard'][] = $this->clientFooterButtons("type=addFund");

        $data = [
            'chat_id' => $this->chatId,
            'text' => "لطفا مبلغ مورد نظر را به تومان وارد کنید.",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function addFundCustomAmountSubmit($data)
    {
        $validate = Validator::make([
            'amount' => $this->text
        ], [
            'amount' => ['required', 'numeric', 'min:1000'],
        ], [
            'amount.required' => '❌ لطفا مقدار تخفیف را وارد کنید.',
            'amount.numeric' => '❌ مقدار باید عددی باشد.',
            'amount.min' => '❌ حداقل مقدار 1000 است.',
        ]);

        if ($validate->fails()) {
            return $this->sendTemporaryMessage($validate->errors()->first());
        }

        $user = $this->user;
        $data['key'] = $user->tel_detail['add-fund-method'];
        $data['amount'] = $this->text;

        return $this->addFundStepTwo($data);

    }

    private function addFundOnline($data)
    {

    }

    private function addFundCart($data)
    {
        $this->updatePath('sendCartBeCartReceipt');

        $amount = $data['amount'];
        $random = Setting::where('key', 'cart_be_cart_random')->first();
        $support = Setting::where('key', 'support_id')->first();
        $isRandom = false;
        if (!is_null($random) && $random->value == 1) {
            $isRandom = true;
        }

        if ($isRandom) {
            $ids = Carts::where('status', 1)
                ->orderBy('id', 'desc')
                ->limit(100)
                ->pluck('id');

            $card = Carts::find($ids->random());
        } else {
            $card = Carts::orderby('id', 'desc')
                ->where('status', 1)
                ->where('is_default', 1)
                ->first();
        }
        if (is_null($card)) {
            $card = Carts::orderby('id', 'desc')
                ->where('status', 1)
                ->first();
        }
        $user = $this->user;
        $amount = (int)str_replace(',', '', $amount);

        $payment = new Payment();
        $payment->user_id = $user->id;
        $payment->order_id = 0;
        $payment->price = $amount;
        $payment->status = 0;
        $payment->type = 4;
        $payment->expired_at = Carbon::now();
        $payment->detail = [
            'cart-number' => $card->cart,
            'cart-sheba' => $card->sheba,
            'cart-name' => $card->name,
        ];
        $payment->save();

        $user = $this->user;
        $tel_detail = $user->tel_detail;
        $tel_detail['payment-id'] = $payment->id;
        $tel_detail['payment-type'] = 'cart-be-cart';
        $tel_detail['payment-cart-number'] = $card->cart;
        $tel_detail['payment-cart-sheba'] = $card->sheba;
        $tel_detail['payment-cart-name'] = $card->name;

        $user->tel_detail = $tel_detail;
        $user->save();


        $rialAmount = number_format($amount * 10);
        $amount = number_format($amount);
        $cardNumberLine = $this->cardNumberLine($card->cart);
        $cardShebaLine = $this->cardShebaLine($card->sheba);
        $text = "🟩درخواست  شما ثبت شد!

👝 مبلغ سفارش : <code>{$amount}</code> تومان
🔘 جهت تکمیل سفارش مبلغ فاکتور را به تومان به شماره کارت زیر واریز نموده و پس از واریز تصویر فیش را در همین مرحله برای ربات ارسال نمایید :

{$cardNumberLine}{$cardShebaLine}👤 به نام {$card->name}

در هنگام انتقال، از نوشتن توضیحات انتقال حاوی کلمات یا عبارات حساس مانند «وی‌پی‌ان vpn»، «کانفیگ»، «ویتوری»، «آی‌پی ثابت» و مشابه آن خودداری کنید
درصورت درج هر یک از این کلمات، حساب شما مسدود شده و کیف‌پول شارژ نخواهد شد

⚠️لطفا برای هربار انتقال به شماره کارت ها دقت کنید زیرا ممکن است تغییر کرده باشند
📸پس از واریز وجه، عکس رسید پرداختی خود را در همین قسمت ارسال کنید تا تراکنش شما بررسی و سپس در صورت صحت اطلاعات حساب کاربری تان شارژ شود
🚫تا قبل از ارسال رسید پرداختی از دکمه منو و یا بازگشت استفاده نکنید
⏳زمان بررسی تراکنش های کارت به کارت، بین 5 تا 30 دقیقه خواهد بود

توجه: لطفا از برنامه آپ برای انتقال استفاده نکنید، محدودیت داره

چنانچه در پرداخت خود با مشکل مواجه شدید، به پشتیبانی مراجعه کنید :
📞 @{$support->value}";

        $buttons[] = [
            [
                'text' => '📤 ارسال رسید',
                'callback_data' => "type=paymentSendReceipt|id={$payment->id}"
            ]
        ];
        $buttons[] = $this->clientFooterButtons("type=home");

        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];
        return $this->sendMessage($data, 'message');

    }

    private function addFundFinal($data)
    {
        $id = $data['id'];
        $payment = Payment::find($id);
        if (!$payment || (string) $payment->type !== '4' || (string) $payment->status !== '1') {
            return $this->sendTemporaryMessage('تراکنش شارژ کیف پول یافت نشد.');
        }

        $targetUser = DB::transaction(function () use ($payment) {
            $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->first();
            if (!$lockedPayment) {
                return null;
            }
            $lockedUser = User::where('id', $lockedPayment->user_id)->lockForUpdate()->first();
            if (!$lockedUser) {
                return null;
            }

            $detail = is_array($lockedPayment->detail) ? $lockedPayment->detail : [];
            if (!empty($detail['wallet_credit_applied'])) {
                return $lockedUser;
            }

            $before = (int) $lockedUser->balance;
            $lockedUser->balance = $before + (int) $lockedPayment->price;
            $lockedUser->save();

            $detail['wallet_balance_before'] = $before;
            $detail['wallet_balance_after'] = (int) $lockedUser->balance;
            $detail['wallet_credit_applied'] = true;
            $lockedPayment->detail = $detail;
            $lockedPayment->save();

            return $lockedUser;
        });
        if (!$targetUser) {
            return $this->sendTemporaryMessage('کاربر تراکنش شارژ کیف پول یافت نشد.');
        }
        $payment->refresh();

        $transactionChannel = Setting::where('key', 'cart_be_cart_id')->first();
        $channelId = (!is_null($transactionChannel) && !empty($transactionChannel->value))
            ? $transactionChannel->value
            : optional(User::where('is_admin', 1)->first())->tel_id;

        if (!$channelId) {
            return $this->sendTemporaryMessage('❌ مقصد ارسال رسید یافت نشد.');
        }

        $targetUserName = $targetUser->username
            ? "@{$targetUser->username}"
            : ($targetUser->first_name ?? 'بدون نام');

        $price = number_format($payment->price);

        if ($payment->method == 'cart-be-cart') {

            $adminUserName = $this->user->username
                ? "@{$this->user->username}"
                : ($this->user->first_name ?? 'ادمین');

            $caption = "✅ <b>تراکنش تایید شد</b>\n\n";
            $caption .= "━━━━━━━━━━━━━━━\n";
            $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
            $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
            $caption .= "━━━━━━━━━━━━━━━\n";
            $caption .= "💳 <b>جزئیات پرداخت</b>\n";
            $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
            $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
            $caption .= "💰 <b>نوع پرداخت:</b> کارت به کارت \n";
            $caption .= "━━━━━━━━━━━━━━━\n";
            $caption .= "👨‍💻 <b>تایید شده توسط:</b> {$adminUserName}\n";
            $caption .= "📌 <b>وضعیت:</b> موفق\n";
            $adminMethod = 'edit';

            $balance = number_format($targetUser->balance);
            $userCaption = "✅ تراکنش شارژ کیف پول شما با موفقیت تایید شد.\n💰 موجودی فعلی شما: {$balance} تومان";
        }

        if ($this->isPhoto) {
            $this->telegramSdk->editCaption([
                'chat_id' => $channelId,
                'message_id' => $this->messageId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ]);
        } else {
            $this->method = $adminMethod;
            $this->sendMessage([
                'chat_id' => $channelId,
                'text' => $caption,
                'parse_mode' => 'HTML',
            ], 'message');
        }

        $this->method = 'toUser';
        $this->sendMessage([
            'chat_id' => $targetUser->tel_id,
            'text' => $userCaption,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '📄خرید',
                            'callback_data' => "type=clientService",
                        ]
                    ]
                ]
            ]),

            'parse_mode' => 'HTML',
        ], 'message');
    }

    protected function connectAccount($data = [])
    {
        $code = $data['code'] ?? null;
        if (!$code) {
            $text = headTitle('اتصال حساب سایت به ربات');
            $text .= "برای اتصال حساب، وارد پنل کاربری سایت شوید، از بخش اطلاعات کاربری کد اتصال بگیرید و بعد این دستور را داخل ربات ارسال کنید:\n\n<code>/connectAccount 123456</code>";
            return $this->sendMessage([
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ], 'message');
        }

        try {
            $sync = app(WpSyncService::class);
            $result = $sync->confirmWordPressLink($this->user, (string) $code);
            if (empty($result['ok'])) {
                return $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => '❌ اتصال حساب انجام نشد: ' . ($result['message'] ?? 'کد نامعتبر است.'),
                    'parse_mode' => 'HTML',
                ], 'message');
            }

            $text = "✅ حساب سایت و ربات با موفقیت متصل شد.\n\n";
            $text .= "📱 شماره سایت: <code>{$result['phone']}</code>\n";
            $text .= "📦 سفارش های سایت وارد ربات شد: <code>{$result['orders_count']}</code> مورد\n\n";
            $text .= "از این به بعد سفارش های سایت و ربات و کیف پول بین هر دو بخش قابل مدیریت است.";

            return $this->sendMessage([
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '📑 سفارشات من', 'callback_data' => 'type=clientOrders'],
                            ['text' => '💰 کیف پول', 'callback_data' => 'type=addFund'],
                        ],
                    ],
                ]),
            ], 'message');
        } catch (\Throwable $e) {
            Log::error('connectAccount failed', ['message' => $e->getMessage(), 'tel_id' => $this->chatId]);
            return $this->sendMessage([
                'chat_id' => $this->chatId,
                'text' => '❌ خطا در اتصال حساب. لطفا چند دقیقه بعد دوباره تلاش کنید.',
                'parse_mode' => 'HTML',
            ], 'message');
        }
    }

    /**
     * Client Area
     */

    protected function clientService($type)
    {
        $page = $type['page'] ?? 1;

        $setting = Setting::where('key', 'channel-join')->first();
        if (!is_null($setting) && $setting->value == 1) {
            $this->ifUserIsJoined();
            if (!$this->isJoined) {
                return $this->joinFirst();
            }
        }

        $plan = Plans::where('type', '!=', null)->where('status', 1)->pluck('type')->toArray();
        $panel = Panels::where('panel_type', '!=', null)->where('status', 1)->pluck('type')->toArray();

        $serviceId = array_values(array_unique(array_merge(
            $plan,
            $panel
        )));

        $list = Service::orderByDesc('id')
            ->wherein('id', $serviceId)
            ->paginate(10, ['*'], 'page', $page);

        $text = headTitle("انتخاب سرویس ");
        $text .= "💡 لطفاً یکی از سرویس زیر را انتخاب کنید:";

        $keyboard = [];
        $row = [];

        foreach ($list as $country) {
            $name = !is_null($country->name) ? $country->name : 'بدون نام';
            $row[] = [
                'text' => "{$name}",
                'callback_data' => "type=clientSelectCountry|s_id={$country->id}",
            ];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        $pagination = $this->paginationFooterButton($list, $page, 'clientService');
        if (!is_null($pagination)) {
            $keyboard[] = $pagination;
        }

        $keyboard[] = $this->clientFooterButtons();

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function clientSelectCountry($type)
    {
        $service_id = $type['s_id'];
        $page = $type['page'] ?? 1;

        $user = $this->user;
        $telDetail = $user->tel_detail;
        $telDetail['order-pasarguard-id'] = 0;
        $user->tel_detail = $telDetail;
        $user->save();

        $service = Service::find($service_id);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس مورد نظر یافت نشد');
        }
        $panelIds = Panels::where('status', 1)
            ->where('panel_type', $service_id)
            ->pluck('id')
            ->toArray();

        $inboundCountryIds = Inbounds::whereIn('panel_id', $panelIds)
            ->whereNotNull('country_id')
            ->distinct()
            ->pluck('country_id')
            ->toArray();

        $panelCountryIds = Panels::where('status', 1)
            ->where('panel_type', $service_id)
            ->whereNotNull('country_id')
            ->distinct()
            ->pluck('country_id')
            ->toArray();

        $planCountryIds = array_values(array_unique(array_merge(
            $panelCountryIds,
            $inboundCountryIds
        )));

        $pasarguard = Panels::where('status', 1)
            ->where('panel_type', $service_id)
            ->where('system_type', 'pasarguard')
            ->where('status', 1)
            ->first();

        $list = Countries::where('type', $service_id)
            ->where('status', 1)
            ->whereIn('id', $planCountryIds)
            ->orderBy('name')
            ->paginate(10);


        $text = headTitle("🌍انتخاب کشور ");
        $text .= "📦 <b>نوع سرویس:</b>
<code>{$service->name}</code>

💡 لطفاً یکی از کشور زیر را انتخاب کنید:
";


        $keyboard = [];
        $row = [];

        if (count($list) > 0) {
            foreach ($list as $country) {
                $name = !is_null($country->name) ? $country->name : 'بدون نام';
                $row[] = [
                    'text' => "{$name}",
                    'callback_data' => "type=clientSelectPlan|s_id={$service->id}|co_id={$country->id}",
                ];
                if (count($row) === 2) {
                    $keyboard[] = $row;
                    $row = [];
                }
            }

            if (!empty($row)) {
                $keyboard[] = $row;
            }
        }

        if (!is_null($pasarguard)) {
            $pasarguardDetail = $pasarguard->detail;
            if (array_key_exists('status', $pasarguardDetail) && $pasarguardDetail['status'] == 1) {
                $keyboard[][] = [
                    'text' => "🌍 همه کشور ها",
                    'callback_data' => "type=clientSelectPlan|s_id={$service->id}|co_id=0|p_id={$pasarguard->id}",
                ];
            }
            $text .= "
گزینه همه کشورها:
با انتخاب این نوع سفارش شما دسترسی برای اتصال با تمام کشور ها رو بصورت لینک ساب اسکریپشن دارید.";
        }


        $pagination = $this->paginationFooterButton($list, $page, "clientSelectCountry|si_id=$service_id");

        if (!is_null($pagination)) {
            $keyboard[] = $pagination;
        }

        $keyboard[] = $this->clientFooterButtons("type=clientService");
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function clientSelectPlan($type)
    {
        $service_id = $type['s_id'];
        $country_id = $type['co_id'];
        $pasarGuard_id = $type['p_id'] ?? 0;
        $page = $type['page'] ?? 1;

        $service = Service::find($service_id);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس مورد نظر یافت نشد');
        }

        $country = Countries::find($country_id);
        if (is_null($country) && $pasarGuard_id == 0) {
            return $this->sendTemporaryMessage('کشور مورد نظر یافت نشد');
        }
        if ($pasarGuard_id != 0) {
            $countryName = 'همه کشور ها';
        } else {
            $countryName = $country->name;
        }

        $list = Plans::where('type', $service_id)->where('status', 1)->orderby('id')->paginate(10);

        $text = headTitle("🌍انتخاب تعرفه سرویس");
        $text .= "📦 <b>نوع سرویس:</b>
<code>{$service->name}</code>
🌐 <b>کشور:</b>
<code>{$countryName}</code>
💡 لطفاً یکی از تعرفه‌های زیر را انتخاب کنید:";

        $keyboard = [];
        $row = [];

        if (count($list) > 0) {
            $pasarguardPercent = 0;
            if ($pasarGuard_id != 0) {
                $pasarguard = Panels::find($pasarGuard_id);
                $pasarguardDetail = $pasarguard->detail;
                $pasarguardPercent = $pasarguardDetail['percent'];
            }


            foreach ($list as $item) {
                $name = !is_null($item->name) ? $item->name : 'بدون نام';

                if ($pasarguardPercent != 0) {
                    $basePrice = $item->price;
                    $percentPrice = ($basePrice / 100) * $pasarguardPercent;
                    $planPrice = $basePrice + $percentPrice;
                    if ($item->discount != 0) {
                        $discount = ($planPrice / 100) * $item->discount;
                        $planPrice = $planPrice - $discount;
                    }
                    $price = number_format($planPrice);

                } else {
                    $planPrice = calculatePlanDiscount($item)['price'];
                    $price = number_format($planPrice);
                }


                $discount = "";
                if ($item->discount > 0) {
                    $discount = "| تخفیف: {$item->discount}%";
                }
                try {
                    $price = number_format(app(CustomerLoyaltyService::class)->quote($this->user, (int) $service->id, $planPrice)['amount']);
                } catch (\RuntimeException $e) {
                    return $this->sendTemporaryMessage($e->getMessage());
                }
                $keyboard[] = [
                    [
                        'text' => "{$name} | $price T $discount",
                        'callback_data' => "type=clientSelectCount|s_id={$service->id}|co_id={$country?->id}|pl_id={$item->id}|p_id={$pasarGuard_id}",
                    ],
                ];
            }
        }

        $pagination = $this->paginationFooterButton($list, $page, "clientSelectPlan|s_id=$service_id");

        if (!is_null($pagination)) {
            $keyboard[] = $pagination;
        }

        $keyboard[] = $this->clientFooterButtons("type=clientSelectCountry|s_id=$service_id");
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');

    }

    protected function clientSelectCount($type)
    {
        $service_id = $type['s_id'];
        $country_id = $type['co_id'];
        $plan_id = $type['pl_id'];
        $pasarGuard_id = $type['p_id'] ?? null;
        $count = $type['cu'] ?? 1;

        if ($count <= 0) {
            return $this->telegramSdk->answerCallback([
                'callback_query_id' => $this->callbackId,
                'text' => "حداقل خرید یک عدد می باشد.",
                'show_alert' => true,
                'cache_time' => 1,
            ]);
        }

        $service = Service::find($service_id);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس مورد نظر یافت نشد');
        }

        $country = Countries::find($country_id);
        if (is_null($country) && $pasarGuard_id == 0) {
            return $this->sendTemporaryMessage('کشور مورد نظر یافت نشد');
        }
        if ($pasarGuard_id != 0) {
            $countryName = 'همه کشور ها';
        } else {
            $countryName = $country->name;
        }

        $plan = Plans::find($plan_id);
        if (is_null($plan)) {
            return $this->sendTemporaryMessage('تعرفه مورد نظر یافت نشد');
        }
        $text = headTitle("🌍انتخاب تعداد");
        $text .= "📦 <b>نوع سرویس:</b>
<code>{$service->name}</code>
🌐 <b>کشور:</b>
<code>{$countryName}</code>
🌐 <b>تعرفه:</b>
<code>{$plan->name} | حجم: {$plan->bandwidth} GB</code>
💡 لطفاً تعداد را مشخص کنید:";

        $decrement = max(1, $count - 1);
        $increment = min(10, $count + 1);

        $keyboard[] = [
            [
                'text' => '➖',
                'callback_data' => $count <= 1
                    ? 'ignore'
                    : "type=CSC|s_id={$service_id}|co_id={$country?->id}|pl_id={$plan_id}|cu={$decrement}|p_id={$pasarGuard_id}",
                'style' => 'danger',
            ],
            [
                'text' => "🛒 {$count}",
                'callback_data' => "ignore",
            ],
            [
                'text' => '➕',
                'callback_data' => $count >= 10
                    ? 'ignore'
                    : "type=CSC|s_id={$service_id}|co_id={$country?->id}|pl_id={$plan_id}|cu={$increment}|p_id={$pasarGuard_id}",
                'style' => 'success',
            ],
        ];

        $keyboard[] = [
            [
                'text' => 'مرحله بعد',
                'callback_data' => "type=CLN|s_id={$service_id}|co_id={$country?->id}|pl_id={$plan_id}|cu={$count}|p_id={$pasarGuard_id}",
            ],
            [
                'text' => '🔙 بازگشت',
                'callback_data' => "type=clientSelectPlan|s_id=$service_id|co_id=$country_id|p_id={$pasarGuard_id}",
            ],
        ];
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function clientSelectName($type)
    {
        $service_id = $type['s_id'];
        $country_id = $type['co_id'];
        $plan_id = $type['pl_id'];
        $pasarGuard_id = $type['p_id'] ?? 0;
        $count = $type['cu'] ?? 1;

        $user = $this->user;

        $tel_detail = $user->tel_detail;

        $tel_detail['order-service-id'] = $service_id;
        $tel_detail['order-country-id'] = $country_id;
        $tel_detail['order-plan-id'] = $plan_id;
        $tel_detail['order-pasarguard-id'] = $pasarGuard_id;
        $tel_detail['order-extra'] = null;
        $tel_detail['order-count'] = $count;

        $user->tel_detail = $tel_detail;
        $user->save();

        $this->updatePath('clientFinalStep');

        $text = headTitle("🌍انتخاب نام کانفیگ");
        $text .= "💡 لطفاً نام کانفیگ خود را وارد کنید در غیر اینصورت بر روی دکمه انتخاب خودکار کلیک کنید";


        $keyboard[] = [
            [
                'text' => 'انتخاب خودکار',
                'callback_data' => "type=clientFinalStep|name=random",
            ],
        ];
        $keyboard[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => "type=clientSelectCount|s_id={$service_id}|co_id={$country_id}|pl_id={$plan_id}|cu={$count}|p_id={$pasarGuard_id}",
            ],
        ];;
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function clientFinalStep($type)
    {
        $user = $this->user;
        $tel_detail = $user->tel_detail;
        $name = $type['name'] ?? $this->text;
        if (!preg_match('/^[a-zA-Z]+$/', $name)) {
            return $this->sendTemporaryMessage(
                '❌ نام فقط باید شامل حروف انگلیسی باشد.'
            );
        }

        $service_id = $tel_detail['order-service-id'];
        $country_id = $tel_detail['order-country-id'];
        $plan_id = $tel_detail['order-plan-id'];
        $extra = null;
        $count = $tel_detail['order-count'];
        $pasarGuard_id = $tel_detail['order-pasarguard-id'] ?? 0;

        $service = Service::find($service_id);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس مورد نظر یافت نشد');
        }

        $country = Countries::find($country_id);
        if (is_null($country) && $pasarGuard_id == 0) {
            return $this->sendTemporaryMessage('کشور مورد نظر یافت نشد');
        }
        if ($pasarGuard_id != 0) {
            $countryName = 'همه کشور ها';
        } else {
            $countryName = $country->name;
        }

        $plan = Plans::find($plan_id);
        if (is_null($plan)) {
            return $this->sendTemporaryMessage('تعرفه مورد نظر یافت نشد');
        }
        $pasarguardPercent = 0;
        if ($pasarGuard_id != 0) {
            $pasarguard = Panels::find($pasarGuard_id);
            $pasarguardDetail = $pasarguard->detail;
            $pasarguardPercent = $pasarguardDetail['percent'];
        }

        if ($pasarguardPercent != 0) {
            $basePrice = $plan->price;
            $percentPrice = ($basePrice / 100) * $pasarguardPercent;
            $planPrice = $basePrice + $percentPrice;
            if ($plan->discount != 0) {
                $discount = ($planPrice / 100) * $plan->discount;
                $planPrice = $planPrice - $discount;
            }
        } else {
            $planPrice = calculatePlanDiscount($plan)['price'];
        }
        try {
            $loyaltyQuote = app(CustomerLoyaltyService::class)->quote($user, (int) $service->id, $planPrice);
        } catch (\RuntimeException $e) {
            return $this->sendTemporaryMessage($e->getMessage());
        }
        $total = $loyaltyQuote['amount'] * $count;
        $loyaltyQuote['unit_amount'] = $loyaltyQuote['amount'];
        $loyaltyQuote['quantity'] = (int) $count;
        $loyaltyQuote['base_amount'] *= $count;
        $loyaltyQuote['amount'] = $total;
        $preOrderData = [
            'service-id' => $service_id,
            'country-id' => $country_id,
            'plan-id' => $plan_id,
            'pasarguard-id' => $pasarGuard_id,
            'extra' => $extra,
            'count' => $count,
            'name' => $name,
            'price' => $total
        ];


        $preOrder = new PreOrder();
        $preOrder->user_id = $user->id;
        $preOrder->data = $preOrderData;
        $preOrder->status = 0;
        $preOrder->count = $count;
        $preOrder->count_left = $count;
        $preOrder->save();

        $payment = new Payment();
        $payment->user_id = $user->id;
        $payment->order_id = $preOrder->id;
        $payment->price = $total;
        $payment->detail = ['customer_loyalty_quote' => $loyaltyQuote];
        $payment->status = 0;
        $payment->type = 1;
        $payment->expired_at = Carbon::now();
        $payment->save();
        $price = number_format($payment->price);

        $text = headTitle("🌍 انتخاب نحوه پرداخت");
        $text .= "📦 <b>نوع سرویس:</b> <code>{$service->name}</code>
🌐 <b>کشور:</b> <code>{$countryName}</code>
🌐 <b>تعرفه:</b> <code>{$plan->name}</code>
🌐 <b>تعداد:</b><code>{$count} عدد</code>
💵 <b>مبلغ کل:</b> <code>{$price} تومان</code>
💡 نحوه پرداخت را مشخص کنید:";

        $keyboard[] = [
            [
                'text' => '💰 کیف پول',
                'callback_data' => "type=paymentWallet|id={$payment->id}",
            ],
        ];

        $cartBeCart = Setting::where('key', 'cart_be_cart')->first();
        if (!is_null($cartBeCart) && $cartBeCart->value == 1) {
            $keyboard[] = [
                [
                    'text' => '💳 کارت به کارت',
                    'callback_data' => "type=paymentCartBeCart|id={$payment->id}",
                ],
            ];
        }

        $keyboard[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => "type=clientSelectName|s_id={$service_id}|co_id={$country?->id}|pl_id={$plan_id}|cu={$count}|p_id={$pasarGuard_id}",
            ],
        ];
        $data = [
            'chat_id' => $this->chatId,
            'text' => rtlMessage(trim($text)),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        return $this->sendMessage($data, 'message');

    }

    private function cartReceiptWasSubmitted(Payment $payment): bool
    {
        $detail = is_array($payment->detail)
            ? $payment->detail
            : (json_decode((string) $payment->detail, true) ?: []);
        $submissionState = (string) ($detail['receipt_submission_state'] ?? '');

        if ($submissionState === 'submitted') {
            return true;
        }

        if ($submissionState === 'sending') {
            try {
                $startedAt = Carbon::parse($detail['receipt_submission_started_at'] ?? null);

                return $startedAt->greaterThan(now()->subMinutes(2));
            } catch (\Throwable) {
                return true;
            }
        }

        // Receipts saved before receipt_submission_state was introduced.
        return !empty($detail['receipt_submitted_at']) || !empty($detail['value']);
    }

    private function cartReceiptStatusLabel(Payment $payment): string
    {
        return match ((string) $payment->status) {
            '1' => '✅ تایید شده',
            '-1', 'rejected' => '❌ رد تراکنش',
            '-2' => '↩️ مبلغ برگشت داده شده',
            '0' => $this->cartReceiptWasSubmitted($payment)
                ? '⏳ در حال بررسی'
                : '📤 در انتظار ارسال رسید',
            default => 'ℹ️ وضعیت نامشخص',
        };
    }

    private function sendCartReceiptState(Payment $payment)
    {
        $hasReceipt = $this->cartReceiptWasSubmitted($payment);
        $text = $hasReceipt
            ? "رسید این تراکنش قبلاً ارسال شده است.\n\n"
            : "امکان ارسال رسید برای این تراکنش وجود ندارد.\n\n";
        $text .= "🔢 شماره تراکنش: <code>{$payment->id}</code>\n";
        $text .= "وضعیت: <b>{$this->cartReceiptStatusLabel($payment)}</b>";

        $this->updatePath('start');
        if ($this->callbackId) {
            $this->telegramSdk->answerCallback([
                'callback_query_id' => $this->callbackId,
                'text' => strip_tags($this->cartReceiptStatusLabel($payment)),
                'show_alert' => false,
                'cache_time' => 1,
            ]);
        }

        $this->method = 'toUser';

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [[[
                    'text' => 'منو اصلی',
                    'callback_data' => 'type=home',
                    'style' => 'primary',
                ]]],
            ]),
        ], 'message');
    }

    protected function paymentCartBeCart($type)
    {
        $id = $type['id'] ?? null;
        $user = $this->user;
        $payment = Payment::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$payment) {
            return $this->sendTemporaryMessage('❌ پرداخت پیدا نشد');
        }
        if ((string) $payment->status !== '0' || $this->cartReceiptWasSubmitted($payment)) {
            return $this->sendCartReceiptState($payment);
        }

        if ((int) $payment->type === 2) {
            $order = Orders::where('id', $payment->order_id)->where('user_id', $user->id)->first();
            if (!$order || !app(OrderLifecycleService::class)->canRenew($order)) {
                return $this->sendTemporaryMessage('❌ مهلت ۷ روزه تمدید این سفارش به پایان رسیده است.');
            }
        } elseif ((int) $payment->type === 3) {
            $order = Orders::where('id', $payment->order_id)->where('user_id', $user->id)->first();
            if (!$order || !app(OrderLifecycleService::class)->canBuyExtra($order)) {
                return $this->sendTemporaryMessage('خرید حجم برای این سفارش امکان‌پذیر نیست.');
            }
        }

        $this->updatePath('sendCartBeCartReceipt');
        $price = $payment->price;

        $cartBeCartRandom = Setting::where('key', 'cart_be_cart_random')->first();

        $isRandom = ($cartBeCartRandom && (int)$cartBeCartRandom->value === 1);

        $query = Carts::where('status', 1);

        if ($isRandom) {
            $cart = (clone $query)->inRandomOrder()->first();
        } else {
            $cart = (clone $query)
                ->where('is_default', 1)
                ->orderByDesc('id')
                ->first();
            if (!$cart) {
                $cart = (clone $query)->orderByDesc('id')->first();
            }
        }

        $cardNumber = $cart->cart ?? 'اطلاعات یافت نشد';
        $cardSheba = $cart->sheba ?? null;
        $cardName = $cart->name ?? '—';

        $paymentDetail = is_array($payment->detail) ? $payment->detail : [];
        $paymentDetail['cart-number'] = $cardNumber;
        $paymentDetail['cart-sheba'] = $cardSheba;
        $paymentDetail['cart-name'] = $cardName;
        $payment->method = 'cart-be-cart';
        $payment->detail = $paymentDetail;
        $payment->save();

        $tel_detail = is_array($user->tel_detail) ? $user->tel_detail : [];
        $tel_detail['payment-id'] = $payment->id;
        $tel_detail['payment-type'] = 'cart-be-cart';
        $tel_detail['payment-cart-number'] = $cardNumber;
        $tel_detail['payment-cart-sheba'] = $cardSheba;
        $tel_detail['payment-cart-name'] = $cardName;

        $user->tel_detail = $tel_detail;
        $user->save();

        $support = Setting::where('key', 'support_id')->first();


        $rialAmount = number_format($price * 10);
        $amount = number_format($price);
        $paymentRemark = $this->paymentOrderRemark($payment);
        $remarkText = $paymentRemark !== null
            ? "\n🏷 ریمارک سرویس: <code>" . $this->escapeHtml($paymentRemark) . "</code>"
            : '';
        $cardNumberLine = $this->cardNumberLine($cardNumber);
        $cardShebaLine = $this->cardShebaLine($cardSheba);
        $text = "درخواست شما ثبت شد.
👝 مبلغ سفارش : <code>{$amount}</code> تومان
{$remarkText}
🔘 جهت تکمیل سفارش مبلغ فاکتور را به تومان به شماره کارت زیر واریز نموده و پس از واریز تصویر فیش را در همین مرحله برای ربات ارسال نمایید :

{$cardNumberLine}{$cardShebaLine}👤 به نام {$cardName}

در هنگام انتقال، از نوشتن توضیحات انتقال حاوی کلمات یا عبارات حساس مانند «وی‌پی‌ان vpn»، «کانفیگ»، «ویتوری»، «آی‌پی ثابت» و مشابه آن خودداری کنید
درصورت درج هر یک از این کلمات، حساب شما مسدود شده و کیف‌پول شارژ نخواهد شد

⚠️لطفا برای هربار انتقال به شماره کارت ها دقت کنید زیرا ممکن است تغییر کرده باشند
📸پس از واریز وجه، عکس رسید پرداختی خود را در همین قسمت ارسال کنید تا تراکنش شما بررسی و سپس در صورت صحت اطلاعات حساب کاربری تان شارژ شود
🚫تا قبل از ارسال رسید پرداختی از دکمه منو و یا بازگشت استفاده نکنید
⏳زمان بررسی تراکنش های کارت به کارت، بین 5 تا 30 دقیقه خواهد بود

توجه: لطفا از برنامه آپ برای انتقال استفاده نکنید، محدودیت داره

توجه: لطفا از برنامه آپ برای انتقال استفاده نکنید، محدودیت داره
چنانچه در پرداخت خود با مشکل مواجه شدید، به پشتیبانی مراجعه کنید :
📞 @{$support->value}";


        $buttons[] = [
            [
                'text' => '📤 ارسال رسید',
                'callback_data' => "type=paymentSendReceipt|id={$payment->id}"
            ]
        ];

        $buttons[] = [
            [
                'text' => 'منو اصلی',
                'callback_data' => "type=home",
                'style' => 'primary'
            ]
        ];

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ], 'message');
    }

    protected function paymentSendReceipt($type)
    {
        $payment = Payment::where('id', (int) ($type['id'] ?? 0))
            ->where('user_id', $this->user->id)
            ->first();

        if (!$payment) {
            return $this->sendTemporaryMessage('❌ پرداخت پیدا نشد.');
        }

        if ((string) $payment->status !== '0' || $this->cartReceiptWasSubmitted($payment)) {
            return $this->sendCartReceiptState($payment);
        }

        $paymentDetail = is_array($payment->detail) ? $payment->detail : [];
        $telDetail = is_array($this->user->tel_detail) ? $this->user->tel_detail : [];
        $samePayment = (int) ($telDetail['payment-id'] ?? 0) === (int) $payment->id;
        $telDetail['payment-id'] = $payment->id;
        $telDetail['payment-type'] = 'cart-be-cart';
        $telDetail['payment-cart-number'] = $paymentDetail['cart-number']
            ?? ($samePayment ? ($telDetail['payment-cart-number'] ?? null) : null);
        $telDetail['payment-cart-sheba'] = $paymentDetail['cart-sheba']
            ?? ($samePayment ? ($telDetail['payment-cart-sheba'] ?? null) : null);
        $telDetail['payment-cart-name'] = $paymentDetail['cart-name']
            ?? ($samePayment ? ($telDetail['payment-cart-name'] ?? null) : null);
        $this->user->tel_detail = $telDetail;
        $this->user->save();

        $this->updatePath('sendCartBeCartReceipt');
        $text = headTitle('💳 پرداخت کارت به کارت');
        $text .= "لطفا عکس رسید خود را ارسال کنید. \n";

        $buttons[] = [
            [
                'text' => 'منو اصلی',
                'callback_data' => "type=home",
                'style' => 'primary'
            ]
        ];

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ], 'message');

    }

    protected function paymentSubmitCartBeCartReceipt()
    {
        $user = $this->user;
        $telDetail = is_array($user->tel_detail) ? $user->tel_detail : [];
        $paymentId = (int) ($telDetail['payment-id'] ?? 0);

        $payment = Payment::where('id', $paymentId)
            ->where('user_id', $user->id)
            ->first();
        if (!$payment) {
            return $this->sendTemporaryMessage('❌ تراکنش معتبر پیدا نشد.');
        }

        if ((string) $payment->status !== '0' || $this->cartReceiptWasSubmitted($payment)) {
            return $this->sendCartReceiptState($payment);
        }

        if (empty($this->messageId)) {
            return $this->sendTemporaryMessage('❌ پیام یا رسیدی ارسال نشده است.');
        }

        $channelId = null;
        $transactionChannel = Setting::where('key', 'cart_be_cart_id')->first();
        if (
            !is_null($transactionChannel) &&
            !empty($transactionChannel->value)
        ) {
            $channelId = $transactionChannel->value;
        } else {
            $admin = User::where('is_admin', 1)->first();
            if ($admin) {
                $channelId = $admin->tel_id;
            }
        }
        if (!$channelId) {
            return $this->sendTemporaryMessage('❌ مقصد ارسال رسید یافت نشد.');
        }

        if ($this->fileId == false) {
            $value = $this->text;
        } else {
            $fileId = $this->fileId;
            $fileName = 'telegram_' . time() . '_' . rand(1000, 9999) . '.jpg';
            $savePath = base_path('../public_html/uploads/telegram/' . $fileName);
            $download = $this->telegramSdk->downloadFileById($fileId, $savePath);
            if ($download['ok']) {
                $value = url('uploads/telegram/' . $fileName);
            }
        }
        if (!isset($value) || trim((string) $value) === '') {
            return $this->sendTemporaryMessage('❌ دریافت رسید ناموفق بود؛ لطفاً دوباره ارسال کنید.');
        }

        $reservationToken = (string) Str::uuid();
        $payment = DB::transaction(function () use ($paymentId, $user, $telDetail, $value, $reservationToken) {
            $lockedPayment = Payment::where('id', $paymentId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (
                !$lockedPayment
                || (string) $lockedPayment->status !== '0'
                || $this->cartReceiptWasSubmitted($lockedPayment)
            ) {
                return null;
            }

            $detail = is_array($lockedPayment->detail) ? $lockedPayment->detail : [];
            $detail['cart-number'] = $detail['cart-number']
                ?? ($telDetail['payment-cart-number'] ?? null);
            $detail['cart-sheba'] = $detail['cart-sheba']
                ?? ($telDetail['payment-cart-sheba'] ?? null);
            $detail['cart-name'] = $detail['cart-name']
                ?? ($telDetail['payment-cart-name'] ?? null);
            $detail['value'] = $value;
            $detail['receipt_submission_state'] = 'sending';
            $detail['receipt_submission_started_at'] = now()->toDateTimeString();
            $detail['receipt_submission_token'] = $reservationToken;

            $lockedPayment->method = 'cart-be-cart';
            $lockedPayment->detail = $detail;
            $lockedPayment->save();

            return $lockedPayment;
        });

        if (!$payment) {
            $currentPayment = Payment::where('id', $paymentId)
                ->where('user_id', $user->id)
                ->first();

            return $currentPayment
                ? $this->sendCartReceiptState($currentPayment)
                : $this->sendTemporaryMessage('❌ تراکنش معتبر پیدا نشد.');
        }

        $caption = "💳 <b>رسید جدید کارت به کارت</b>\n\n";
        $paymentType = __('payment.type.' . $payment->type);
        $caption .= "💥 نوع تراکنش: <code>{$paymentType}</code>\n";
        $paymentRemark = $this->paymentOrderRemark($payment);
        if ($paymentRemark !== null) {
            $caption .= "🏷 ریمارک سرویس: <code>" . $this->escapeHtml($paymentRemark) . "</code>\n";
        }

        $paymentDetail = is_array($payment->detail) ? $payment->detail : [];
        $paymentCardNumber = $paymentDetail['cart-number'] ?? '—';
        $paymentCardSheba = $paymentDetail['cart-sheba'] ?? null;
        $paymentCardName = $paymentDetail['cart-name'] ?? '—';
        $price = number_format($payment->price);
        $caption .= "👤 کاربر\n";
        if (!empty($user->username)) {
            $caption .= "@{$user->username}\n";
        } else {
            $caption .= "{$user->first_name}\n";
        }
        $caption .= " آیدی: <code>{$user->tel_id}</code>\n\n";
        $caption .= "اطلاعات پرداخت\n";
        $caption .= "💰 شماره تراکنش: <code>{$payment->id}</code>\n";
        $caption .= $this->cardNumberLine($paymentCardNumber);
        $caption .= $this->cardShebaLine($paymentCardSheba);
        $caption .= "👤 صاحب کارت: {$paymentCardName}\n";
        $caption .= "🧾 مبلغ تراکنش: <code>{$price}</code> تومان\n\n";
        $caption .= "رسید کاربر: \n";

        $this->method = 'toUser';
        if ($this->fileId == false) {
            $caption .= $this->escapeHtml($value);
            $sendResult = $this->sendMessage([
                'chat_id' => $channelId,
                'text' => $caption,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '✅ تایید پرداخت',
                                'callback_data' => "type=adminConfirmCartReceipt|p_id={$payment->id}",
                                'style' => 'success'
                            ],
                            [
                                'text' => '❌ رد پرداخت',
                                'callback_data' => "type=adminRejectCartReceipt|p_id={$payment->id}",
                                'style' => 'danger'
                            ]
                        ]
                    ]
                ])
            ], 'message');
        } else {
            $sendResult = $this->telegramSdk->sendPhoto([
                'chat_id' => $channelId,
                'photo' => url($value),
                'caption' => $caption,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '✅ تایید پرداخت',
                                'callback_data' => "type=adminConfirmCartReceipt|p_id={$payment->id}",
                                'style' => 'success'
                            ],
                            [
                                'text' => '❌ رد پرداخت',
                                'callback_data' => "type=adminRejectCartReceipt|p_id={$payment->id}",
                                'style' => 'danger'
                            ]
                        ]
                    ]
                ])
            ]);
        }

        if (empty($sendResult['ok'])) {
            DB::transaction(function () use ($payment, $reservationToken) {
                $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->first();
                $detail = is_array($lockedPayment?->detail) ? $lockedPayment->detail : [];

                if (($detail['receipt_submission_token'] ?? null) !== $reservationToken) {
                    return;
                }

                unset(
                    $detail['value'],
                    $detail['receipt_submission_state'],
                    $detail['receipt_submission_started_at'],
                    $detail['receipt_submission_token']
                );
                $lockedPayment->detail = $detail;
                $lockedPayment->save();
            });

            return $this->sendTemporaryMessage('❌ ارسال رسید برای بررسی ناموفق بود؛ لطفاً دوباره تلاش کنید.');
        }

        DB::transaction(function () use ($payment, $reservationToken) {
            $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->first();
            $detail = is_array($lockedPayment?->detail) ? $lockedPayment->detail : [];

            if (($detail['receipt_submission_token'] ?? null) !== $reservationToken) {
                return;
            }

            $detail['receipt_submission_state'] = 'submitted';
            $detail['receipt_submitted_at'] = now()->toDateTimeString();
            unset($detail['receipt_submission_started_at'], $detail['receipt_submission_token']);
            $lockedPayment->detail = $detail;
            $lockedPayment->save();
        });

        $payment->refresh();
        $statusLabel = $this->cartReceiptStatusLabel($payment);

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => "✅ رسید شما با موفقیت ارسال شد.\n\n🔢 شماره تراکنش: <code>{$payment->id}</code>\nوضعیت: <b>{$statusLabel}</b>",
            'parse_mode' => 'HTML',
        ], 'message');
    }

    protected function adminRejectCartReceipt($type)
    {
        if (!$this->isAdmin) {
            return $this->denyAdminAccess();
        }

        $id = (int) ($type['p_id'] ?? 0);

        $payment = Payment::find($id);

        if (!$payment) {
            return $this->sendTemporaryMessage('❌ تراکنش یافت نشد.');
        }

        /*
        |--------------------------------------------------------------------------
        | Update Status
        |--------------------------------------------------------------------------
        */

        $alreadyRejected = false;
        $updated = Payment::where('id', $payment->id)
            ->where('status', 0)
            ->update([
                'status' => -1,
                'admin_id' => $this->user->id,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            $payment->refresh();
            if ((string) $payment->status === '-1') {
                $alreadyRejected = true;
            } else {
                if ($this->callbackId) {
                    return $this->telegramSdk->answerCallback([
                        'callback_query_id' => $this->callbackId,
                        'text' => 'این تراکنش قبلاً پردازش شده است: ' . strip_tags($this->cartReceiptStatusLabel($payment)),
                        'show_alert' => true,
                        'cache_time' => 1,
                    ]);
                }

                return $this->sendTemporaryMessage('این تراکنش قبلاً پردازش شده است.');
            }
        }
        $payment->refresh();

        if ($this->callbackId) {
            $this->telegramSdk->answerCallback([
                'callback_query_id' => $this->callbackId,
                'text' => $alreadyRejected
                    ? 'این تراکنش قبلاً رد شده بود؛ پیام به‌روزرسانی شد.'
                    : 'پرداخت رد و تراکنش لغو شد.',
                'show_alert' => false,
                'cache_time' => 1,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Channel ID
        |--------------------------------------------------------------------------
        */

        // Always edit the exact chat message whose button was pressed. The
        // configured receipt destination may have changed after it was sent.
        $channelId = $this->chatId;
        $support_id = Setting::where('key', 'support_id')->first();

        /*
        |--------------------------------------------------------------------------
        | Users
        |--------------------------------------------------------------------------
        */

        $adminUser = $alreadyRejected && $payment->admin_id
            ? (User::find($payment->admin_id) ?? $this->user)
            : $this->user;
        $targetUser = User::find($payment->user_id);

        if (!$targetUser) {
            return $this->sendTemporaryMessage('❌ کاربر تراکنش یافت نشد.');
        }

        /*
        |--------------------------------------------------------------------------
        | Payment Details
        |--------------------------------------------------------------------------
        */

        $price = number_format($payment->price);

        $paymentCardNumber = $payment->detail['cart-number'] ?? '—';
        $paymentCardSheba = $payment->detail['cart-sheba'] ?? null;
        $paymentCardName = $payment->detail['cart-name'] ?? '—';

        /*
        |--------------------------------------------------------------------------
        | Usernames
        |--------------------------------------------------------------------------
        */

        $targetUserName = !empty($targetUser->username)
            ? "@{$targetUser->username}"
            : ($targetUser->first_name ?? 'بدون نام');

        $adminUserName = !empty($adminUser->username)
            ? "@{$adminUser->username}"
            : ($adminUser->first_name ?? 'ادمین');

        /*
        |--------------------------------------------------------------------------
        | Caption
        |--------------------------------------------------------------------------
        */

        $caption = "❌ <b>تراکنش کارت به کارت رد شد</b>\n\n";

        $caption .= "━━━━━━━━━━━━━━━\n";
        $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
        $caption .= "🆔 <b>آیدی تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
        $caption .= "━━━━━━━━━━━━━━━\n";

        $caption .= "💳 <b>اطلاعات پرداخت</b>\n";
        $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
        $caption .= $this->cardNumberLine($paymentCardNumber);
        $caption .= $this->cardShebaLine($paymentCardSheba);
        $caption .= "👤 <b>صاحب کارت:</b> {$paymentCardName}\n";
        $caption .= "💰 <b>مبلغ:</b> <code>{$price}</code> تومان\n";
        $paymentRemark = $this->paymentOrderRemark($payment);
        if ($paymentRemark !== null) {
            $caption .= "🏷 <b>ریمارک سرویس:</b> <code>" . $this->escapeHtml($paymentRemark) . "</code>\n";
        }

        $caption .= "━━━━━━━━━━━━━━━\n";
        $caption .= "🚫 <b>رد شده توسط:</b>\n{$adminUserName}";


        if ($this->isPhoto) {
            $this->telegramSdk->editCaption([
                'chat_id' => $channelId,
                'message_id' => $this->messageId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => []]),
            ]);
        } else {
            $this->sendMessage([
                'chat_id' => $channelId,
                'text' => $caption,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => []]),
            ], 'message');
        }

        if ($alreadyRejected) {
            return;
        }

        $userText = "❌ <b>پرداخت شما تایید نشد</b>\n\n";
        $userText .= "🔢 شماره تراکنش: <code>{$payment->id}</code>\n";
        $userText .= "💰 مبلغ: <code>{$price}</code> تومان\n\n";
        $userText .= "در صورت نیاز با پشتیبانی ارتباط بگیرید.";
        if (!is_null($support_id) && $support_id->value != 0) {
            $buttons[] = [
                [
                    'text' => 'پشتیبانی',
                    'url' => "https://t.me/$support_id->value",
                ]
            ];
        }
        $buttons[] = [
            [
                'text' => 'منو اصلی',
                'callback_data' => "type=home",
                'style' => 'primary'
            ]
        ];
        $this->method = 'toUser';
        return $this->sendMessage([
            'chat_id' => $targetUser->tel_id,
            'text' => $userText,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ], 'message');
    }

    protected function adminConfirmCartReceipt($type)
    {
        if (!$this->isAdmin) {
            return $this->denyAdminAccess();
        }

        $id = $type['p_id'];

        $payment = Payment::find($id);
        if (is_null($payment)) {
            return $this->sendTemporaryMessage('تراکنش یافت نشد');
        }

        if ((int) $payment->type === 2) {
            $order = Orders::where('id', $payment->order_id)
                ->where('user_id', $payment->user_id)
                ->first();
            if (!$order || !app(OrderLifecycleService::class)->canRenew($order)) {
                return $this->sendTemporaryMessage('❌ مهلت ۷ روزه تمدید این سفارش به پایان رسیده است؛ رسید تایید نشد.');
            }
        } elseif ((int) $payment->type === 3) {
            $order = Orders::where('id', $payment->order_id)
                ->where('user_id', $payment->user_id)
                ->first();
            if (!$order || !app(OrderLifecycleService::class)->canBuyExtra($order)) {
                return $this->sendTemporaryMessage('خرید حجم برای این سفارش امکان‌پذیر نیست؛ رسید تایید نشد.');
            }
        }

        if ((string) $payment->status === '0') {
            $updated = Payment::where('id', $payment->id)
                ->where('status', 0)
                ->update([
                    'status' => 1,
                    'admin_id' => $this->user->id,
                    'detail' => json_encode(array_merge($payment->detail ?? [], ['loyalty_approved_at' => ($payment->detail['loyalty_approved_at'] ?? now()->toIso8601String())])),
                    'updated_at' => now(),
                ]);
            if ($updated === 1) {
                return $this->finalPaymentStep($payment->fresh());
            }
        }

        return $this->sendTemporaryMessage('این تراکنش قبلاً پردازش شده است.');
    }

    protected function paymentWallet($type)
    {
        $id = $type['id'];
        $user = $this->user;
        $payment = Payment::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (is_null($payment)) {
            return $this->sendTemporaryMessage('تراکنش یافت نشد');
        }

        if ((int) $payment->type === 2) {
            $order = Orders::where('id', $payment->order_id)
                ->where('user_id', $user->id)
                ->first();
            if (!$order || !app(OrderLifecycleService::class)->canRenew($order)) {
                return $this->sendTemporaryMessage('❌ مهلت ۷ روزه تمدید این سفارش به پایان رسیده است.');
            }
        } elseif ((int) $payment->type === 3) {
            $order = Orders::where('id', $payment->order_id)
                ->where('user_id', $user->id)
                ->first();
            if (!$order || !app(OrderLifecycleService::class)->canBuyExtra($order)) {
                return $this->sendTemporaryMessage('خرید حجم برای این سفارش امکان‌پذیر نیست.');
            }
        }
        $walletResult = DB::transaction(function () use ($payment, $user) {
            $lockedPayment = Payment::where('id', $payment->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();

            if (!$lockedPayment || !$lockedUser || (string) $lockedPayment->status !== '0') {
                return ['ok' => false, 'message' => 'این تراکنش قبلاً پردازش شده است.'];
            }
            if ((int) $lockedUser->balance < (int) $lockedPayment->price) {
                return ['ok' => false, 'insufficient' => true];
            }

            $before = (int) $lockedUser->balance;
            $lockedUser->balance = $before - (int) $lockedPayment->price;
            $lockedUser->save();

            $detail = is_array($lockedPayment->detail) ? $lockedPayment->detail : [];
            $detail['wallet_balance_before'] = $before;
            $detail['wallet_balance_after'] = (int) $lockedUser->balance;
            $lockedPayment->status = 1;
            $lockedPayment->method = 'wallet';
            $lockedPayment->detail = $detail;
            $lockedPayment->save();

            return ['ok' => true, 'payment' => $lockedPayment];
        });

        if (!empty($walletResult['insufficient'])) {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'موجودی شما برای پرداخت این سفارش کافی نیست.',
                'show_alert' => true,
                'cache_time' => 1,
            ];
            return $this->telegramSdk->answerCallback($data);
        }
        if (!($walletResult['ok'] ?? false)) {
            return $this->sendTemporaryMessage($walletResult['message'] ?? 'پرداخت انجام نشد.');
        }

        return $this->finalPaymentStep($walletResult['payment']);
    }

    protected function finalPaymentStep($payment)
    {
        switch ($payment->type) {
            case "1":
                return $this->submitOrder($payment->id, 'toUser');
                break;
            case "2":
                return $this->clientFinalRenew(['id' => $payment->id]);
                break;
            case "3":
                return $this->clientFinalExtra(['id' => $payment->id]);
                break;
            case "4":
                return $this->addFundFinal(['id' => $payment->id]);
                break;
        }
    }

    private function submitOrder($payment)
    {

        $payment = Payment::find($payment);
        $targetUser = User::find($payment->user_id);
        $adminMethod = 'toUser';
        $userMethod = 'toUser';

        $transactionChannel = Setting::where('key', 'cart_be_cart_id')->first();

        $channelId = (!is_null($transactionChannel) && !empty($transactionChannel->value))
            ? $transactionChannel->value
            : optional(User::where('is_admin', 1)->first())->tel_id;

        if (!$channelId) {
            return $this->sendTemporaryMessage('❌ مقصد ارسال رسید یافت نشد.');
        }

        if ($payment->method == 'cart-be-cart') {
            $caption = "✅ <b>تراکنش تایید شد</b>\n\n⏳ در حال تحویل سفارش به کاربر هستیم...\nلطفاً چند لحظه صبر کنید.\nشناسه کاربر:  <code>{$targetUser->tel_id}</code>\nشماره تراکنش:<code>{$payment->id}</code>\n";
            $adminMethod = 'edit';
            if ($this->isPhoto) {
                $this->telegramSdk->editCaption([
                    'chat_id' => $channelId,
                    'message_id' => $this->messageId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]);
            } else {
                $this->method = $adminMethod;
                $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');
            }
        }
        if ($payment->method == 'wallet') {

            $caption = "✅ <b>تراکنش تایید شد</b>\n\n⏳ در حال پردازش سفارش هستیم...\nلطفاً چند لحظه صبر کنید.";

            $this->sendMessage([
                'chat_id' => $targetUser->tel_id,
                'text' => $caption,
                'parse_mode' => 'HTML',
            ], 'message');

            $userMethod = 'edit';
        }

        /*
        |--------------------------------------------------------------------------
        | Load Models
        |--------------------------------------------------------------------------
        */

        $preOrder = PreOrder::find($payment->order_id);
        $targetUser = User::find($payment->user_id);

        $orderDetail = $preOrder->data;

        $plan = Plans::find($orderDetail['plan-id']);
        $panel = Panels::where('country_id', $orderDetail['country-id'])
            ->where('panel_type', $plan->type)
            ->where('system_type', 'sanaie')
            ->where('status', 1)
            ->first();

        if (!is_null($panel)) {
            $activeInbound = Inbounds::where('panel_id', $panel->id)
                ->where('status', 1)
                ->first();

            if (!is_null($activeInbound)) {
                $data['panel'] = $panel;
            } else {

                $activeInbound = Inbounds::where('country_id', $orderDetail['country-id'])
                    ->where('status', 1)
                    ->first();
                if (!is_null($activeInbound)) {
                    $data['panel'] = Panels::find($activeInbound->panel_id);
                }
            }
        } else {
            $activeInbound = Inbounds::where('country_id', $orderDetail['country-id'])
                ->where('status', 1)
                ->first();

            if (!is_null($activeInbound)) {
                $data['panel'] = Panels::find($activeInbound->panel_id);
            }
        }
        if (array_key_exists('pasarguard-id', $orderDetail) && $orderDetail['pasarguard-id'] != 0) {
            $data['panel'] = Panels::find($orderDetail['pasarguard-id']);
        }
        $data['targetUser'] = $targetUser;
        $data['payment'] = $payment;
        $data['preOrder'] = $preOrder;
        $data['adminMethod'] = $adminMethod;
        $data['userMethod'] = $userMethod;
        $data['channelId'] = $channelId;
        $data['orderDetail'] = $orderDetail;
        $data['plan'] = $plan;

        return $this->generateAccount($data);
    }

    private function generateAccount($data)
    {
        $panel = $data['panel'];
        $targetUser = $data['targetUser'];
        $payment = $data['payment'];
        $preOrder = $data['preOrder'];
        $adminMethod = $data['adminMethod'];
        $userMethod = $data['userMethod'];
        $channelId = $data['channelId'];
        $orderDetail = $data['orderDetail'];
        $plan = $data['plan'];

        $orderCountryId = (int) ($orderDetail['country-id'] ?? 0);
        $orderCountryName = $orderCountryId > 0
            ? Countries::whereKey($orderCountryId)->value('name')
            : ((int) ($orderDetail['pasarguard-id'] ?? 0) > 0 ? '🌍 همه کشورها' : null);

        if ($panel->system_type == 'pasarguard') {
            if (array_key_exists('pasarguard-id', $orderDetail) && $orderDetail['pasarguard-id'] != 0) {
                $activeGroup = Inbounds::where('panel_id', $panel->id)
                    ->where('status', 1)
                    ->pluck('inbound_id')
                    ->toArray();
                $inboundId = 0;
            } else {
                $activeGroup = Inbounds::where('panel_id', $panel->id)
                    ->where('country_id', $orderDetail['country-id'])
                    ->where('status', 1)
                    ->pluck('inbound_id')
                    ->toArray();
                $inboundId = $activeGroup[0];
            }

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

            $leftCount = (int)$preOrder->count_left;

            $successCount = 0;
            $orders = [];

            for ($i = 0; $i < $leftCount; $i++) {

                $remarkBase = $orderDetail['name'] !== 'random' ? $orderDetail['name'] : "{$targetUser->tel_id}";

                $remark = $remarkBase . '-' . rand(1111, 9999);

                $bandwidth = (int)$plan->bandwidth;

                if ($extra = ExtraBandwidth::find($orderDetail['extra'] ?? null)) {
                    $bandwidth += (int)$extra->bandwidth;
                }


                $result = $pasarGuard->createUserAndGetConfig([
                    'username' => $remark,
                    'group_id' => $activeGroup,
                    'days' => (int)$plan->days,
                    'total_gb' => $bandwidth,
                    'client_type' => 'links',
                    'note' => 'Created from Telegram bot',
                    'status' => "on_hold",
                    "on_hold_timeout" => 0,
                    'on_hold_expire_duration' => (int)$plan->days * 24 * 60 * 60,
                    'auto_delete_in_days' => 7
                ]);

                if (!$result['status']) {
                    return $result;
                }

                $config = $result['config'];

                $Order = Orders::create([
                    'user_id' => $payment->user_id,
                    'remark' => $remark,
                    'uid' => $result['user']['id'],
                    'sub_id' => $result['user']['subscription_url'],
                    'plan' => $plan->id,
                    'panel_id' => $panel->id,
                    'inbound_id' => $inboundId,
                    'system_type' => 'pasarguard',
                    'expire_at' => Carbon::now()->addDays((int)$plan->days)->format('Y-m-d H:i:s'),
                    'status' => 1,
                    'detail' => [
                        'code' => $config,
                        'preOrderId' => $preOrder->id,
                        'country-id' => $orderCountryId,
                        'country' => $orderCountryName,
                    ],
                ]);

                $orders[] = [
                    'order-id' => $Order->id,
                    'code' => $config,
                    'sub' => "{$panel->sub_address}{$Order->sub_id}",
                    'remark' => $Order->remark,
                ];
                $successCount++;
            }

            $preOrder->update([
                'status' => $successCount == $preOrder->count ? 1 : 0,
                'count_left' => max(0, $preOrder->count_left - $successCount),
            ]);

            foreach ($orders as $singleOrder) {
                if (array_key_exists('pasarguard-id', $orderDetail) && $orderDetail['pasarguard-id'] != 0) {
                    $code = $singleOrder['sub'];
                    $codeText = "";
                } else {
                    $code = $singleOrder['code'];
                    $codeText = "🔑 کد کانفیگ:
<code>{$singleOrder['code']}</code>";
                }
                $photo = "https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=" . urlencode($code);
                $this->telegramSdk->sendPhoto([
                    'chat_id' => $targetUser->tel_id,
                    'photo' => $photo,
                    'caption' => "
<b>✅ سفارش با موفقیت تکمیل شد</b>

🧾 شماره سفارش:
<code>{$singleOrder['order-id']}</code>
$codeText
🔗 لینک ساب:
<code>{$singleOrder['sub']}</code>
",
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=clientSingleOrder|id={$singleOrder['order-id']}",
                                ]
                            ]
                        ]
                    ]),
                ]);
            }

            $remarks = collect($orders)
                ->pluck('remark')
                ->filter()
                ->map(fn($r) => "<code>{$r}</code>")
                ->implode(', ');

            $targetUserName = $targetUser->username
                ? "@{$targetUser->username}"
                : ($targetUser->first_name ?? 'بدون نام');

            $price = number_format($payment->price);
            $paymentType = __('payment.type.' . $payment->type);

            if ($payment->method == 'cart-be-cart') {

                $adminUserName = $this->user->username
                    ? "@{$this->user->username}"
                    : ($this->user->first_name ?? 'ادمین');

                $caption = "✅ <b>تراکنش تایید شد</b>\n\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                $caption .= "💥 نوع تراکنش: <code>{$paymentType}</code>\n";
                $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
                $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                $caption .= "💰 <b>نوع پرداخت:</b> کارت به کارت \n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👨‍💻 <b>تایید شده توسط:</b> {$adminUserName}\n";
                $caption .= "📌 <b>وضعیت:</b> موفق\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "🔗<b>ریمـارک‌های تحویل شده:</b>\n{$remarks}";
            } elseif ($payment->method == 'wallet') {

                $this->deleteChat();
                $caption = "✅ <b>خرید آی پی</b>\n\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                $caption .= "💰 <b>نوع پرداخت:</b> کیف پول\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "🔗<b>ریمـارک‌های تحویل شده:</b>\n{$remarks}";
            }

            if ($this->isPhoto) {
                $this->telegramSdk->editCaption([
                    'chat_id' => $channelId,
                    'message_id' => $this->messageId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]);
            } else {
                $this->method = $adminMethod;
                $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');
            }

        } else {
            $session['session'] = "";
            if (!is_null($panel)) {
                $session = loginToSanaie([
                    'url' => $panel->url,
                    'username' => $panel->username,
                    'password' => $panel->password,
                ]);
            }

            if (empty($session['session'])) {

                $targetUser->increment('balance', $payment->price);

                $payment->update(['status' => -2]);
                $preOrder->update(['status' => -2]);

                $this->method = $adminMethod;
                $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => "❌ خطا در اتصال به سرور رخ داده است.\n\n🏷 نام سرور: {$panel->name}",
                    'parse_mode' => 'HTML',
                ], 'message');

                $this->method = $userMethod;
                return $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => "❌ عملیات تحویل سفارش انجام نشد.\n\n💰 مبلغ <code>{$payment->price}</code> تومان به کیف پول شما بازگردانده شد.",
                    'parse_mode' => 'HTML',
                ], 'message');
            }

            /*
            |--------------------------------------------------------------------------
            | Get Inbound
            |--------------------------------------------------------------------------
            */

            $activeInbound = Inbounds::where('panel_id', $panel->id)
                ->where('status', 1)
                ->first();

            $inbound = json_decode($activeInbound->setting, true);
            if (!$inbound) {
                return $this->sendTemporaryMessage('❌ اینباند یافت نشد.');
            }


            $inbound['settings'] = json_decode($inbound['settings'] ?? '{}', true);
            $inbound['streamSettings'] = json_decode($inbound['streamSettings'] ?? '{}', true);
            $inbound['sniffing'] = json_decode($inbound['sniffing'] ?? '{}', true);
            /*
            |--------------------------------------------------------------------------
            | Create Clients
            |--------------------------------------------------------------------------
            */

            $leftCount = (int)$preOrder->count_left;
            $successCount = 0;
            $orders = [];

            $address = $session['raw']['Domain'] ?? null;

            for ($i = 0; $i < $leftCount; $i++) {

                $remarkBase = $orderDetail['name'] === 'random'
                    ? ($targetUser->username ?? 'user')
                    : $orderDetail['name'];

                $remark = $remarkBase . '-' . rand(1111, 9999);

                $bandwidth = (int)$plan->bandwidth;

                if ($extra = ExtraBandwidth::find($orderDetail['extra'] ?? null)) {
                    $bandwidth += (int)$extra->bandwidth;
                }

                $expireTime = Carbon::now()
                        ->addDays((int)$plan->days)
                        ->timestamp * 1000;

                $addClientData = [
                    'serverUrl' => $panel->url,
                    'sessionCookie' => $session['session'],
                    'inboundId' => $inbound['id'],
                    'email' => $remark,
                    'uuid' => (string)Str::uuid(),
                    'subId' => Str::random(16),
                    'expiryTimestamp' => $expireTime,
                    'limitIp' => 0,
                    'totalGB' => gbToByte($bandwidth),
                ];
                $addClient = addClient($addClientData);

                if (!($addClient['success'] ?? false)) {
                    continue;
                }

                $code = makeSanaeiVlessConfig($inbound['streamSettings'], $addClientData['uuid'], $remark);

                $Order = Orders::create([
                    'user_id' => $payment->user_id,
                    'remark' => $remark,
                    'uid' => $addClientData['uuid'],
                    'sub_id' => $addClientData['subId'],
                    'plan' => $plan->id,
                    'panel_id' => $panel->id,
                    'system_type' => 'sanaie',
                    'inbound_id' => $activeInbound->id,
                    'status' => 1,
                    'expire_at' => Carbon::now()->addDays($plan->days)->format('Y-m-d H:i:s'),
                    'detail' => [
                        'code' => $code,
                        'preOrderId' => $preOrder->id,
                        'country-id' => $orderCountryId,
                        'country' => $orderCountryName,
                    ],
                ]);

                $orders[] = [
                    'order-id' => $Order->id,
                    'code' => $code,
                    'sub' => "{$panel->sub_address}/sub/{$Order->sub_id}",
                    'remark' => $Order->remark,
                ];
                $successCount++;
            }

            /*
            |--------------------------------------------------------------------------
            | Update PreOrder
            |--------------------------------------------------------------------------
            */

            $preOrder->update([
                'status' => $successCount == $preOrder->count ? 1 : 0,
                'count_left' => max(0, $preOrder->count_left - $successCount),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Send Orders to User
            |--------------------------------------------------------------------------
            */

            foreach ($orders as $singleOrder) {
                $photo = "https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=" . urlencode($singleOrder['code']);
                $this->telegramSdk->sendPhoto([
                    'chat_id' => $targetUser->tel_id,
                    'photo' => $photo,
                    'caption' => "
<b>✅ سفارش با موفقیت تکمیل شد</b>

🧾 شماره سفارش:
<code>{$singleOrder['order-id']}</code>

🔑 کد کانفیگ:
<code>{$singleOrder['code']}</code>

🔗 لینک ساب:
<code>{$singleOrder['sub']}</code>
",
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=clientSingleOrder|id={$singleOrder['order-id']}",
                                ]
                            ]
                        ]
                    ]),
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Final Report
            |--------------------------------------------------------------------------
            */

            $remarks = collect($orders)
                ->pluck('remark')
                ->filter()
                ->map(fn($r) => "<code>{$r}</code>")
                ->implode(', ');

            $targetUserName = $targetUser->username
                ? "@{$targetUser->username}"
                : ($targetUser->first_name ?? 'بدون نام');

            $price = number_format($payment->price);

            if ($payment->method == 'cart-be-cart') {

                $adminUserName = $this->user->username
                    ? "@{$this->user->username}"
                    : ($this->user->first_name ?? 'ادمین');

                $caption = "✅ <b>تراکنش تایید شد</b>\n\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
                $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                $caption .= "💰 <b>نوع پرداخت:</b> کارت به کارت \n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👨‍💻 <b>تایید شده توسط:</b> {$adminUserName}\n";
                $caption .= "📌 <b>وضعیت:</b> موفق\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👨‍💻 <b>ریمـارک‌های تحویل شده:</b>\n{$remarks}";
            } elseif ($payment->method == 'wallet') {

                $this->deleteChat();
                $caption = "✅ <b>خرید آی پی</b>\n\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                $caption .= "💰 <b>نوع پرداخت:</b> کیف پول\n";
                $caption .= "━━━━━━━━━━━━━━━\n";
                $caption .= "👨‍💻 <b>ریمـارک‌های تحویل شده:</b>\n{$remarks}";
            }

            if ($this->isPhoto) {
                $this->telegramSdk->editCaption([
                    'chat_id' => $channelId,
                    'message_id' => $this->messageId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]);
            } else {
                $this->method = $adminMethod;
                $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');
            }
        }
    }

    protected function clientOrders($data)
    {
        $page = $data['page'] ?? 1;
        $status = $data['status'] ?? 'all';
        $search = $data['search'] ?? null;

        $user = $this->user;
        if ($this->callbackId) {
            $this->telegramSdk->answerCallback([
                'callback_query_id' => $this->callbackId,
                'text' => 'در حال به‌روزرسانی وضعیت سفارش‌ها…',
                'show_alert' => false,
                'cache_time' => 0,
            ]);
        }

        $lifecycle = app(OrderLifecycleService::class);
        $lifecycle->refreshPasarguardListStatuses($user->id);

        $query = Orders::where('user_id', $user->id);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if (!empty($search)) {
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('remark', 'like', "%{$search}%")
                    ->orWhere('detail->code', $search);
            });
        }

        $list = $lifecycle->orderByStatus($query)
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page', $page);

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = headTitle("📦 لیست سفارش‌ها");

        if ($list->count() == 0) {
            $text .= "❌ موردی یافت نشد.";
        } else {
            $text .= "🟢 فعال | 🟠 حجم تمام\n";
            $text .= "🔵 زمان تمام | 🔴 لغو/غیرفعال\n\n";
            $text .= "برای مشاهده جزئیات روی سفارش کلیک کنید 👇\n\n";
        }

        /*
        |--------------------------------------------------------------------------
        | Buttons (Orders list)
        |--------------------------------------------------------------------------
        */

        $keyboard = ['inline_keyboard' => []];

        if ($list->count() > 0) {
            $orderCountries = app(OrderCountryResolver::class)->resolve($list->getCollection());

            foreach ($list as $order) {
                $country = $orderCountries[$order->id] ?? '🌍 نامشخص';
                $status = $lifecycle->statusMeta($order);
                $keyboard['inline_keyboard'][] = [
                    [
                        'text' => "{$status['icon']} {$order->id} | {$country}",
                        'callback_data' => "type=clientSingleOrder|id={$order->id}"
                    ]
                ];
            }

        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = $this->paginationFooterButton($list, $page, 'clientOrders');
        if (!empty($pagination)) {
            $keyboard['inline_keyboard'][] = $pagination;
        }

        $keyboard['inline_keyboard'][] = [['text' => 'منو', 'callback_data' => 'type=home']];

        /*
        |--------------------------------------------------------------------------
        | Send message
        |--------------------------------------------------------------------------
        */
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function clientSingleOrder($data)
    {
        $id = $data['id'] ?? null;
        $user = $this->user;

        if ($this->callbackId) {
            $this->telegramSdk->answerCallback([
                'callback_query_id' => $this->callbackId,
                'text' => 'در حال دریافت جزئیات سفارش…',
                'show_alert' => false,
                'cache_time' => 0,
            ]);
        }

        if (!$id) {
            return $this->telegramSdk->sendMessage([
                'chat_id' => $user->tel_id,
                'text' => "❌ <b>سفارش نامعتبر است!</b>\n\nلطفا دوباره از بخش «سرویس های من» سفارش خود را انتخاب کنید.",
                'parse_mode' => 'HTML',
            ]);
        }

        $order = Orders::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (is_null($order)) {
            return $this->telegramSdk->sendMessage([
                'chat_id' => $user->tel_id,
                'text' => "🚫 <b>سفارش یافت نشد</b>\n\nاین سفارش وجود ندارد یا متعلق به حساب شما نیست.",
                'parse_mode' => 'HTML',
            ]);
        }

        $panel = Panels::find($order->panel_id);

        if (is_null($panel)) {
            return $this->telegramSdk->sendMessage([
                'chat_id' => $user->tel_id,
                'text' => "⚠️ <b>خطا در دریافت اطلاعات سرویس</b>\n\nپنل مربوط به این سفارش پیدا نشد. لطفا با پشتیبانی در ارتباط باشید.",
                'parse_mode' => 'HTML',
            ]);
        }

        $lifecycle = app(OrderLifecycleService::class);
        $lifecycle->refreshTimeStatus($order);

        if ($lifecycle->shouldSkipProviderRefresh($order)) {
            $configDetail = ['status' => false, 'skipped_cancelled' => true];
        } else {
            try {
                $configDetail = getConfigDetail($order);
            } catch (\Throwable $exception) {
                Log::warning('Could not load live order details', [
                    'order_id' => $order->id,
                    'message' => $exception->getMessage(),
                ]);
                $configDetail = ['status' => false];
            }
        }

        if ($configDetail['status'] ?? false) {
            $lifecycle->applyConfigDetail($order, $configDetail);
            $order = $order->fresh();
        }

        $detail = is_array($order->detail)
            ? $order->detail
            : (json_decode($order->detail, true) ?: []);
        $canRenew = $lifecycle->canRenew($order);
        $status = $lifecycle->statusMeta($order);

        $buttons = [];

        $allowSellExtra = Setting::where('key', 'extra')->value('value') == 1;

        if ($panel->system_type != 'pasarguard' && $status['key'] !== Orders::STATUS_INACTIVE) {
            $buttons[] = [
                [
                    'text' => '✏️ تغییر نام',
                    'callback_data' => "type=clientChangeConfigName|id={$order->id}",
                ],
                [
                    'text' => '🔗 تغییر کد',
                    'callback_data' => "type=clientChangeConfigUid|id={$order->id}",
                ],
            ];
        }


        if ($allowSellExtra && in_array($status['key'], [Orders::STATUS_ACTIVE, Orders::STATUS_DATA_EXHAUSTED], true)) {
            $buttons[] = [
                [
                    'text' => '➕ خرید حجم',
                    'callback_data' => "type=clientBuyExtra|id={$order->id}",
                ],
                ...($canRenew ? [[
                    'text' => '🔄 تمدید سرویس',
                    'callback_data' => "type=clientRenewOrder|id={$order->id}",
                ]] : []),
            ];

        } else {
            $row = [];
            if ($canRenew) {
                $row[] = [
                    'text' => '🔄 تمدید سرویس',
                    'callback_data' => "type=clientRenewOrder|id={$order->id}",
                ];
            }
            if ($row !== []) {
                $buttons[] = $row;
            }
        }

        $buttons[] = [
            [
                'text' => '📚 راهنمای اتصال',
                'url' => 'https://t.me/ipsabetme/118',
            ],
            [
                'text' => '📋 لیست تراکنش‌ها',
                'callback_data' => "type=clientOrderTransactions|id={$order->id}|action=delete",
            ],
        ];

        $buttons[] = [

            [
                'text' => '🏠 منو اصلی',
                'callback_data' => 'type=home|action=delete',
            ],
            [
                'text' => '🔙 بازگشت',
                'callback_data' => 'type=clientOrders|action=delete',
            ],

        ];


        $jdf = new Jdf();
        $expireTime = $order->expire_at ? $jdf->jdate('H:i:s d-m-Y', strtotime($order->expire_at)) : 'اطلاعات یافت نشد';

        $warning = '';
        if ($configDetail['status'] ?? false) {
            $totalGb = $configDetail['data']['totalGb'];
            $totalUsed = $configDetail['data']['totalUsed'];
            $left = $configDetail['data']['left'];
            $code = $configDetail['data']['code'];
        } else {
            $cached = is_array($detail['lifecycle'] ?? null) ? $detail['lifecycle'] : [];
            $totalGb = $cached['total_gb'] ?? 'نامشخص';
            $totalUsed = $cached['used_gb'] ?? 'نامشخص';
            $left = $cached['left_gb'] ?? 'نامشخص';
            $code = $detail['code'] ?? null;
            $warning = !empty($configDetail['skipped_cancelled'])
                ? "\nℹ️ <i>سفارش غیرفعال است؛ اطلاعات ذخیره‌شده نمایش داده شد.</i>\n"
                : "\n⚠️ <i>اطلاعات لحظه‌ای پنل در دسترس نیست.</i>\n";
        }

        if ($status['key'] === Orders::STATUS_INACTIVE) {
            foreach ($buttons as $rowIndex => $row) {
                $buttons[$rowIndex] = array_values(array_filter($row, function (array $button) {
                    $callback = $button['callback_data'] ?? '';

                    return !str_contains($callback, 'type=clientRenewOrder')
                        && !str_contains($callback, 'type=clientBuyExtra');
                }));
            }
            $buttons = array_values(array_filter($buttons));
        }

        $configCodeRaw = $code ?? '-';

        $subId = (string) $order->sub_id;
        $subUrl = preg_match('#^https?://#i', $subId)
            ? $subId
            : rtrim((string) $panel->sub_address, '/') . '/' . ltrim($subId, '/');

        $subUrlSafe = htmlspecialchars($subUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $message = "<b>✅ جزئیات سفارش #{$order->id}</b>\n\n";
        $message .= "<b>وضعیت:</b> {$status['icon']} {$status['label']}\n";
        $message .= "<b>حجم کل:</b> {$totalGb} گیگ\n";
        $message .= "<b>حجم مصرف شده:</b> {$totalUsed} گیگ\n";
        $message .= "<b>حجم باقی مانده:</b> {$left} گیگ\n";
        $message .= "<b>زمان پایان:</b> {$expireTime}\n\n";
        $message .= $warning;
        if ($order->inbound_id != 0) {
            $configCode = htmlspecialchars($configCodeRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $message .= "<b>کد کانفیگ:</b>\n<code>{$configCode}</code>\n\n";
        } else {
            $code = $subUrl;
        }
        $message .= "<b>لینک ساب:</b>\n<code>{$subUrlSafe}</code>";

        /*
         * نکته مهم:
         * caption در sendPhoto محدودیت دارد.
         * اگر متن طولانی شد، اول QR را می فرستیم، بعد متن کامل را با sendMessage ارسال می کنیم.
         */
        $qrValue = $code ?: $subUrl;
        $photo = "https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=" . urlencode($qrValue);

        $replyMarkup = json_encode(['inline_keyboard' => $buttons]);
        $shortCaption = mb_strlen(strip_tags($message), 'UTF-8') <= 900;
        $photoParams = [
            'chat_id' => $user->tel_id,
            'photo' => $photo,
            'caption' => $shortCaption ? $message : "✅ جزئیات سفارش #{$order->id}",
            'parse_mode' => 'HTML',
        ];
        if ($shortCaption) {
            $photoParams['reply_markup'] = $replyMarkup;
        }
        $photoResult = $this->telegramSdk->sendPhoto($photoParams);

        if (!empty($photoResult['ok']) && $shortCaption) {
            $this->deleteChat();

            return $photoResult;
        }

        if (empty($photoResult['ok'])) {
            Log::warning('Telegram could not send order QR; falling back to text', [
                'order_id' => $order->id,
                'telegram_response' => $photoResult,
            ]);
        }

        $messageResult = $this->telegramSdk->sendMessage([
            'chat_id' => $user->tel_id,
            'text' => $message,
            'parse_mode' => 'HTML',
            'reply_markup' => $replyMarkup,
        ]);

        if (!empty($messageResult['ok'])) {
            $this->deleteChat();
        }

        return $messageResult;
    }

    protected function clientOrderTransactions($data)
    {
        $order = Orders::where('id', $data['id'] ?? null)
            ->where('user_id', $this->user->id)
            ->first();
        if (!$order) {
            return $this->sendTemporaryMessage('سفارش مورد نظر یافت نشد.');
        }

        $page = max(1, (int) ($data['page'] ?? 1));
        $payments = $this->orderTransactionsQuery($order)
            ->orderByDesc('id')
            ->paginate(8, ['*'], 'page', $page);
        $remark = $this->escapeHtml($order->remark);

        return $this->sendTransactionsPage(
            $payments,
            "📋 تراکنش‌های سفارش #{$order->id}\n🏷 {$remark}",
            "clientOrderTransactions|id={$order->id}",
            "type=clientSingleOrder|id={$order->id}|action=delete"
        );
    }

    protected function clientWalletTransactions($data)
    {
        $page = max(1, (int) ($data['page'] ?? 1));
        $payments = $this->walletTransactionsQuery($this->user)
            ->orderByDesc('id')
            ->paginate(8, ['*'], 'page', $page);

        return $this->sendTransactionsPage(
            $payments,
            '💰 تراکنش‌های کیف پول' . "\nموجودی: " . number_format((float) $this->user->balance) . ' تومان',
            'clientWalletTransactions',
            'type=addFund',
            false,
            true
        );
    }

    protected function adminOrderTransactions($data)
    {
        if (!$this->isAdmin) {
            return $this->denyAdminAccess();
        }

        $order = Orders::find($data['id'] ?? null);
        if (!$order) {
            return $this->sendTemporaryMessage('سفارش مورد نظر یافت نشد.');
        }

        $page = max(1, (int) ($data['page'] ?? 1));
        $payments = $this->orderTransactionsQuery($order)
            ->orderByDesc('id')
            ->paginate(5, ['*'], 'page', $page);
        $remark = $this->escapeHtml($order->remark);
        $owner = User::find($order->user_id);
        $ownerLabel = $owner
            ? ($owner->username ? '@' . ltrim($owner->username, '@') : ($owner->first_name ?: $owner->tel_id))
            : 'کاربر نامشخص';
        $ownerInfo = $this->escapeHtml($ownerLabel)
            . ($owner ? ' | ' . $this->escapeHtml($owner->tel_id) : '');

        return $this->sendTransactionsPage(
            $payments,
            "📋 تراکنش‌های کامل سفارش #{$order->id}\n🏷 {$remark}\n👤 {$ownerInfo}",
            "adminOrderTransactions|id={$order->id}",
            "type=adminOrderSingle|id={$order->id}",
            true
        );
    }

    protected function adminWalletTransactions($data)
    {
        if (!$this->isAdmin) {
            return $this->denyAdminAccess();
        }

        $targetUser = User::find($data['id'] ?? null);
        if (!$targetUser) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد.');
        }

        $page = max(1, (int) ($data['page'] ?? 1));
        $payments = $this->walletTransactionsQuery($targetUser)
            ->orderByDesc('id')
            ->paginate(5, ['*'], 'page', $page);
        $userLabel = $targetUser->username
            ? '@' . ltrim($targetUser->username, '@')
            : ($targetUser->first_name ?: $targetUser->tel_id);

        return $this->sendTransactionsPage(
            $payments,
            '💰 تراکنش‌های کیف پول ' . $this->escapeHtml($userLabel)
                . "\nموجودی: " . number_format((float) $targetUser->balance) . ' تومان',
            "adminWalletTransactions|id={$targetUser->id}",
            "type=adminUserBalance|id={$targetUser->id}",
            true,
            true
        );
    }

    protected function clientChangeConfigName($data)
    {
        $orderId = $data['id'];
        $user = $this->user;

        $orderQuery = Orders::where('id', $orderId);
        if (!$this->isAdmin) {
            $orderQuery->where('user_id', $user->id);
        }
        $order = $orderQuery->first();
        if (!$order) {
            return $this->sendTemporaryMessage('سفارش مورد نظر یافت نشد.');
        }
        $panel = Panels::find($order->panel_id);
        if (!$panel) {
            return $this->sendTemporaryMessage('پنل مربوط به سفارش یافت نشد.');
        }

        if ($panel->system_type == 'pasarguard') {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'امکان تغییر نام وجود ندارد',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            return $this->telegramSdk->answerCallback($data);
        }
        $this->updatePath('clientChangeConfigNameSubmit');
        $this->deleteChat();

        $telDetail = $user->tel_detail ?? [];
        $telDetail['order-id'] = $orderId;
        $user->tel_detail = $telDetail;
        $user->save();

        $text = "⚙️ <b>تغییر نام کانفیگ</b>\n\n";
        $text .= " مقدار قبلی : <b>" . $this->escapeHtml($order->remark) . "</b>\n\n";

        $buttons = [];

        $buttons[] = $this->clientFooterButtons("type=clientSingleOrder|id={$order->id}");


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function clientChangeConfigNameSubmit($data)
    {
        $name = $data['name'];
        if (!preg_match('/^[a-zA-Z]+$/', $name)) {
            return $this->sendTemporaryMessage(
                '❌ نام فقط باید شامل حروف انگلیسی باشد.'
            );
        }

        $name = $name . '-' . rand(100, 999);

        $user = $this->user;
        $userDetail = $user->tel_detail;

        $orderQuery = Orders::where('id', $userDetail['order-id'] ?? null);
        if (!$this->isAdmin) {
            $orderQuery->where('user_id', $user->id);
        }
        $order = $orderQuery->first();
        if (!$order) {
            return $this->sendTemporaryMessage('سفارش مورد نظر یافت نشد.');
        }

        $panel = Panels::find($order->panel_id);
        $inbound = Inbounds::find($order->inbound_id);
        if (!$panel || !$inbound || $panel->system_type == 'pasarguard') {
            return $this->sendTemporaryMessage('اطلاعات پنل سفارش کامل نیست یا امکان تغییر نام وجود ندارد.');
        }
        $loginData = [
            'username' => $panel->username,
            'password' => $panel->password,
            'url' => $panel->url,
        ];
        $session = loginToSanaie($loginData);

        $clientRequestData = [
            'sessionCookie' => $session['session'],
            'serverUrl' => $panel->url,
            'uuid' => $order->uid,
        ];

        $clientData = getClient($clientRequestData)['obj'][0];
        $result = [
            'serverUrl' => $panel->url,
            'sessionCookie' => $session['session'],
            'inboundId' => $clientData['inboundId'],
            'oldUuid' => $order->uid,
            'uuid' => $order->uid,
            'email' => $name,
            'expiryTimestamp' => $clientData['expiryTime'],
            'limitIp' => 0,
            'subId' => $clientData['subId'],
            'totalGB' => $clientData['total'],
        ];

        $result = updateClient($result);
        if ($result['success']) {

            $code = makeSanaeiVlessConfig(json_decode($inbound->setting, true)['streamSettings'], $order->uid, $name);

            $orderDetail = $order->detail;
            $orderDetail['code'] = $code;

            $order->remark = $name;
            $order->detail = $orderDetail;
            $order->save();
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'نام کانفیگ با موفقیت تغییر پیدا کرد.',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            $this->telegramSdk->answerCallback($data);

            $data['id'] = $order->id;
            return $this->clientSingleOrder($data);
        } else {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'خطا در تغییر نام کانفیگ',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            $this->telegramSdk->answerCallback($data);

            $data['id'] = $order->id;
            return $this->clientSingleOrder($data);
        }

    }

    protected function clientChangeConfigUid($data)
    {
        $id = $data['id'];
        $uid = (string)Str::uuid();

        $orderQuery = Orders::where('id', $id);
        if (!$this->isAdmin) {
            $orderQuery->where('user_id', $this->user->id);
        }
        $order = $orderQuery->first();
        if (!$order) {
            return $this->sendTemporaryMessage('سفارش مورد نظر یافت نشد.');
        }
        $panel = Panels::find($order->panel_id);
        if (!$panel) {
            return $this->sendTemporaryMessage('پنل مربوط به سفارش یافت نشد.');
        }
        if ($panel->system_type == 'pasarguard') {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'امکان تغییر کد وجود ندارد',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            return $this->telegramSdk->answerCallback($data);
        }

        $name = $order->remark;

        $panel = Panels::find($order->panel_id);
        $inbound = Inbounds::find($order->inbound_id);
        $loginData = [
            'username' => $panel->username,
            'password' => $panel->password,
            'url' => $panel->url,
        ];
        $session = loginToSanaie($loginData);
        $clientRequestData = [
            'sessionCookie' => $session['session'],
            'serverUrl' => $panel->url,
            'uuid' => $order->uid,
        ];
        $clientData = getClient($clientRequestData)['obj'][0];

        $result = [
            'serverUrl' => $panel->url,
            'sessionCookie' => $session['session'],
            'inboundId' => $clientData['inboundId'],
            'oldUuid' => $order->uid,
            'uuid' => $uid,
            'email' => $name,
            'expiryTimestamp' => $clientData['expiryTime'],
            'limitIp' => 0,
            'subId' => $clientData['subId'],
            'totalGB' => $clientData['total'],
        ];

        $result = updateClient($result);
        if ($result['success']) {

            $code = makeSanaeiVlessConfig(json_decode($inbound->setting, true)['streamSettings'], $uid, $name);

            $orderDetail = $order->detail;
            $orderDetail['code'] = $code;

            $order->uid = $uid;
            $order->detail = $orderDetail;
            $order->save();
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'کد کانفیگ با موفقیت تغییر پیدا کرد.',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            $this->telegramSdk->answerCallback($data);

            $data['id'] = $order->id;
            $this->deleteChat();
            return $this->clientSingleOrder($data);
        } else {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'خطا در تغییر کد کانفیگ',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            $this->telegramSdk->answerCallback($data);

            $data['id'] = $order->id;
            return $this->clientSingleOrder($data);
        }

    }

    protected function clientRenewOrder($data)
    {
        $orderId = $data['id'] ?? null;
        $order = Orders::where('id', $orderId)
            ->where('user_id', $this->user->id)
            ->first();

        if (!$order) {
            return $this->sendTemporaryMessage('سفارش مورد نظر یافت نشد.');
        }

        if (!app(OrderLifecycleService::class)->canRenew($order)) {
            return $this->sendTemporaryMessage('❌ مهلت ۷ روزه تمدید این سفارش به پایان رسیده است.');
        }

        $panel = Panels::find($order->panel_id);

        if (!$panel) {
            return $this->sendTemporaryMessage('پنل مربوط به سفارش یافت نشد.');
        }

        $plans = Plans::where('type', $panel->panel_type)->where('status', 1)->orderby('id')->get();

        if (count($plans) > 0) {
            $pasarguardPercent = 0;
            if ($order->inbound_id == 0) {
                $pasarguardDetail = is_array($panel->detail) ? $panel->detail : [];
                $pasarguardPercent = (float) ($pasarguardDetail['percent'] ?? 0);
            }


            foreach ($plans as $item) {

                if ($pasarguardPercent != 0) {
                    $basePrice = $item->price;
                    $percentPrice = ($basePrice / 100) * $pasarguardPercent;
                    $planPrice = $basePrice + $percentPrice;
                    if ($item->discount != 0) {
                        $discount = ($planPrice / 100) * $item->discount;
                        $planPrice = $planPrice - $discount;
                    }
                    $price = number_format($planPrice);
                } else {
                    $planPrice = calculatePlanDiscount($item)['price'];
                    $price = number_format($planPrice);
                }
                $name = !is_null($item->name) ? $item->name : 'بدون نام';
                try {
                    $price = number_format(app(CustomerLoyaltyService::class)->quote($this->user, (int) $panel->panel_type, $planPrice)['amount']);
                } catch (\RuntimeException $e) {
                    return $this->sendTemporaryMessage($e->getMessage());
                }
                $keyboard[] = [

                    [
                        'text' => "{$name} | مبلغ:$price T",
                        'callback_data' => "type=clientSubmitRenew|o_id={$order->id}|pl_id={$item->id}",
                    ],
                ];
            }
        }
        $text = headTitle('تمدید سرویس');
        $remark = $this->escapeHtml($order->remark);
        $text .= "🏷 <b>ریمارک سرویس:</b> <code>{$remark}</code>\n\n";
        $text .= "💡 لطفاً یکی از تعرفه‌های زیر را انتخاب کنید:";

        $keyboard[] = $this->clientFooterButtons("type=clientSingleOrder|id={$order->id}");
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        $this->deleteChat();
        $this->method = 'toUser';
        return $this->sendMessage($data, 'message');

    }

    protected function clientSubmitRenew($data)
    {

        $orderId = $data['o_id'];
        $planId = $data['pl_id'];
        $user = $this->user;
        $order = Orders::where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();
        $plan = Plans::find($planId);

        if (!$order || !$plan) {
            return $this->sendTemporaryMessage('سفارش یا تعرفه مورد نظر یافت نشد.');
        }

        if (!app(OrderLifecycleService::class)->canRenew($order)) {
            return $this->sendTemporaryMessage('❌ مهلت ۷ روزه تمدید این سفارش به پایان رسیده است.');
        }

        $panel = Panels::find($order->panel_id);

        if (!$panel) {
            return $this->sendTemporaryMessage('پنل مربوط به سفارش یافت نشد.');
        }
        if ((string) $plan->type !== (string) $panel->panel_type) {
            return $this->sendTemporaryMessage('تعرفه انتخاب‌شده مربوط به این سرویس نیست.');
        }

        $pasarguardPercent = 0;
        if ($order->inbound_id == 0) {
            $pasarguardDetail = is_array($panel->detail) ? $panel->detail : [];
            $pasarguardPercent = (float) ($pasarguardDetail['percent'] ?? 0);
        }

        if ($pasarguardPercent != 0) {
            $basePrice = $plan->price;
            $percentPrice = ($basePrice / 100) * $pasarguardPercent;
            $planPrice = $basePrice + $percentPrice;
            if ($plan->discount != 0) {
                $discount = ($planPrice / 100) * $plan->discount;
                $planPrice = $planPrice - $discount;
            }
            $price = number_format($planPrice);
        } else {
            $planPrice = calculatePlanDiscount($plan)['price'];
            $price = number_format($planPrice);
        }

        try {
            $loyaltyQuote = app(CustomerLoyaltyService::class)->quote($user, (int) $panel->panel_type, $planPrice);
        } catch (\RuntimeException $e) {
            return $this->sendTemporaryMessage($e->getMessage());
        }
        $planPrice = $loyaltyQuote['amount'];
        $price = number_format($planPrice);
        $detail['customer_loyalty_quote'] = $loyaltyQuote;

        $detail['plan-id'] = $plan->id;
        $detail['remark'] = (string) $order->remark;


        $payment = new Payment();
        $payment->user_id = $user->id;
        $payment->order_id = $orderId;
        $payment->price = $planPrice;
        $payment->status = 0;
        $payment->detail = $detail;
        $payment->type = 2;
        $payment->expired_at = Carbon::now()->addMinutes(10);
        $payment->save();

        $text = headTitle("🌍انتخاب نحوه پرداخت");
        $remark = $this->escapeHtml($order->remark);
        $text .= "🏷 <b>ریمارک سرویس:</b> <code>{$remark}</code>
🌐 <b>تعرفه:</b>
<code>{$plan->name} | حجم: {$plan->bandwidth} GB | مبلغ:{$price} تومان</code>
💡 نحوه پرداخت را مشخص کنید:";

        $keyboard[] = [
            [
                'text' => 'کیف پول',
                'callback_data' => "type=paymentWallet|id={$payment->id}",
            ],
        ];

        $cartBeCart = Setting::where('key', 'cart_be_cart')->first();
        if (!is_null($cartBeCart) && $cartBeCart->value == 1) {
            $keyboard[] = [
                [
                    'text' => 'کارت به کارت',
                    'callback_data' => "type=paymentCartBeCart|id={$payment->id}",
                ],
            ];
        }

        $keyboard[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => "type=clientRenewOrder|id={$orderId}",
            ],
        ];
        $data = [
            'chat_id' => $this->chatId,
            'text' => rtlMessage(trim($text)),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function clientFinalRenew($data)
    {
        $payment = Payment::find($data['id'] ?? null);
        if (!$payment || (int) $payment->type !== 2) {
            return $this->sendTemporaryMessage('تراکنش مورد نظر یافت نشد.');
        }

        $targetUser = User::find($payment->user_id);
        if (!$targetUser) {
            return $this->sendTemporaryMessage('کاربر مربوط به تراکنش یافت نشد.');
        }

        $order = Orders::where('id', $payment->order_id)
            ->where('user_id', $payment->user_id)
            ->first();
        if (!$order || !app(OrderLifecycleService::class)->canRenew($order)) {
            return $this->sendTemporaryMessage('❌ مهلت ۷ روزه تمدید این سفارش به پایان رسیده است.');
        }

        $adminMethod = 'toUser';
        $userMethod = 'toUser';

        $transactionChannel = Setting::where('key', 'cart_be_cart_id')->first();
        $channelId = (!is_null($transactionChannel) && !empty($transactionChannel->value))
            ? $transactionChannel->value
            : optional(User::where('is_admin', 1)->first())->tel_id;

        if (!$channelId) {
            return $this->sendTemporaryMessage('❌ مقصد ارسال رسید یافت نشد.');
        }
        if ($payment->method == 'cart-be-cart') {
            $remark = $this->escapeHtml($order->remark);
            $caption = "✅ <b>تراکنش تایید شد</b>\n\n🏷 <b>ریمارک سرویس:</b> <code>{$remark}</code>\n\n⏳ در حال تحویل سفارش به کاربر هستیم...\nلطفاً چند لحظه صبر کنید.";
            $adminMethod = 'edit';
            if ($this->isPhoto) {
                $this->telegramSdk->editCaption([
                    'chat_id' => $channelId,
                    'message_id' => $this->messageId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]);
            } else {
                $this->method = 'edit';
                $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');
            }
        }
        if ($payment->method == 'wallet') {

            $remark = $this->escapeHtml($order->remark);
            $caption = "✅ <b>تراکنش تایید شد</b>\n\n🏷 <b>ریمارک سرویس:</b> <code>{$remark}</code>\n\n⏳ در حال پردازش سفارش هستیم...\nلطفاً چند لحظه صبر کنید.";

            $this->sendMessage([
                'chat_id' => $targetUser->tel_id,
                'text' => $caption,
                'parse_mode' => 'HTML',
            ], 'message');

            $userMethod = 'edit';
        }

        $plan = Plans::find($payment->detail['plan-id']);
        $panel = Panels::find($order->panel_id);

        if (!$targetUser || !$plan || !$panel) {
            return $this->sendTemporaryMessage('اطلاعات لازم برای تمدید کامل نیست.');
        }

        return $this->renewClient($panel, $order, $plan, $targetUser, $payment, $adminMethod, $channelId);
    }

    protected function renewClient($panel, $order, $plan, $targetUser, $payment, $adminMethod, $channelId)
    {
        $paymentType = __('payment.type.' . $payment->type);
        $remark = $this->escapeHtml($order->remark);
        $remarkLine = "🏷 <b>ریمارک سرویس:</b> <code>{$remark}</code>\n";
        if ($panel->system_type == 'pasarguard') {


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
            $result = $pasarGuard->getUserById($order->uid);

            $currentExpire = is_array($result) && !empty($result['expire'])
                ? Carbon::parse($result['expire'])
                : Carbon::parse($order->expire_at);
            $renewalBase = $currentExpire->isFuture() ? $currentExpire : now();
            $expire = $renewalBase->copy()->addDays((int) $plan->days)->format('Y-m-d H:i:s');
            $band = gbToByte($plan->bandwidth);

            $data = [
                'status' => 'active',
                'expire' => $expire,
                'data_limit' => (is_array($result) && is_numeric($result['data_limit'] ?? null)
                    ? (float) $result['data_limit']
                    : 0) + $band,
            ];

            $result = $pasarGuard->updateUserById($order->uid, $data);

            if (is_array($result) && ($result['status'] ?? true) !== false) {

                $order->expire_at = $expire;
                $order->status = Orders::STATUS_ACTIVE;
                $order->reminded = 0;
                $orderDetail = is_array($order->detail) ? $order->detail : [];
                $lifecycleDetail = is_array($orderDetail['lifecycle'] ?? null) ? $orderDetail['lifecycle'] : [];
                unset($lifecycleDetail['remote_disabled'], $lifecycleDetail['cancelled_at']);
                $orderDetail['lifecycle'] = $lifecycleDetail;
                $order->detail = $orderDetail;
                $order->save();

                $caption = "تمدید سرویس با موفقیت انجام شد.\n\n{$remarkLine}";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=clientSingleOrder|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');


                $targetUserName = $targetUser->username
                    ? "@{$targetUser->username}"
                    : ($targetUser->first_name ?? 'بدون نام');

                $price = number_format($payment->price);
                if ($payment->method == 'cart-be-cart') {

                    $adminUserName = $this->user->username
                        ? "@{$this->user->username}"
                        : ($this->user->first_name ?? 'ادمین');

                    $caption = "✅ <b>تمدید تایید شد</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= $remarkLine;
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "💥 نوع تراکنش: <code>{$paymentType}</code>\n";
                    $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کارت به کارت \n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👨‍💻 <b>تایید شده توسط:</b> {$adminUserName}\n";
                    $caption .= "📌 <b>وضعیت:</b> موفق\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                } elseif ($payment->method == 'wallet') {
                    $this->deleteChat();
                    $caption = "✅ <b>تمدید آی پی</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= $remarkLine;
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "💥 نوع تراکنش: <code>{$paymentType}</code>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کیف پول\n";
                }


                if ($this->isPhoto) {
                    $this->telegramSdk->editCaption([
                        'chat_id' => $channelId,
                        'message_id' => $this->messageId,
                        'caption' => $caption,
                        'parse_mode' => 'HTML',
                    ]);
                } else {
                    $this->method = $adminMethod;
                    $this->sendMessage([
                        'chat_id' => $channelId,
                        'text' => $caption,
                        'parse_mode' => 'HTML',
                    ], 'message');
                }
            } else {

                $this->refundPaymentToWallet($payment, $targetUser, 'renew_failed');

                $caption = "❌ <b>تمدید سرویس ناموفق بود</b>

متاسفانه عملیات تمدید سرویس شما با خطا مواجه شد.

💰 مبلغ پرداختی شما به کیف پول بازگردانده شد.

📄 <b>شماره تراکنش:</b> <code>{$payment->id}</code>
💳 <b>مبلغ بازگشتی:</b> <code>{$payment->price}</code>

✅ موجودی کیف پول شما با موفقیت شارژ شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

                $caption = "🚨 <b>خطا در پردازش سفارش</b>

❌ تمدید سرویس ناموفق بود و سرویس ایجاد نشد.

📄 شماره تراکنش: <code>{$payment->id}</code>
💰 مبلغ: <code>{$payment->price}</code>

🔄 مبلغ به کیف پول کاربر بازگردانده شد.
✅ عملیات بازگشت وجه با موفقیت انجام شد.";
                $this->method = $adminMethod;
                return $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

            }
        } else {
            $loginData = [
                'username' => $panel->username,
                'password' => $panel->password,
                'url' => $panel->url,
            ];
            $session = loginToSanaie($loginData);

            $clientRequestData = [
                'sessionCookie' => $session['session'],
                'serverUrl' => $panel->url,
                'uuid' => $order->uid,
            ];

            $clientData = getClient($clientRequestData)['obj'][0];
            $band = gbToByte($plan->bandwidth);

            $currentExpire = !empty($clientData['expiryTime'])
                ? Carbon::createFromTimestampMs($clientData['expiryTime'])->timezone('Asia/Tehran')
                : Carbon::parse($order->expire_at);
            $renewalBase = $currentExpire->isFuture() ? $currentExpire : now();
            $expire = $renewalBase->copy()->addDays((int) $plan->days);

            $expiryTimestamp = $expire->timestamp * 1000;

            $result = [
                'serverUrl' => $panel->url,
                'sessionCookie' => $session['session'],
                'inboundId' => $clientData['inboundId'],
                'uuid' => $order->uid,
                'email' => $clientData['email'],
                'expiryTimestamp' => $expiryTimestamp,
                'limitIp' => 0,
                'subId' => $clientData['subId'],
                'totalGB' => $clientData['total'] + $band,
            ];

            $result = updateClient($result);

            if (is_array($result) && ($result['success'] ?? false)) {


                $order->expire_at = $expire;
                $order->status = Orders::STATUS_ACTIVE;
                $order->reminded = 0;
                $orderDetail = is_array($order->detail) ? $order->detail : [];
                $lifecycleDetail = is_array($orderDetail['lifecycle'] ?? null) ? $orderDetail['lifecycle'] : [];
                unset($lifecycleDetail['remote_disabled'], $lifecycleDetail['cancelled_at']);
                $orderDetail['lifecycle'] = $lifecycleDetail;
                $order->detail = $orderDetail;
                $order->save();

                $caption = "تمدید سرویس با موفقیت انجام شد.\n\n{$remarkLine}";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=clientSingleOrder|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');


                $targetUserName = $targetUser->username
                    ? "@{$targetUser->username}"
                    : ($targetUser->first_name ?? 'بدون نام');

                $price = number_format($payment->price);
                if ($payment->method == 'cart-be-cart') {

                    $adminUserName = $this->user->username
                        ? "@{$this->user->username}"
                        : ($this->user->first_name ?? 'ادمین');

                    $caption = "✅ <b>تمدید تایید شد</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= $remarkLine;
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کارت به کارت \n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👨‍💻 <b>تایید شده توسط:</b> {$adminUserName}\n";
                    $caption .= "📌 <b>وضعیت:</b> موفق\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                } elseif ($payment->method == 'wallet') {

                    $this->deleteChat();
                    $caption = "✅ <b>تمدید آی پی</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= $remarkLine;
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کیف پول\n";
                }


                if ($this->isPhoto) {
                    $this->telegramSdk->editCaption([
                        'chat_id' => $channelId,
                        'message_id' => $this->messageId,
                        'caption' => $caption,
                        'parse_mode' => 'HTML',
                    ]);
                } else {
                    $this->method = $adminMethod;
                    $this->sendMessage([
                        'chat_id' => $channelId,
                        'text' => $caption,
                        'parse_mode' => 'HTML',
                    ], 'message');
                }

            } else {
                $this->refundPaymentToWallet($payment, $targetUser, 'renew_failed');

                $caption = "❌ <b>تمدید سرویس ناموفق بود</b>

متاسفانه عملیات تمدید سرویس شما با خطا مواجه شد.

💰 مبلغ پرداختی شما به کیف پول بازگردانده شد.

📄 <b>شماره تراکنش:</b> <code>{$payment->id}</code>
💳 <b>مبلغ بازگشتی:</b> <code>{$payment->price}</code>

✅ موجودی کیف پول شما با موفقیت شارژ شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

                $caption = "🚨 <b>خطا در پردازش سفارش</b>

❌ تمدید سرویس ناموفق بود و سرویس ایجاد نشد.

📄 شماره تراکنش: <code>{$payment->id}</code>
💰 مبلغ: <code>{$payment->price}</code>

🔄 مبلغ به کیف پول کاربر بازگردانده شد.
✅ عملیات بازگشت وجه با موفقیت انجام شد.";
                $this->method = $adminMethod;
                return $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

            }
        }
    }

    protected function clientBuyExtra($data)
    {
        $order = Orders::where('id', $data['id'] ?? null)
            ->where('user_id', $this->user->id)
            ->first();
        if (!$order || !app(OrderLifecycleService::class)->canBuyExtra($order)) {
            return $this->sendTemporaryMessage('خرید حجم برای این سفارش امکان‌پذیر نیست.');
        }
        $panel = Panels::find($order->panel_id);

        if (!$panel) {
            return $this->sendTemporaryMessage('پنل مربوط به سفارش یافت نشد.');
        }

        $service = Service::find($panel->panel_type);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس مورد نظر یافت نشد');
        }

        $text = headTitle("خرید حجم اضافه");
        $remark = $this->escapeHtml($order->remark);
        $text .= "🏷 <b>ریمارک سرویس:</b> <code>{$remark}</code>\n\n";
        $text .= "💡 لطفاً یکی از گزینه زیر را انتخاب کنید:";


        $allowSellExtra = Setting::where('key', 'extra')->first();
        if (!is_null($allowSellExtra) && $allowSellExtra->value != 1) {
            return $this->home();
        }

        $list = ExtraBandwidth::where('type', $service->id)->where('status', 1)->paginate(20);
        $perGbPrice = $service->price_per_gb;

        $keyboard = [];
        if (count($list) > 0) {

            $pasarguardPercent = 0;
            if ($order->inbound_id == 0) {
                $pasarguardDetail = is_array($panel->detail) ? $panel->detail : [];
                $pasarguardPercent = (float) ($pasarguardDetail['percent'] ?? 0);
            }

            foreach ($list as $item) {
                $name = !is_null($item->name) ? $item->name : 'بدون نام';
                if ($pasarguardPercent != 0) {
                    $basePrice = $item->name * $perGbPrice;
                    $percentPrice = ($basePrice / 100) * $pasarguardPercent;
                    $planPrice = $basePrice + $percentPrice;
                    if ($item->discount != 0) {
                        $discount = ($planPrice / 100) * $item->discount;
                        $planPrice = $planPrice - $discount;
                    }
                    $price = number_format($planPrice);
                } else {
                    $price = calculateExtraDiscount($item, $perGbPrice);
                    $planPrice = $price['price'];
                    $price = number_format($planPrice);
                }

                try {
                    $price = number_format(app(CustomerLoyaltyService::class)->quote($this->user, (int) $service->id, $planPrice)['amount']);
                } catch (\RuntimeException $e) {
                    return $this->sendTemporaryMessage($e->getMessage());
                }
                $keyboard[] = [
                    [
                        'text' => "{$name} GB | {$price} تومان",
                        'callback_data' => "type=clientSubmitExtra|o_id={$order->id}|ex_id=$item->id",
                    ],
                ];
            }
        }


        $keyboard[] = $this->clientFooterButtons("type=clientSingleOrder|id={$order->id}");
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        $this->deleteChat();
        $this->method = 'toUser';
        return $this->sendMessage($data, 'message');
    }

    protected function clientSubmitExtra($data)
    {
        $orderId = $data['o_id'];
        $extraId = $data['ex_id'];
        $user = $this->user;

        $order = Orders::where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();
        if (!$order || !app(OrderLifecycleService::class)->canBuyExtra($order)) {
            return $this->sendTemporaryMessage('خرید حجم برای این سفارش امکان‌پذیر نیست.');
        }
        $panel = Panels::find($order->panel_id);
        $extra = ExtraBandwidth::find($extraId);
        if (!$panel || !$extra) {
            return $this->sendTemporaryMessage('اطلاعات خرید حجم کامل نیست.');
        }
        $service = Service::find($extra->type);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس مورد نظر یافت نشد');
        }
        if ((string) $service->id !== (string) $panel->panel_type) {
            return $this->sendTemporaryMessage('حجم اضافی انتخاب‌شده مربوط به این سرویس نیست.');
        }
        $perGbPrice = $service->price_per_gb;

        $pasarguardPercent = 0;
        if ($order->inbound_id == 0) {
            $pasarguardDetail = is_array($panel->detail) ? $panel->detail : [];
            $pasarguardPercent = (float) ($pasarguardDetail['percent'] ?? 0);
        }

        if ($pasarguardPercent != 0) {
            $basePrice = $extra->name * $perGbPrice;
            $percentPrice = ($basePrice / 100) * $pasarguardPercent;
            $planPrice = $basePrice + $percentPrice;
            if ($extra->discount != 0) {
                $discount = ($planPrice / 100) * $extra->discount;
                $planPrice = $planPrice - $discount;
            }
            $extraPrice = $planPrice;
            $price = number_format($planPrice);
        } else {
            $price = calculateExtraDiscount($extra, $perGbPrice);
            $extraPrice = $price['price'];
            $price = number_format($price['price']);
        }

        try {
            $loyaltyQuote = app(CustomerLoyaltyService::class)->quote($user, (int) $service->id, $extraPrice);
        } catch (\RuntimeException $e) {
            return $this->sendTemporaryMessage($e->getMessage());
        }
        $extraPrice = $loyaltyQuote['amount'];
        $price = number_format($extraPrice);
        $detail['customer_loyalty_quote'] = $loyaltyQuote;

        $detail['extra-id'] = $extra->id;
        $detail['remark'] = (string) $order->remark;

        $payment = new Payment();
        $payment->user_id = $user->id;
        $payment->order_id = $orderId;
        $payment->price = $extraPrice;
        $payment->status = 0;
        $payment->detail = $detail;
        $payment->type = 3;
        $payment->expired_at = Carbon::now()->addMinutes(10);
        $payment->save();

        $text = headTitle("💳 انتخاب روش پرداخت");
        $remark = $this->escapeHtml($order->remark);

        $text .= "🛒 <b>خلاصه سفارش شما</b>

📦 <b>نوع سرویس:</b>
خرید حجم

🏷 <b>ریمارک سرویس:</b>
<code>{$remark}</code>

🌐 <b>حجم انتخابی:</b>
<code>{$extra->name} گیگابایت</code>

💰 <b>مبلغ قابل پرداخت:</b>
<code>{$price}</code>

━━━━━━━━━━━━━━━━━━

🔻 لطفاً روش پرداخت مورد نظر خود را انتخاب کنید:";

        $keyboard[] = [
            [
                'text' => 'کیف پول',
                'callback_data' => "type=paymentWallet|id={$payment->id}",
            ],
        ];

        $cartBeCart = Setting::where('key', 'cart_be_cart')->first();
        if (!is_null($cartBeCart) && $cartBeCart->value == 1) {
            $keyboard[] = [
                [
                    'text' => 'کارت به کارت',
                    'callback_data' => "type=paymentCartBeCart|id={$payment->id}",
                ],
            ];
        }

        $keyboard[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => "type=clientBuyExtra|id={$orderId}",
            ],
        ];
        $data = [
            'chat_id' => $this->chatId,
            'text' => rtlMessage(trim($text)),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function clientFinalExtra($data)
    {

        $payment = Payment::find($data['id'] ?? null);
        if (!$payment || (int) $payment->type !== 3) {
            return $this->sendTemporaryMessage('تراکنش مورد نظر یافت نشد.');
        }
        $targetUser = User::find($payment->user_id);
        if (!$targetUser) {
            return $this->sendTemporaryMessage('کاربر مربوط به تراکنش یافت نشد.');
        }

        $order = Orders::where('id', $payment->order_id)
            ->where('user_id', $payment->user_id)
            ->first();
        if (!$order || !app(OrderLifecycleService::class)->canBuyExtra($order)) {
            return $this->sendTemporaryMessage('خرید حجم برای این سفارش امکان‌پذیر نیست.');
        }

        $adminMethod = 'toUser';
        $userMethod = 'toUser';

        $transactionChannel = Setting::where('key', 'cart_be_cart_id')->first();
        $channelId = (!is_null($transactionChannel) && !empty($transactionChannel->value))
            ? $transactionChannel->value
            : optional(User::where('is_admin', 1)->first())->tel_id;

        if (!$channelId) {
            return $this->sendTemporaryMessage('❌ مقصد ارسال رسید یافت نشد.');
        }
        if ($payment->method == 'cart-be-cart') {
            $remark = $this->escapeHtml($order->remark);
            $caption = "✅ <b>تراکنش تایید شد</b>\n\n🏷 <b>ریمارک سرویس:</b> <code>{$remark}</code>\n\n⏳ در حال تحویل سفارش به کاربر هستیم...\nلطفاً چند لحظه صبر کنید.";
            $adminMethod = 'edit';

            if ($this->isPhoto) {
                $this->telegramSdk->editCaption([
                    'chat_id' => $channelId,
                    'message_id' => $this->messageId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]);
            } else {
                $this->method = $adminMethod;
                $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');
            }
        }
        if ($payment->method == 'wallet') {

            $remark = $this->escapeHtml($order->remark);
            $caption = "✅ <b>تراکنش تایید شد</b>\n\n🏷 <b>ریمارک سرویس:</b> <code>{$remark}</code>\n\n⏳ در حال پردازش سفارش هستیم...\nلطفاً چند لحظه صبر کنید.";

            $this->sendMessage([
                'chat_id' => $targetUser->tel_id,
                'text' => $caption,
                'parse_mode' => 'HTML',
            ], 'message');

            $userMethod = 'edit';
        }

        $extra = ExtraBandwidth::find($payment->detail['extra-id']);
        $panel = Panels::find($order->panel_id);

        if (!$targetUser || !$extra || !$panel) {
            return $this->sendTemporaryMessage('اطلاعات لازم برای خرید حجم کامل نیست.');
        }

        return $this->ExtraClient($panel, $order, $extra, $targetUser, $payment, $adminMethod, $channelId);
    }

    protected function ExtraClient($panel, $order, $extra, $targetUser, $payment, $adminMethod, $channelId)
    {
        $remark = $this->escapeHtml($order->remark);
        $remarkLine = "🏷 <b>ریمارک سرویس:</b> <code>{$remark}</code>\n";

        if ($panel->system_type == 'pasarguard') {
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
            $result = $pasarGuard->getUserById($order->uid);

            $expire = is_array($result) && !empty($result['expire'])
                ? Carbon::parse($result['expire'])->format('Y-m-d H:i:s')
                : Carbon::parse($order->expire_at)->format('Y-m-d H:i:s');
            $band = gbToByte($extra->name);
            $data = [
                'status' => 'active',
                'expire' => $expire,
                'data_limit' => (is_array($result) && is_numeric($result['data_limit'] ?? null)
                    ? (float) $result['data_limit']
                    : 0) + $band,
            ];
            $result = $pasarGuard->updateUserById($order->uid, $data);

            if (is_array($result) && ($result['status'] ?? true) !== false) {
                $order->status = Orders::STATUS_ACTIVE;
                $order->reminded = 0;
                $order->save();
                $caption = "خرید حجم سرویس با موفقیت انجام شد.\n\n{$remarkLine}";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=clientSingleOrder|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');


                $targetUserName = $targetUser->username
                    ? "@{$targetUser->username}"
                    : ($targetUser->first_name ?? 'بدون نام');

                $price = number_format($payment->price);
                if ($payment->method == 'cart-be-cart') {

                    $adminUserName = $this->user->username
                        ? "@{$this->user->username}"
                        : ($this->user->first_name ?? 'ادمین');

                    $caption = "✅ <b>خرید حجم تایید شد</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= $remarkLine;
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کارت به کارت \n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👨‍💻 <b>تایید شده توسط:</b> {$adminUserName}\n";
                    $caption .= "📌 <b>وضعیت:</b> موفق\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                } elseif ($payment->method == 'wallet') {

                    $this->deleteChat();
                    $caption = "✅ <b>خرید حجم</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= $remarkLine;
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کیف پول\n";
                }

                if ($this->isPhoto) {
                    $this->telegramSdk->editCaption([
                        'chat_id' => $channelId,
                        'message_id' => $this->messageId,
                        'caption' => $caption,
                        'parse_mode' => 'HTML',
                    ]);
                } else {
                    $this->method = $adminMethod;
                    $this->sendMessage([
                        'chat_id' => $channelId,
                        'text' => $caption,
                        'parse_mode' => 'HTML',
                    ], 'message');
                }
            } else {

                $this->refundPaymentToWallet($payment, $targetUser, 'extra_failed');

                $caption = "❌ <b>خرید حجم ناموفق بود</b>

متاسفانه عملیات خرید حجم شما با خطا مواجه شد.

💰 مبلغ پرداختی شما به کیف پول بازگردانده شد.

📄 <b>شماره تراکنش:</b> <code>{$payment->id}</code>
💳 <b>مبلغ بازگشتی:</b> <code>{$payment->price}</code>

✅ موجودی کیف پول شما با موفقیت شارژ شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

                $caption = "🚨 <b>خطا در پردازش سفارش</b>

❌ خرید حجم ناموفق بود و سرویس ایجاد نشد.

📄 شماره تراکنش: <code>{$payment->id}</code>
💰 مبلغ: <code>{$payment->price}</code>

🔄 مبلغ به کیف پول کاربر بازگردانده شد.
✅ عملیات بازگشت وجه با موفقیت انجام شد.";
                $this->method = $adminMethod;
                return $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

            }


        } else {
            $loginData = [
                'username' => $panel->username,
                'password' => $panel->password,
                'url' => $panel->url,
            ];
            $session = loginToSanaie($loginData);

            $clientRequestData = [
                'sessionCookie' => $session['session'],
                'serverUrl' => $panel->url,
                'uuid' => $order->uid,
            ];

            $clientData = getClient($clientRequestData)['obj'][0];
            $band = gbToByte($extra->name);


            $expiryTimestamp = $clientData['expiryTime'];

            $result = [
                'serverUrl' => $panel->url,
                'sessionCookie' => $session['session'],
                'inboundId' => $clientData['inboundId'],
                'uuid' => $order->uid,
                'email' => $clientData['email'],
                'expiryTimestamp' => $expiryTimestamp,
                'limitIp' => 0,
                'subId' => $clientData['subId'],
                'totalGB' => $clientData['total'] + $band,
            ];

            $result = updateClient($result);

            if (is_array($result) && ($result['success'] ?? false)) {

                $order->status = Orders::STATUS_ACTIVE;
                $order->reminded = 0;
                $order->save();

                $caption = "خرید حجم سرویس با موفقیت انجام شد.\n\n{$remarkLine}";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=clientSingleOrder|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');

                $targetUserName = $targetUser->username
                    ? "@{$targetUser->username}"
                    : ($targetUser->first_name ?? 'بدون نام');

                $price = number_format($payment->price);
                if ($payment->method == 'cart-be-cart') {

                    $adminUserName = $this->user->username
                        ? "@{$this->user->username}"
                        : ($this->user->first_name ?? 'ادمین');

                    $caption = "✅ <b>خرید حجم تایید شد</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= $remarkLine;
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "🔢 <b>شماره تراکنش:</b> <code>{$payment->id}</code>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کارت به کارت \n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👨‍💻 <b>تایید شده توسط:</b> {$adminUserName}\n";
                    $caption .= "📌 <b>وضعیت:</b> موفق\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                } elseif ($payment->method == 'wallet') {

                    $this->deleteChat();
                    $caption = "✅ <b>خرید حجم</b>\n\n";
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "👤 <b>کاربر:</b> {$targetUserName}\n";
                    $caption .= "🆔 <b>شناسه تلگرام:</b> <code>{$targetUser->tel_id}</code>\n";
                    $caption .= $remarkLine;
                    $caption .= "━━━━━━━━━━━━━━━\n";
                    $caption .= "💳 <b>جزئیات پرداخت</b>\n";
                    $caption .= "💰 <b>مبلغ واریزی:</b> <code>{$price}</code> تومان\n";
                    $caption .= "💰 <b>نوع پرداخت:</b> کیف پول\n";
                }


                if ($this->isPhoto) {
                    $this->telegramSdk->editCaption([
                        'chat_id' => $channelId,
                        'message_id' => $this->messageId,
                        'caption' => $caption,
                        'parse_mode' => 'HTML',
                    ]);
                } else {
                    $this->method = $adminMethod;
                    $this->sendMessage([
                        'chat_id' => $channelId,
                        'text' => $caption,
                        'parse_mode' => 'HTML',
                    ], 'message');
                }

            } else {
                $this->refundPaymentToWallet($payment, $targetUser, 'extra_failed');

                $caption = "❌ <b>خرید حجم ناموفق بود</b>

متاسفانه عملیات خرید حجم شما با خطا مواجه شد.

💰 مبلغ پرداختی شما به کیف پول بازگردانده شد.

📄 <b>شماره تراکنش:</b> <code>{$payment->id}</code>
💳 <b>مبلغ بازگشتی:</b> <code>{$payment->price}</code>

✅ موجودی کیف پول شما با موفقیت شارژ شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $targetUser->tel_id,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

                $caption = "🚨 <b>خطا در پردازش سفارش</b>

❌ خرید حجم ناموفق بود و سرویس ایجاد نشد.

📄 شماره تراکنش: <code>{$payment->id}</code>
💰 مبلغ: <code>{$payment->price}</code>

🔄 مبلغ به کیف پول کاربر بازگردانده شد.
✅ عملیات بازگشت وجه با موفقیت انجام شد.";
                $this->method = $adminMethod;
                return $this->sendMessage([
                    'chat_id' => $channelId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                ], 'message');

            }
        }

    }


    protected function profile()
    {
        $user = $this->user;
        $balance = number_format($user->balance);
        $text = headTitle("حساب کاربری");
        $text .= "🆔 آیدی تلگرام: `{$user->tel_id}`";
        $text .= app(CustomerLoyaltyService::class)->profileText($user);
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '💰 موجودی', 'callback_data' => 'ignore'],
                        ['text' => "{$balance}", 'callback_data' => 'ignore'],
                    ],
                    [
                        ['text' => 'اتصال حساب', 'callback_data' => 'type=connectAccount'],
                    ] ,
                    [
                        ['text' => '⬅️ برگشت', 'callback_data' => 'type=home'],
                    ]
                ]
            ])
        ];
        $this->method = 'edit';
        return $this->sendMessage($data, 'message');
    }



    /**
     * Client Area
     */


    /**
     * Admin Area
     */
    protected function adminMenu($type)
    {
        $buttons = [];

        $buttons[] = [
            [
                'text' => "👥 لیست کاربران",
                'callback_data' => 'type=adminUserList'],
        ];

//        $buttons[] = [
//            ['text' => "🛒 سفارشات اخیر",
//                'callback_data' => 'type=adminOrdersList'],
//            [
//                'text' => "💳 تراکنشات اخیر",
//                'callback_data' => 'type=recent-transactions'],
//        ];

        $buttons[] = [
            [
                'text' => "🖥 پنل‌ها",
                'callback_data' => 'type=adminPanelMenu',
            ],
        ];

        $buttons[] = [
            [
                'text' => "📦 تعرفه ها",
                'callback_data' => 'type=adminPlans',
            ],
            [
                'text' => "📥 دریافت بکاپ",
                'callback_data' => 'type=backup',
            ],
        ];

        $buttons[] = [
            ['text' => "🤖 پیام‌های ربات",
                'callback_data' => 'type=bot-messages'],
            ['text' => "📨 ارسال پیام",
                'callback_data' => 'type=send-message'],
        ];
        $buttons[] = [
            [
                'text' => "تنظمیات کلی",
                'callback_data' => 'type=adminSetting',
            ],
        ];

        $buttons[] = [
            [
                'text' => "🏠 پنل کاربر",
                'style' => 'success',
                'callback_data' => 'type=home',
            ],
        ];

        $text = headTitle("👑 پنل مدیریت ربات");
        $text .= "⚙️ مدیریت کاربران، سفارشات، سرویس‌ها،
پنل‌ها، تراکنش‌ها و تنظیمات سیستم

📌 لطفاً یکی از گزینه‌های زیر را انتخاب کنید.
";
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];
        return $this->sendMessage($data, 'message');
    }

    // Users List
    protected function adminUserList($type)
    {
        $page = $type['page'] ?? 1;
        $filter = $type['filter'] ?? null;
        $search = $type['search'] ?? null;

        $query = User::query();

        /*
        |--------------------------------------------------------------------------
        | Filters
        |--------------------------------------------------------------------------
        */

        switch ($filter) {

            case 'admins':
                $query->where('is_admin', 1);
                break;

            case 'resellers':
                $query->where('is_seller', 1);
                break;

            case 'normal':
                $query->where('is_admin', 0)
                    ->where('is_seller', 0);
                break;
        }

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (!empty($search)) {

            $query->where(function ($q) use ($search) {

                $q->where('tel_id', 'LIKE', "%{$search}%")
                    ->orWhere('username', 'LIKE', "%{$search}%")
                    ->orWhere('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%");
            });
        }

        $users = $query
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page', $page);

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = headTitle("👥مدیریت کاربران");
        $text .= "🔎 جستجو بر اساس:
• آیدی تلگرام
• نام کاربری
• نام و نام خانوادگی

📌 برای مشاهده جزئیات،
روی کاربر موردنظر کلیک کنید.
";

        if ($filter) {
            $text .= "📌 فیلتر فعال: <code>{$filter}</code>\n\n";
        }

        $keyboard = [];
        $row = [];

        /*
        |--------------------------------------------------------------------------
        | User Buttons
        |--------------------------------------------------------------------------
        */

        foreach ($users as $user) {

            $name = trim(
                ($user->first_name ?? '') . ' ' .
                ($user->last_name ?? '')
            );


            $username = !empty($user->username)
                ? "@{$user->username}"
                : 'بدون یوزرنیم';

            $btnText = !empty($name) ? "{$name}" : "{$username}";

            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminUserDetail|id={$user->id}"
            ];

            // دو ستونه
            if (count($row) == 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        // باقی‌مانده
        if (!empty($row)) {
            $keyboard[] = $row;
        }


        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = [];

        if ($users->currentPage() > 1) {

            $pagination[] = [
                'text' => '⬅️ قبلی',
                'callback_data' => 'type=adminUserList|page=' . ($page - 1)
            ];
        } else {
            $pagination[] = [
                'text' => '⬅️ قبلی',
                'callback_data' => 'ignore',
                'style' => 'danger'
            ];
        }

// شماره صفحه وسط
        $pagination[] = [
            'text' => "📄 {$users->currentPage()} / {$users->lastPage()}",
            'callback_data' => 'ignore',
            'style' => 'success'
        ];

        if ($users->hasMorePages()) {

            $pagination[] = [
                'text' => 'بعدی ➡️',
                'callback_data' => 'type=adminUserList|page=' . ($page + 1)
            ];
        } else {
            $pagination[] = [
                'text' => 'بعدی ➡️',
                'callback_data' => 'ignore',
                'style' => 'danger'
            ];
        }

        $keyboard[] = $pagination;

        /*
        |--------------------------------------------------------------------------
        | Filter Buttons
        |--------------------------------------------------------------------------
        */
        $keyboard[] = [
            [
                'text' => 'جستجو',
                'callback_data' => 'type=adminUserSearch',
                'style' => 'primary'
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Home Button
        |--------------------------------------------------------------------------
        */

        $keyboard[] = $this->adminFooterButtons('type=admin-home');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserSearch($type)
    {
        $text = headTitle("👥جستجو کاربران");
        $text .= "🔎 جستجو بر اساس:
• آیدی تلگرام
• نام کاربری
• نام و نام خانوادگی

📌 برای مشاهده جزئیات،
روی کاربر موردنظر کلیک کنید.
";

        $keyboard[] = $this->adminFooterButtons('type=adminUserList');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        $this->updatePath('adminUserList');

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserDetail($type = null)
    {
        if (!$this->isAdmin) {
            return $this->denyAdminAccess();
        }

        $id = $type['id'];

        $currentUser = $this->user;

        $user = User::find($id);

        if (is_null($user)) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        /*
        |--------------------------------------------------------------------------
        | User Info
        |--------------------------------------------------------------------------
        */

        $fullName = trim(
            ($user->first_name ?? '') . ' ' .
            ($user->last_name ?? '')
        );

        if (empty($fullName)) {
            $fullName = '—';
        }

        $username = !empty($user->username)
            ? '@' . $user->username
            : '—';

        $balance = number_format((float)$user->balance);

        $status = match ((int)$user->status) {
            1 => '🟢 فعال',
            -1 => '🔴 غیرفعال',
            default => '🟡 محدود',
        };

        $isAdmin = ((int)$user->is_admin === 1)
            ? '✅'
            : '❌';

        $isSeller = ((int)$user->is_seller === 1)
            ? '✅'
            : '❌';

//        $lastOnline = !empty($user->updated_at)
//            ? jdate($user->updated_at)->ago()
//            : '—';

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = headTitle("👤پروفایل کاربر");

        $text .= "🪪 <b>نام کامل:</b>\n";
        $text .= "<code>{$fullName}</code>\n\n";

        $text .= "👤 <b>نام کاربری:</b>\n";
        $text .= "<code>{$username}</code>\n\n";

        $text .= "💰 <b>موجودی کیف پول:</b>\n";
        $text .= "<code>{$balance}</code> تومان\n\n";

        $text .= "📡 <b>وضعیت حساب:</b>\n";
        $text .= "{$status}\n\n";

        $text .= "🆔 <b>تلگرام آیدی:</b>\n";
        $text .= "<code>{$user->tel_id}</code>\n\n";

//        $text .= "⏳ <b>آخرین فعالیت:</b>\n";
//        $text .= "<code>{$lastOnline}</code>\n\n";

        $text .= "👮 <b>دسترسی ادمین:</b> {$isAdmin}\n";
        $text .= "💼 <b>وضعیت فروشنده:</b> {$isSeller}\n";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $keyboard = [
            [
                [
                    'text' => '💰 ویرایش موجودی',
                    'callback_data' => "type=adminUserBalance|id={$user->id}"
                ],
                [
                    'text' => '🛒 سفارشات کاربر',
                    'callback_data' => "type=adminOrdersList|userId={$user->id}"
                ]
            ],

            [
                [
                    'text' => '⚙️ تنظیمات کاربر',
                    'callback_data' => "type=adminUserSettings|id={$user->id}"
                ],
                [
                    'text' => '💳 تراکنش‌های کیف پول',
                    'callback_data' => "type=adminWalletTransactions|id={$user->id}"
                ]
            ],

            [
                [
                    'text' => '✉️ ارسال پیام',
                    'callback_data' => "type=adminUserSendMessage|id={$user->id}"
                ],
                [
                    'text' => ((int)$user->status === 1)
                        ? '🔴 غیرفعال کردن'
                        : '🟢 فعال کردن',

                    'callback_data' => "type=adminToggleUserStatus|id={$user->id}"
                ]
            ],
            [
                [
                    'text' => '⬅️ بازگشت',
                    'callback_data' => 'type=adminUserList'
                ]
            ],

            [
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=admin-home'
                ]
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Send Message
        |--------------------------------------------------------------------------
        */

        if ((int) $user->is_seller !== 1) {
            $text .= app(CustomerLoyaltyService::class)->profileText($user);
            array_unshift($keyboard, [['text' => 'تخفیف ثابت حساب', 'callback_data' => 'type=adminCustomerDiscount|id='.$user->id]]);
        }
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserBalance($type)
    {
        if (!$this->isAdmin) {
            return $this->denyAdminAccess();
        }

        $id = $type['id'];

        $user = User::find($id);

        if (!$user) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        $username = $user->username ? "@{$user->username}" : '—';
        $balance = number_format($user->balance);
        $text = headTitle("💰 مدیریت موجودی کیف پول");

        $text .= "👤 کاربر:\n<code>{$username}</code>\n\n";
        $text .= "⚙️ لطفا یکی از گزینه‌های زیر را انتخاب کنید:\n موجودی کیف پول{$balance}";

        $keyboard[] =
            [
                [
                    'text' => '➕ شارژ کیف پول',
                    'callback_data' => "type=adminUserBalanceAction|id={$id}|value=increment",
                    'style' => 'success'
                ],
                [
                    'text' => '➖ کسر از کیف پول',
                    'callback_data' => "type=adminUserBalanceAction|id={$id}|value=decrement",
                    'style' => 'danger'
                ],
            ];
        $keyboard[] = [[
            'text' => '📋 لیست تراکنش‌های کیف پول',
            'callback_data' => "type=adminWalletTransactions|id={$id}",
        ]];
        $keyboard[] = $this->adminFooterButtons("type=adminUserDetail|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserBalanceAction($type = null)
    {
        if (!$this->isAdmin) {
            return $this->denyAdminAccess();
        }

        $action = $type['value'];
        $id = $type['id'];

        $user = $this->user;

        $this->updatePath('adminUserBalanceActionBalance');

        $telDetail = $user->tel_detail ?? [];

        $telDetail['user-action'] = $action;
        $telDetail['user-id'] = $id;

        $user->tel_detail = $telDetail;
        $user->save();

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        if ($action == 'increment') {
            $text = headTitle("💰 افزایش موجودی کاربر");

            $text .= "✏️ لطفا مبلغی که می‌خواهید\n";
            $text .= "به کیف پول کاربر اضافه شود را وارد کنید.";
        } else {
            $text = headTitle("💰 کاهش موجودی کاربر");
            $text .= "✏️ لطفا مبلغی که می‌خواهید\n";
            $text .= "از کیف پول کاربر کسر شود را وارد کنید.";
        }

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $keyboard = [
            [
                [
                    'text' => '📂 انصراف',
                    'style' => 'danger',
                    'callback_data' => "type=adminUserDetail|id={$id}"
                ],
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=admin-home'
                ]
            ]
        ];

        /*
        |--------------------------------------------------------------------------
        | Send Message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserBalanceActionBalance()
    {
        if (!$this->isAdmin) {
            return $this->denyAdminAccess();
        }

        $validator = Validator::make(['amount' => $this->text], [
            'amount' => ['required', 'integer', 'min:1'],
        ], [
            'amount.required' => '❌ مبلغ را وارد کنید.',
            'amount.integer' => '❌ مبلغ باید یک عدد صحیح باشد.',
            'amount.min' => '❌ مبلغ باید بیشتر از صفر باشد.',
        ]);
        if ($validator->fails()) {
            return $this->sendTemporaryMessage($validator->errors()->first());
        }

        $balance = (int) $this->text;

        $user = $this->user;

        $action = $user->tel_detail['user-action'];
        $id = $user->tel_detail['user-id'];

        $targetUser = User::find($id);

        if (!$targetUser) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        if (!in_array($action, ['increment', 'decrement'], true)) {
            return $this->sendTemporaryMessage('عملیات کیف پول نامعتبر است.');
        }

        $direction = $action === 'increment' ? 'credit' : 'debit';
        $result = app(WpSyncService::class)->adminWalletAdjust(
            $targetUser,
            $direction,
            $balance,
            '',
            ['admin_id' => $user->id]
        );
        if (!($result['ok'] ?? false)) {
            return $this->sendTemporaryMessage($result['message'] ?? 'عملیات کیف پول انجام نشد.');
        }
        $operationText = $action === 'increment' ? '➕ افزایش موجودی' : '➖ کاهش موجودی';

        // رفرش دیتا
        $targetUser->refresh();

        $text = headTitle("💰تراکنش موجودی کاربر");


        $text .= "⚙️ <b>نوع عملیات:</b>\n<code>{$operationText}</code>\n\n";

        $text .= "💵 <b>مقدار تغییر:</b>\n<code>{$balance}</code>\n\n";

        $text .= "💰 <b>موجودی جدید کاربر:</b>\n<code>{$targetUser->balance}</code>\n\n";

        $text .= "✅ <b>وضعیت:</b> با موفقیت انجام شد";

        $keyboard = [
            [
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=admin-home'
                ],
                [
                    'text' => '👤 جزئیات کاربر',
                    'callback_data' => "type=adminUserDetail|id={$id}"
                ]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserSettings($type)
    {
        $id = $type['id'];

        $targetUser = User::find($id);

        if (!$targetUser) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        $username = $targetUser->username ? "@{$targetUser->username}" : '—';

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        $isAdmin = (int)$targetUser->is_admin === 1;
        $isSeller = (int)$targetUser->is_seller === 1;

        $keyboard = [

            [
                [
                    'text' => '👮 دسترسی ادمین',
                    'callback_data' => 'ignore',
                    'style' => 'primary'
                ]
            ],

            [
                [
                    'text' => $isAdmin ? 'فعال ✅' : 'فعال',
                    'callback_data' => "type=adminUserIsAdmin|id={$id}|value=1",
                ],
                [
                    'text' => !$isAdmin ? 'غیرفعال ❌' : 'غیرفعال',
                    'callback_data' => "type=adminUserIsAdmin|id={$id}|value=0",
                ],
            ],

            [
                [
                    'text' => '💼 دسترسی فروشنده',
                    'callback_data' => 'ignore',
                    'style' => 'primary'
                ]
            ],

            [
                [
                    'text' => $isSeller ? 'فعال ✅' : 'فعال',
                    'callback_data' => "type=adminUserIsSeller|id={$id}|value=1",
                ],
                [
                    'text' => !$isSeller ? 'غیرفعال ❌' : 'غیرفعال',
                    'callback_data' => "type=adminUserIsSeller|id={$id}|value=0",
                ],
            ],

            [
                [
                    'text' => '🎁 درصد تخفیف فروشنده',
                    'callback_data' => "type=adminUserSellerDiscount|id={$id}",
                    'style' => 'primary'
                ],
                [
                    'text' => '🎁 اینبوند های فروشنده',
                    'callback_data' => "type=adminUserSellerInbounds|id={$id}",
                    'style' => 'primary'
                ]
            ],

            [
                [
                    'text' => '📂 انصراف',
                    'callback_data' => "type=adminUserDetail|id={$id}",
                    'style' => 'danger'
                ]
            ],

            [
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=admin-home'
                ]
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = headTitle(" ⚙️ تنظیمات کاربر");


        $text .= "👤 کاربر:\n<code>{$username}</code>\n\n";
        $text .= "ℹ️ راهنما:\n✅ فعال = روشن بودن دسترسی\n❌ غیرفعال = خاموش بودن دسترسی\n\n برای تغییر دسترسی بر روی گزینه مورد نظر گلیگ کنید\n\n";
        $text .= "⚙️ یکی از گزینه‌ها را انتخاب کنید:";

        /*
        |--------------------------------------------------------------------------
        | Send
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserIsAdmin($type)
    {
        $id = $type['id'];
        $value = $type['value'];

        $user = User::find($id);

        if (!$user) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        if ($user->id == env('SUPERADMIN')) {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'امکان تغییر درسترسی این کاربر رو ندارید.',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            return $this->telegramSdk->answerCallback($data);
        }

        if ($user->id == $this->user->id) {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'امکان تغییر دسترسی خود را ندارید.',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            return $this->telegramSdk->answerCallback($data);
        }

        $user->is_admin = $value;
        $user->save();

        $statusText = $value ? '🟢 فعال شد' : '🔴 غیرفعال شد';

        $text = headTitle("🔔 بروزرسانی دسترسی ادمین");

        $text .= "وضعیت دسترسی شما تغییر کرد:\n";
        $text .= "<b>{$statusText}</b>\n\n";

        $text .= "در صورت نیاز با پشتیبانی تماس بگیرید.";

        $data = [
            'chat_id' => $user->tel_id,
            'text' => trim($text),
            'parse_mode' => 'HTML',
        ];

        $this->method = 'toUser';
        $this->sendMessage($data, 'message');

        $this->method = 'edit';
        $this->adminUserSettings($type);

        $data = [
            'callback_query_id' => $this->callbackId,
            'text' => 'وضعیت کاربر به عنوان مدیر تغییر پیدا کرد',
            'show_alert' => true,
            'cache_time' => 1,
        ];
        return $this->telegramSdk->answerCallback($data);


    }

    protected function adminUserIsSeller($type)
    {
        $id = $type['id'];
        $value = $type['value'];

        $user = User::find($id);

        if (!$user) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        if ($user->id == env('SUPERADMIN')) {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'امکان تغییر درسترسی این کاربر رو ندارید.',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            return $this->telegramSdk->answerCallback($data);
        }

        if ($user->id == $this->user->id) {
            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => 'امکان تغییر دسترسی خود را ندارید.',
                'show_alert' => true,
                'cache_time' => 1,
            ];

            return $this->telegramSdk->answerCallback($data);
        }

        $user->is_seller = $value;
        $user->save();

        if ($value) {
            $inbounds = Inbounds::where('status', 1)->get();
            foreach ($inbounds as $inbound) {
                $newSellerInbound = SellerInbound::where('user_id', $user->id)->where('inbound_id', $inbound->id)->first();
                if (is_null($newSellerInbound)) {
                    $sellerInbound = new SellerInbound();
                    $sellerInbound->user_id = $user->id;
                    $sellerInbound->inbound_id = $inbound->id;
                    $sellerInbound->status = $inbound->status;
                    $sellerInbound->save();
                }
            }
        }

        $statusText = $value ? '🟢 فعال شد' : '🔴 غیرفعال شد';

        $text = "🔔 <b>بروزرسانی دسترسی فروشنده</b>\n\n";

        $text .= "وضعیت دسترسی شما تغییر کرد:\n";
        $text .= "<b>{$statusText}</b>\n\n";

        $text .= "در صورت نیاز می‌توانید از پشتیبانی اطلاعات بیشتری دریافت کنید.";

        $data = [
            'chat_id' => $user->tel_id,
            'text' => trim($text),
            'parse_mode' => 'HTML',
        ];

        $this->method = 'toUser';
        $this->sendMessage($data, 'message');

        $this->method = 'edit';
        $this->adminUserSettings($type);

        $data = [
            'callback_query_id' => $this->callbackId,
            'text' => 'وضعیت کاربر به عنوان فروشنده با موفقیت تغییر پیدا کرد',
            'show_alert' => true,
            'cache_time' => 1,
        ];


        return $this->telegramSdk->answerCallback($data);

    }

    protected function adminUserSellerDiscount($type)
    {
        $id = $type['id'];

        $user = $this->user;

        $this->updatePath('adminUserSellerDiscountAmount');

        $telDetail = $user->tel_detail ?? [];

        $telDetail['user-id'] = $id;

        $user->tel_detail = $telDetail;
        $user->save();

        $discount = $telDetail['discount'] ?? 0;

        $text = headTitle("🎯 درصد تخفیف فروشنده");
        $text .= "💰 تخفیف فعلی: <code>{$discount}%</code>\n\n";

        $text .= "✏️ لطفا درصد تخفیفی که می‌خواهید\n";
        $text .= "برای این کاربر به عنوان فروشنده اعمال شود را وارد کنید.\n\n";

        $text .= "⚠️ این تخفیف روی موارد زیر اعمال می‌شود:\n";
        $text .= "• خرید سرویس\n";
        $text .= "• تمدید سرویس\n";
        $text .= "• خرید حجم\n";

        $keyboard = [
            [
                [
                    'text' => '📂 انصراف',
                    'style' => 'danger',
                    'callback_data' => "type=adminUserSettings|id={$id}"
                ],
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=admin-home'
                ]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');

    }

    protected function adminUserSellerDiscountAmount()
    {
        $validate = Validator::make([
            'discount' => $this->text
        ], [
            'discount' => ['required', 'numeric', 'min:0', 'max:100'],
        ], [
            'discount.required' => '❌ لطفا مقدار تخفیف را وارد کنید.',
            'discount.numeric' => '❌ مقدار باید عددی باشد.',
            'discount.min' => '❌ حداقل مقدار 0 است.',
            'discount.max' => '❌ حداکثر مقدار 100 است.',
        ]);

        if ($validate->fails()) {
            return $this->sendTemporaryMessage($validate->errors()->first());
        }

        $user = $this->user;
        $telDetail = $user->tel_detail ?? [];

        $userId = $telDetail['user-id'] ?? null;

        $discount = (float)$this->text;


        if (!$userId) {
            return $this->sendTemporaryMessage('کاربر انتخاب نشده است');
        }

        $targetUser = User::find($userId);

        if (!$targetUser) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        /*
        |--------------------------------------------------------------------------
        | Save discount in tel_detail (correct way)
        |--------------------------------------------------------------------------
        */

        $targetDetail = $targetUser->tel_detail ?? [];
        $targetDetail['discount'] = $discount;

        $targetUser->tel_detail = $targetDetail;
        $targetUser->save();

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        $text = headTitle(" 🎯 تخفیف فروشنده بروزرسانی شد");
        $text .= "💰 مقدار تخفیف:\n<code>{$discount}%</code>\n\n";
        $text .= "✅ با موفقیت ذخیره شد";

        $keyboard = [
            [
                [
                    'text' => '⚙️ تنظیمات کاربر',
                    'callback_data' => "type=adminUserSettings|id={$userId}"
                ],
                [
                    'text' => '🏠 منو اصلی',
                    'callback_data' => 'type=admin-home'
                ]
            ]
        ];

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];


        return $this->sendMessage($data, 'message');
    }

    protected function adminUserSellerInbounds($type)
    {
        $id = $type['id'];
        $page = $type['page'] ?? 1;

        $user = User::find($id);

        if (!$user) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        /*
        |--------------------------------------------------------------------------
        | Seller Inbounds (NEW STRUCTURE)
        |--------------------------------------------------------------------------
        */

        $selectedInboundIds = SellerInbound::where('user_id', $user->id)
            ->pluck('inbound_id')
            ->toArray();

        /*
        |--------------------------------------------------------------------------
        | Inbounds Pagination
        |--------------------------------------------------------------------------
        */

        $inbounds = Inbounds::with(['panel'])
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'page', $page);

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $username = $user->username ? "@{$user->username}" : '—';

        $text = headTitle("🌐 اینباندهای فروشنده");

        $text .= "👤 کاربر:\n<code>{$username}</code>\n\n";

        $text .= "ℹ️ راهنما:\n";
        $text .= "✅ فعال\n❌ غیرفعال\n\n";

        $text .= "⚙️ برای تغییر وضعیت روی هر اینباند کلیک کنید.";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $keyboard = [];

        foreach ($inbounds as $inbound) {

            $isActive = in_array($inbound->id, $selectedInboundIds);

            $icon = $isActive ? '✅' : '❌';

            $panelName = $inbound->panel->name ?? 'بدون پنل';

            $btnText = "{$icon} {$panelName} | {$inbound->remark} | {$inbound->port}";

            $keyboard[] = [
                [
                    'text' => $btnText,
                    'callback_data' => "type=adminUserSellerChangeInbound|id={$id}|inbound_id={$inbound->id}",
                ]
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = [];

        if ($inbounds->currentPage() > 1) {

            $pagination[] = [
                'text' => '⬅️ قبلی',
                'callback_data' => 'type=adminUserSellerInbounds'
                    . "|id={$id}|page=" . ($page - 1)
            ];
        }

        $pagination[] = [
            'text' => "📄 {$inbounds->currentPage()} / {$inbounds->lastPage()}",
            'callback_data' => 'ignore',
            'style' => 'primary'
        ];

        if ($inbounds->hasMorePages()) {

            $pagination[] = [
                'text' => 'بعدی ➡️',
                'callback_data' => 'type=adminUserSellerInbounds'
                    . "|id={$id}|page=" . ($page + 1)
            ];
        }

        $keyboard[] = $pagination;

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = [
            [
                'text' => '📂 بازگشت',
                'callback_data' => "type=adminUserSettings|id={$id}"
            ]
        ];

        $keyboard[] = [
            [
                'text' => '🏠 منو اصلی',
                'callback_data' => 'type=admin-home'
            ]
        ];

        /*
        |--------------------------------------------------------------------------
        | Send
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUserSellerChangeInbound($type)
    {
        $id = $type['id'];
        $inbound_id = (int)$type['inbound_id'];

        $seller = Seller::where('user_id', $id)->first();

        if (!$seller) {
            return $this->sendTemporaryMessage('تنظیمات فروشنده پیدا نشد');
        }

        /*
        |--------------------------------------------------------------------------
        | Check existing relation
        |--------------------------------------------------------------------------
        */

        $exists = SellerInbound::where('user_id', $id)
            ->where('inbound_id', $inbound_id)
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Toggle
        |--------------------------------------------------------------------------
        */

        if ($exists) {

            $exists->delete();

            $message = '❌ اینباند غیرفعال شد';

        } else {

            SellerInbound::create([
                'user_id' => $id,
                'inbound_id' => $inbound_id
            ]);

            $message = '✅ اینباند فعال شد';
        }

        /*
        |--------------------------------------------------------------------------
        | Alert (non-blocking)
        |--------------------------------------------------------------------------
        */

        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => $message,
            'show_alert' => false,
            'cache_time' => 1,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Reload UI
        |--------------------------------------------------------------------------
        */

        return $this->adminUserSellerInbounds([
            'id' => $id
        ]);
    }

    protected function adminToggleUserStatus($type)
    {
        $id = $type['id'];

        $user = User::find($id);

        if (!$user) {
            return $this->sendTemporaryMessage('کاربر پیدا نشد');
        }

        /*
        |--------------------------------------------------------------------------
        | Toggle Status
        |--------------------------------------------------------------------------
        */

        if ((int)$user->status === 1) {

            $user->status = -1;

            $statusText = '🔴 غیرفعال شد';
            $style = 'danger';

        } else {

            $user->status = 1;

            $statusText = '🟢 فعال شد';
            $style = 'success';
        }

        $user->save();

        /*
        |--------------------------------------------------------------------------
        | Notify User
        |--------------------------------------------------------------------------
        */

        $notifyText = "🔔 <b>بروزرسانی وضعیت حساب</b>\n\n";
        $notifyText .= "وضعیت حساب شما تغییر کرد:\n";
        $notifyText .= "<b>{$statusText}</b>";

        $notifyData = [
            'chat_id' => $user->tel_id,
            'text' => trim($notifyText),
            'parse_mode' => 'HTML',
        ];

        $this->method = 'toUser';
        $this->sendMessage($notifyData, 'message');

        /*
        |--------------------------------------------------------------------------
        | Admin Alert
        |--------------------------------------------------------------------------
        */

        $data = [
            'callback_query_id' => $this->callbackId,
            'text' => "وضعیت کاربر {$statusText}",
            'show_alert' => true,
            'cache_time' => 1,
        ];

        $this->telegramSdk->answerCallback($data);

        /*
        |--------------------------------------------------------------------------
        | Reload User Detail
        |--------------------------------------------------------------------------
        */
        $this->method = 'edit';
        return $this->adminUserDetail([
            'id' => $id
        ]);
    }


    // Start Panels

    protected function adminPanelMenu($type = null)
    {
        $buttons = [];

        $buttons[] = [
            [
                'text' => "🧩 انواع سرویس‌ها",
                'callback_data' => 'type=adminService',
            ],
            [
                'text' => "🌍 کشورها",
                'callback_data' => 'type=adminCountries',
            ],
        ];

        $buttons[] = [
            [
                'text' => "📦 تعرفه‌ها",
                'callback_data' => 'type=adminPlans',
            ],
            [
                'text' => "🖥 پنل‌ها",
                'callback_data' => 'type=admin-panels',
            ],
        ];

        $buttons[] = [
            [
                'text' => "💾 حجم‌های اضافه",
                'callback_data' => 'type=adminExtraBandwidths',
            ],
            [
                'text' => "🌐 اینباندها",
                'callback_data' => 'type=adminInbounds',
            ],
        ];

        $buttons[] = [
            [
                'text' => "📘 راهنما و آموزش",
                'callback_data' => 'type=adminServiceHelp',
                'style' => 'primary'
            ],
        ];

        $buttons[] = $this->adminFooterButtons();

        $text = headTitle(" ⚙️ مدیریت سرویس‌ها");
        $text .= "از این بخش می‌توانید:\n\n";

        $text .= "🌍 کشورها را مدیریت کنید\n";
        $text .= "🖥 پنل‌ها را تنظیم کنید\n";
        $text .= "📦 تعرفه های فروش را مدیریت کنید\n";
        $text .= "💾 حجم‌های اضافه را تنظیم کنید\n";
        $text .= "🌐 اینباندها را مدیریت کنید\n\n";

        $text .= "👇 لطفاً یکی از گزینه‌های زیر را انتخاب کنید.";

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminPanels($type)
    {
        $page = $type['page'] ?? 1;

        $list = Panels::orderByDesc('id')
            ->paginate(10, ['*'], 'page', $page);

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $keyboard = ['inline_keyboard' => []];

        foreach ($list as $panel) {
            $status = match ((int)$panel->status) {
                1 => '🟢 فعال',
                0 => '🟡 در انتظار',
                -1 => '🔴 غیرفعال',
                default => '⚪ نامشخص',
            };
            $name = !is_null($panel->name) ? $panel->name : 'بدون نام';
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => "نام: {$name} | {$status}",
                    'callback_data' => "type=adminPanelDetail|id={$panel->id}",
                ]
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = $this->paginationFooterButton($list, $page, 'adminPanels');

        if (!empty($pagination)) {
            $keyboard['inline_keyboard'][] = $pagination;
        }

        /*
        |--------------------------------------------------------------------------
        | Actions
        |--------------------------------------------------------------------------
        */

        $keyboard['inline_keyboard'][] = [
            [
                'text' => '➕ ایجاد پنل جدید',
                'callback_data' => 'type=adminCreatePanel',
                'style' => 'success'
            ]
        ];

        $keyboard['inline_keyboard'][] = $this->adminFooterButtons("type=adminPanelMenu");

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = headTitle("🖥 مدیریت پنل‌ها");

        $text .= "📌 وضعیت پنل‌ها:\n";
        $text .= "🟢 فعال = پنل در حال کار و قابل استفاده\n";
        $text .= "🟡 در انتظار = پنل ثبت شده ولی هنوز فعال نشده\n";
        $text .= "🔴 غیرفعال = پنل خاموش یا غیرقابل استفاده\n\n";

        $text .= "📍 برای مشاهده جزئیات یا ویرایش، روی هر پنل کلیک کنید.\n\n";

        $text .= "📄 صفحه: {$list->currentPage()} از {$list->lastPage()}\n";
        $text .= "📦 تعداد کل: {$list->total()} پنل";

        /*
        |--------------------------------------------------------------------------
        | Send
        |--------------------------------------------------------------------------
        */

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ], 'message');
    }

    protected function adminPanelDetail($type)
    {
        $id = $type['id'];

        $panel = Panels::find($id);

        if (is_null($panel)) {
            return $this->sendTemporaryMessage('panel not found please try again');
        }

        $fields = [
            'name' => ['label' => 'نام', 'value' => $panel->name],
            'url' => ['label' => 'آدرس', 'value' => $panel->url],
            'username' => ['label' => 'نام کاربری', 'value' => $panel->username],
            'password' => ['label' => 'رمز عبور', 'value' => $panel->password],
            'panel_type' => ['label' => 'نوع پنل', 'value' => $panel->panel_type],
            'sub_address' => ['label' => 'لینک ساب', 'value' => $panel->sub_address],
            'system_type' => ['label' => 'سیستم', 'value' => $panel->system_type],
            'status' => ['label' => 'وضعیت', 'value' => $panel->status],
        ];
        if ($panel->system_type == 'sanaie') {
            $fields['country_id'] = ['label' => 'کشور', 'value' => $panel->country_id];
        }

        $text = headTitle("📋 جزئیات پنل");

        $keyboard = [];
        $row = [];

        foreach ($fields as $key => $field) {

            $isEmpty = ($field['value'] === null || $field['value'] === '');

            if ($key == 'status') {
                $valueText = match ((int)$field['value']) {
                    1 => 'فعال',
                    -1 => 'غیرفعال',
                    default => 'معلق',
                };

            } elseif ($key == 'country_id') {
                $valueText = Countries::find($field['value'])->name ?? '—';

            } elseif ($key == 'panel_type') {
                $valueText = Service::find($field['value'])->name ?? '—';

            } else {

                $valueText = $isEmpty
                    ? '—'
                    : (string)$field['value'];

                // 🔐 اسپویل برای فیلدهای حساس
                if (in_array($key, ['username', 'password', 'url'])) {
                    $valueText = "<tg-spoiler>{$valueText}</tg-spoiler>";
                } else {
                    $valueText = htmlspecialchars($valueText);
                }
            }

            if (in_array($key, ['username', 'password', 'url'])) {
                $text .= "▪️ <b>{$field['label']}</b>: {$valueText}\n";
            } else {
                $text .= "▪️ <b>{$field['label']}</b>: <code>{$valueText}</code>\n";

            }


            // دکمه‌ها
            $textBtn = $isEmpty
                ? "🔴 " . $field['label']
                : "🟢 " . $field['label'];

            $row[] = [
                'text' => $textBtn,
                'style' => $isEmpty ? 'danger' : '',
                'callback_data' => "type=adminEditPanel|id={$id}|key={$key}"
            ];

            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        if ($panel->system_type == 'pasarguard') {
            $keyboard[] = [
                [
                    'text' => '📂 بررسی اتصال',
                    'callback_data' => "type=adminConnectPanel|id=$panel->id"
                ], [
                    'text' => '📂دریافت گروه ها',
                    'callback_data' => "type=adminGetInbounds|id=$panel->id"
                ]
            ];
            $keyboard[] = [
                [
                    'text' => 'لیست گروه ها',
                    'callback_data' => "type=adminPasarGuardGroups|id=$panel->id",
                ]
            ];
            $keyboard[] = [
                [
                    'text' => 'فروش از چند کشور',
                    'callback_data' => "type=adminPasarGuardSellSetting|id=$panel->id",
                ]
            ];
        } else {
            $keyboard[] = [
                [
                    'text' => '📂 بررسی اتصال',
                    'callback_data' => "type=adminConnectPanel|id=$panel->id"
                ], [
                    'text' => '📂دریافت اینبوند ها',
                    'callback_data' => "type=adminGetInbounds|id=$panel->id"
                ]
            ];
        }

        $keyboard[] = [
            [
                'text' => 'حذف پنل',
                'callback_data' => "type=adminPanelDeleteDetail|id=$panel->id",
                'style' => 'danger'
            ]
        ];

        $text .= "\n⚠️ فیلدهای خالی با مقدار — مشخص شده‌اند.";


        $keyboard[] = $this->adminFooterButtons('type=admin-panels');


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCreatePanel($type)
    {
        $user = $this->user;

        $newPanel = new Panels();
        $newPanel->admin_id = $user->id;
        $newPanel->status = -1;
        $newPanel->save();

        $type['id'] = $newPanel->id;
        return $this->adminPanelDetail($type);
    }

    protected function adminEditPanel($type)
    {
        $id = $type['id'];
        $key = $type['key'];
        $user = $this->user;

        $this->updatePath('adminUpdatePanel');
        $telDetail = $user->tel_detail ?? [];
        $telDetail['panel-key'] = $key;
        $telDetail['panel-id'] = $id;
        $user->tel_detail = $telDetail;
        $user->save();

        $fields = [
            'name' => ['label' => 'نام'],
            'url' => ['label' => 'آدرس'],
            'username' => ['label' => 'نام کاربری'],
            'country_id' => ['label' => 'کشور'],
            'password' => ['label' => 'رمز عبور'],
            'panel_type' => ['label' => 'نوع پنل'],
            'system_type' => ['label' => 'سیستم'],
            'sub_address' => ['label' => 'لینک ساب',],
            'status' => ['label' => 'وضعیت'],

        ];

        $panel = Panels::find($id);

        $oldValue = $panel->$key ?? '—';

        $text = "✏️ لطفا مقدار <b>{$fields[$key]['label']}</b> را وارد کنید\n";

        $customFiles = ['system_type', 'type', 'panel_type', 'status', 'country_id'];
        if (in_array($key, $customFiles)) {

            if ($key == 'system_type') {
                $keyboard[] = [
                    [
                        'text' => 'X-Sanaie',
                        'callback_data' => "type=adminUpdatePanel|id={$id}|value=sanaie",
                    ],
                    [
                        'text' => 'PasarGuard',
                        'callback_data' => "type=adminUpdatePanel|id={$id}|value=pasarguard",
                    ]
                ];
            } elseif ($key == 'panel_type') {
                $services = Service::where('status', 1)->get();

                $keyboard = [];
                $row = [];

                foreach ($services as $service) {

                    $row[] = [
                        'text' => $service->name,
                        'callback_data' => "type=adminUpdatePanel|id={$id}|value={$service->id}",
                    ];

                    // دو ستونه
                    if (count($row) == 2) {
                        $keyboard[] = $row;
                        $row = [];
                    }
                }

                if (!empty($row)) {
                    $keyboard[] = $row;
                }

            } elseif ($key == 'status') {
                $keyboard[] = [
                    [
                        'text' => 'فعال',
                        'callback_data' => "type=adminUpdatePanel|id={$id}|value=1",
                    ],
                    [
                        'text' => 'غیرفعال',
                        'callback_data' => "type=adminUpdatePanel|id={$id}|value=-1",
                    ],
                ];
            } elseif ($key == 'country_id') {
                $country = Countries::get();
                foreach ($country as $item) {

                    $isSelected = ((int)$panel->country_id === (int)$item->id);

                    if ($isSelected) {
                        $oldValue = $item->name;
                    }
                    $keyboard[] = [
                        [
                            'text' => $item->name . ($isSelected ? ' ✅' : ''),
                            'callback_data' => "type=adminUpdatePanel|id={$id}|value={$item->id}",
                            'style' => $isSelected ? 'success' : ''
                        ]
                    ];
                }
            }
        }
        $text .= "📌 مقدار قبلی: <code>" . htmlspecialchars((string)$oldValue) . "</code>";


        $keyboard[] = $this->adminFooterButtons("type=adminPanelDetail|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');

    }

    protected function adminUpdatePanel($type = null)
    {
        $user = $this->user;
        $key = $user->tel_detail['panel-key'];
        $id = $user->tel_detail['panel-id'];

        $fields = [
            'name' => ['label' => 'نام'],
            'url' => ['label' => 'آدرس'],
            'country_id' => ['label' => 'کشور'],
            'username' => ['label' => 'نام کاربری'],
            'password' => ['label' => 'رمز عبور'],
            'panel_type' => ['label' => 'نوع پنل'],
            'system_type' => ['label' => 'سیستم'],
            'status' => ['label' => 'وضعیت'],
            'sub_address' => ['label' => 'لینک ساب',],

        ];


        $customFiles = ['system_type', 'type', 'panel_type', 'status', 'country_id'];
        if (in_array($key, $customFiles)) {
            $value = $type['value'];
            Panels::where('id', $id)->update([$key => $value]);

        } else {
            $text = rtrim($this->text, '/');
            Panels::where('id', $id)->update([$key => $text]);

            if ($key == 'url') {
                $checkSub = Panels::find($id);
                if (is_null($checkSub->sub_address)) {
                    $scheme = parse_url($text, PHP_URL_SCHEME) ?? 'https';
                    $host = parse_url($text, PHP_URL_HOST);
                    $result = $scheme . '://' . $host . ':2096/sub/';
                    $checkSub->sub_address = $result;
                    $checkSub->save();
                }
            }
        }

        $text = "فیلد `{$fields[$key]['label']}` با موفقیت ویرایش شد.";

        $keyboard[] = $this->adminFooterButtons("type=adminPanelDetail|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');

    }

    protected function adminConnectPanel($type)
    {
        $id = $type['id'];

        $panel = Panels::find($id);


        if ($panel->system_type == "pasarguard") {

            $data = [
                'url' => $panel->url,
                'username' => $panel->username,
                'password' => $panel->password,
                'id' => $panel->id,
            ];

            $pasarGuard = new PasarGuard($data);
            $result = $pasarGuard->checkConnection();

            if (!is_null($result)) {
                $Data = [
                    'Domain' => $result,
                ];
                $panel->detail = $Data;
                $panel->save();
                $data = [
                    'callback_query_id' => $this->callbackId,
                    'text' => '✅ اتصال به سرور با موفقیت برقرار شد',
                    'show_alert' => true,
                    'cache_time' => 1,
                ];
            } else {
                $data = [
                    'callback_query_id' => $this->callbackId,
                    'text' => '❌ اتصال به سرور برقرار نشد',
                    'show_alert' => true,
                    'cache_time' => 1,
                ];
            }
        } else {
            $data = [
                'url' => $panel->url,
                'username' => $panel->username,
                'password' => $panel->password
            ];
            $session = loginToSanaie($data);
            if (!empty($session['session'])) {
                $data = [
                    'callback_query_id' => $this->callbackId,
                    'text' => '✅ اتصال به سرور با موفقیت برقرار شد',
                    'show_alert' => true,
                    'cache_time' => 1,
                ];
            } else {
                $data = [
                    'callback_query_id' => $this->callbackId,
                    'text' => '❌ اتصال به سرور برقرار نشد',
                    'show_alert' => true,
                    'cache_time' => 1,
                ];
            }

        }

        return $this->telegramSdk->answerCallback($data);
    }

    protected function adminGetInbounds($type)
    {
        $id = $type['id'];

        $panel = Panels::find($id);
        if ($panel->system_type == "pasarguard") {

            $data = [
                'url' => $panel->url,
                'username' => $panel->username,
                'password' => $panel->password,
                'id' => $panel->id,
            ];
            $pasarGuard = new PasarGuard($data);
            $result = $pasarGuard->getGroups();

            foreach ($result['groups'] as $item) {
                $newInbound = Inbounds::where('panel_id', $panel->id)->where('inbound_id', $item['id'])->first();
                if (is_null($newInbound)) {
                    $newInbound = new Inbounds();
                    $newInbound->status = -1;
                }
                $newInbound->panel_id = $panel->id;
                $newInbound->inbound_id = $item['id'];
                $newInbound->remark = $item['name'];
                $newInbound->save();
            }

            $newInbound = Inbounds::where('panel_id', $panel->id)->pluck('remark')->implode(',');

            $data = [
                'callback_query_id' => $this->callbackId,
                'text' => "✅ گروه ها با موفقیت دریافت شدند.
گروه های دریافت شده: $newInbound",
                'show_alert' => true,
                'cache_time' => 1,
            ];

        } else {
            $data = [
                'url' => $panel->url,
                'username' => $panel->username,
                'password' => $panel->password
            ];

            $session = loginToSanaie($data);

            if (!empty($session['session'])) {
                $data = [
                    'session' => $session['session'],
                    'url' => $panel->url
                ];
                $inbounds = getInbounds($data);
                if (!empty($inbounds)) {
                    foreach ($inbounds['inbounds'] as $item) {
                        $newInbound = Inbounds::where('panel_id', $panel->id)->where('inbound_id', $item['id'])->first();
                        if (is_null($newInbound)) {
                            $newInbound = new Inbounds();
                            $newInbound->status = $item['enable'] ? 1 : 0;
                        }
                        $newInbound->panel_id = $panel->id;
                        $newInbound->inbound_id = $item['id'];
                        $newInbound->port = $item['port'];
                        $newInbound->remark = $item['remark'];
                        $newInbound->setting = $item;
                        $newInbound->save();
                    }
                    $newInbound = Inbounds::where('panel_id', $panel->id)->where('inbound_id', $item['id'])->pluck('remark')->implode(',');

                    $data = [
                        'callback_query_id' => $this->callbackId,
                        'text' => "✅ اینبوند ها با موفقیت دریافت شدند.
گروه های دریافت شده: $newInbound",
                        'show_alert' => true,
                        'cache_time' => 1,
                    ];
                } else {
                    $data = [
                        'callback_query_id' => $this->callbackId,
                        'text' => '❌ اینبوندی بر روی سرور یافت نشد',
                        'show_alert' => true,
                        'cache_time' => 1,
                    ];
                }
            } else {
                $data = [
                    'callback_query_id' => $this->callbackId,
                    'text' => '❌ اتصال به سرور برقرار نشد',
                    'show_alert' => true,
                    'cache_time' => 1,
                ];
            }
        }
        return $this->telegramSdk->answerCallback($data);

    }

    protected function adminPanelDeleteDetail($type)
    {
        $id = $type['id'];

        $service = Panels::find($id);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('❌ پنل موردنظر پیدا نشد.');
        }

        $status = match ((int)$service->status) {
            1 => '🟢 فعال',
            -1 => '🔴 غیرفعال',
            default => '🟡 معلق'
        };

        $text = "╔════════════════════╗\n";
        $text .= "      🗑 حذف پنل\n";
        $text .= "╚════════════════════╝\n\n";

        $text .= "⚠️ آیا از حذف این پنل اطمینان دارید؟\n\n";

        $text .= "📦 نام پنل:\n";
        $text .= "<code>{$service->name}</code>\n\n";

        $text .= "📊 وضعیت:\n";
        $text .= "<code>{$status}</code>\n\n";

        $text .= "❗ پس از حذف، امکان بازگردانی تعرفه وجود نخواهد داشت.\n\n";

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard = [];

        $keyboard[] = [
            [
                'text' => '🗑 بله، حذف شود',
                'callback_data' => "type=adminPanelDeleteSubmit|id={$id}",
                'style' => 'danger'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons(
            "type=adminPlanDetail|id={$id}"
        );

        /*
        |--------------------------------------------------------------------------
        | Send Message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminPanelDeleteSubmit($type)
    {
        $id = $type['id'];

        $service = Panels::find($id);

        if (is_null($service)) {
            return $this->sendTemporaryMessage('پنل پیدا نشد');
        }

        $service->delete();

        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => "پنل با موفقیت حذف شد.",
            'show_alert' => true,
            'cache_time' => 1,
        ]);


        return $this->adminPanels(['page' => 1]);
    }

    // End Panels


    // Start Plans
    protected function adminPlans($type)
    {
        $page = $type['page'] ?? 1;

        $list = Plans::orderByDesc('id')
            ->paginate(10, ['*'], 'page', $page);

        $text = "📦 <b>لیست تعرفه ها</b>\n";
        $text .= "📄 صفحه: <code>{$list->currentPage()}</code>\n";
        $text .= "📊 تعداد: <code>{$list->total()}</code>\n\n";

        $keyboard = [];
        $row = [];


        foreach ($list as $plan) {

            // نوع پلن
            $planType = Service::find($plan->type);
            if (!is_null($planType)) {
                $planType = $planType->name;
            } else {
                $planType = '--';
            }

            // وضعیت
            $status = ((int)$plan->status === 1)
                ? '🟢 فعال'
                : '🔴 غیرفعال';

            $row[] = [
                'text' => "نام:{$plan->name} | {$planType} | {$status}",
                'callback_data' => "type=adminPlanDetail|id={$plan->id}"
            ];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        // صفحه‌بندی
        $pagination = $this->paginationFooterButton($list, $page, 'adminPlans');
        if (!is_null($pagination)) {
            $keyboard[] = $pagination;
        }

        // ایجاد پلن
        $keyboard[] = [
            [
                'text' => '➕ ایجاد پلن جدید',
                'callback_data' => 'type=adminPlanCreate',
                'style' => 'success'
            ]
        ];

        // برگشت

        $keyboard[] = $this->adminFooterButtons('type=adminPanelMenu');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function adminPlanCreate($type)
    {
        $user = $this->user;

        $newPanel = new Plans();
        $newPanel->admin_id = $user->id;
        $newPanel->status = 0;
        $newPanel->save();

        $type['id'] = $newPanel->id;
        return $this->adminPlanDetail($type);
    }

    protected function adminPlanDetail($type)
    {
        $id = $type['id'];

        $plan = Plans::find($id);

        if (is_null($plan)) {
            return $this->sendTemporaryMessage('پلن پیدا نشد، دوباره تلاش کنید');
        }

        $fields = [
            'name' => ['label' => 'نام پلن', 'value' => $plan->name],
            'bandwidth' => ['label' => 'حجم', 'value' => $plan->bandwidth],
            'days' => ['label' => 'مدت زمان', 'value' => $plan->days],
            'price' => ['label' => 'قیمت (تومان)', 'value' => $plan->price],
            'type' => ['label' => 'نوع', 'value' => $plan->type],
            'status' => ['label' => 'وضعیت', 'value' => $plan->status],
            'discount' => ['label' => 'درصد تخفیف', 'value' => $plan->discount],
        ];

        $text = "📦 <b>جزئیات پلن</b>\n\n";

        $keyboard = [];
        $row = [];

        foreach ($fields as $key => $field) {

            $isEmpty = ($field['value'] === null || $field['value'] === '');

            // نمایش وضعیت در متن
            $valueText = $isEmpty
                ? '—'
                : htmlspecialchars((string)$field['value']);

            if ($key == 'type') {

                $service = Service::find($field['value']);
                if (!is_null($service)) {
                    $valueText = htmlspecialchars($service->name);
                } else {
                    $valueText = "--";
                }
            } elseif ($key == 'price') {
                $valueText = number_format($plan->price) . ' تومان ';
            } elseif ($key == 'status') {
                $valueText = match ((int)$field['value']) {
                    1 => '🟢 فعال',
                    -1 => '🔴 غیرفعال',
                    0 => '🟡 معلق',
                    default => '⚪ نامشخص',
                };
            }

            $text .= "▪️ <b>{$field['label']}</b>: <code>{$valueText}</code>\n";

            // دکمه‌ها
            $btnText = $isEmpty
                ? "🔴 " . $field['label']
                : $field['label'];

            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminEditPlan|id={$id}|key={$key}"
            ];

            // هر دو دکمه یک ردیف
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        $price = number_format(calculatePlanDiscount($plan)['price']);
        $text .= "▪️ <b>قیمت نهایی</b>: <code>{$price}</code>\n";

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        $keyboard[] = [
            [
                'text' => 'حذف پلن',
                'callback_data' => "type=adminPlanDeleteDetail|id={$id}",
                'style' => 'danger'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons('type=adminPlans');


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminEditPlan($type)
    {
        $id = $type['id'];
        $key = $type['key'];
        $user = $this->user;

        $this->updatePath('adminUpdatePlan');
        $telDetail = $user->tel_detail ?? [];
        $telDetail['plan-key'] = $key;
        $telDetail['plan-id'] = $id;
        $user->tel_detail = $telDetail;
        $user->save();

        $plan = Plans::find($id);

        $fields = [
            'name' => ['label' => 'نام پلن', 'value' => $plan->name],
            'bandwidth' => ['label' => 'حجم', 'value' => $plan->bandwidth],
            'days' => ['label' => 'مدت زمان', 'value' => $plan->days],
            'price' => ['label' => 'قیمت (تومان)', 'value' => $plan->price],
            'type' => ['label' => 'نوع', 'value' => $plan->type],
            'status' => ['label' => 'وضعیت', 'value' => $plan->status],
            'discount' => ['label' => 'درصد تخفیف', 'value' => $plan->discount],
        ];
        $oldValue = $plan->$key ?? '—';

        $text = "✏️ لطفا مقدار <b>{$fields[$key]['label']}</b> را وارد کنید\n";
        if ($key == 'bandwidth') {
            $text .= "📌 حجم را به گیگ وارد کنید";
        }
        if ($key == 'price') {
            $text .= "📌 مبلغ را به تومان وارد کنید";
        }

        $customFiles = ['type', 'status'];
        if (in_array($key, $customFiles)) {

            if ($key == 'type') {
                $services = Service::where('status', 1)->get();

                $keyboard = [];
                $row = [];

                foreach ($services as $service) {

                    if ($service->id == $plan->type) {
                        $oldValue = $service->name;
                    }

                    $row[] = [
                        'text' => $service->name,
                        'callback_data' => "type=adminUpdatePlan|id={$id}|value={$service->id}",
                    ];

                    // دو ستونه
                    if (count($row) == 2) {
                        $keyboard[] = $row;
                        $row = [];
                    }
                }

                if (!empty($row)) {
                    $keyboard[] = $row;
                }
            } elseif ($key == 'status') {
                $keyboard[] = [
                    [
                        'text' => 'غیرفعال',
                        'callback_data' => "type=adminUpdatePlan|id={$id}|value=-1",
                    ],
                    [
                        'text' => 'فعال',
                        'callback_data' => "type=adminUpdatePlan|id={$id}|value=1",
                    ],
                ];
            }

        }
        $text .= "📌 مقدار قبلی: <code>" . htmlspecialchars((string)$oldValue) . "</code> \n";


        $keyboard[] = $this->adminFooterButtons("type=adminPlanDetail|id={$id}");


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminUpdatePlan($type = null)
    {
        $this->updatePath('start');

        $user = $this->user;
        $key = $user->tel_detail['plan-key'];
        $id = $user->tel_detail['plan-id'];

        $fields = [
            'name' => ['label' => 'نام پلن'],
            'bandwidth' => ['label' => 'حجم'],
            'days' => ['label' => 'مدت زمان'],
            'price' => ['label' => 'قیمت'],
            'type' => ['label' => 'نوع'],
            'status' => ['label' => 'وضعیت'],
            'discount' => ['label' => 'درصد تخفیف'],

        ];

        $customFiles = ['type', 'status'];
        if (in_array($key, $customFiles)) {
            $value = $type['value'];
            Plans::where('id', $id)->update([$key => $value]);

        } else {
            Plans::where('id', $id)->update([$key => $this->text]);
        }

        $text = "فیلد `{$fields[$key]['label']}` با موفقیت ویرایش شد.";

        $keyboard[] = $this->adminFooterButtons("type=adminPlanDetail|id={$id}");


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminPlanDeleteDetail($type)
    {
        $id = $type['id'];

        $service = Plans::find($id);
        if (is_null($service)) {
            return $this->sendTemporaryMessage('❌ تعرفه موردنظر پیدا نشد.');
        }


        /*
        |--------------------------------------------------------------------------
        | Service Details
        |--------------------------------------------------------------------------
        */


        $status = match ((int)$service->status) {
            1 => '🟢 فعال',
            -1 => '🔴 غیرفعال',
            default => '🟡 معلق'
        };

        $text = "╔════════════════════╗\n";
        $text .= "      🗑 حذف تعرفه\n";
        $text .= "╚════════════════════╝\n\n";

        $text .= "⚠️ آیا از حذف این تعرفه اطمینان دارید؟\n\n";

        $text .= "📦 نام تعرفه:\n";
        $text .= "<code>{$service->name}</code>\n\n";

        $text .= "📊 وضعیت:\n";
        $text .= "<code>{$status}</code>\n\n";

        $text .= "❗ پس از حذف، امکان بازگردانی تعرفه وجود نخواهد داشت.\n\n";

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard = [];

        $keyboard[] = [
            [
                'text' => '🗑 بله، حذف شود',
                'callback_data' => "type=adminPlanDeleteSubmit|id={$id}",
                'style' => 'danger'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons(
            "type=adminPlanDetail|id={$id}"
        );

        /*
        |--------------------------------------------------------------------------
        | Send Message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminPlanDeleteSubmit($type)
    {
        $id = $type['id'];

        $service = Plans::find($id);

        if (is_null($service)) {
            return $this->sendTemporaryMessage('تعرفه پیدا نشد');
        }

        $service->delete();

        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => "تعرفه با موفقیت حذف شد.",
            'show_alert' => true,
            'cache_time' => 1,
        ]);


        return $this->adminPlans(['page' => 1]);
    }

    // End Plans

    // Start Setting

    protected function adminCustomerLoyalty($type = [])
    {
        if (!$this->isAdmin) return $this->denyAdminAccess();
        try {
            $data = app(CustomerLoyaltyService::class)->request('settings');
        } catch (\RuntimeException $e) {
            return $this->sendTemporaryMessage($e->getMessage());
        }
        $lines = array_map(fn ($t) => ($t['min'] + $t['strict']).':'.$t['percent'], $data['customer']);
        $this->updatePath('adminCustomerLoyaltySave');
        $text = "تخفیف پلکانی کاربران عادی\nمرجع مشترک: سایت | واحد: تومان\n\n".implode("\n", $lines);
        $text .= "\n\nچهار خط به شکل مبلغ:درصد ارسال کنید. خط اول از صفر شروع شود؛ مرزها صعودی و درصدها غیرکاهشی باشند. مرز هر پله متعلق به همان پله است.";
        return $this->sendMessage(['chat_id' => $this->chatId, 'text' => $text,
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => 'بازگشت', 'callback_data' => 'type=adminSetting']]]])], 'message');
    }

    protected function adminCustomerLoyaltySave()
    {
        if (!$this->isAdmin) return $this->denyAdminAccess();
        $rows = preg_split('/\R/u', trim($this->text));
        $tiers = [];
        if (count($rows) !== 4) return $this->sendTemporaryMessage('دقیقاً چهار خط مبلغ:درصد وارد کنید.');
        foreach ($rows as $row) {
            if (!preg_match('/^\s*(0|[1-9][0-9]{0,11})\s*:\s*(100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)\s*$/D', $row, $m)) {
                return $this->sendTemporaryMessage('مبلغ صحیح نامنفی و درصد صفر تا صد وارد کنید.');
            }
            $tiers[] = ['min' => (int) $m[1], 'percent' => (float) $m[2], 'strict' => 0];
        }
        $last = -1; $percent = -1;
        foreach ($tiers as $i => $tier) {
            if (($i === 0 && $tier['min'] !== 0) || $tier['min'] <= $last || $tier['percent'] < $percent) {
                return $this->sendTemporaryMessage('پله پایه از صفر؛ مرزها صعودی و درصدها غیرکاهشی باشند.');
            }
            $last = $tier['min']; $percent = $tier['percent'];
        }
        try {
            app(CustomerLoyaltyService::class)->request('settings', ['customer' => $tiers]);
        } catch (\RuntimeException $e) {
            return $this->sendTemporaryMessage($e->getMessage());
        }
        $this->updatePath('adminSetting');
        return $this->sendTemporaryMessage('تنظیمات مشترک سایت و ربات ذخیره شد.');
    }

    protected function adminCustomerDiscount($type = [])
    {
        if (!$this->isAdmin) return $this->denyAdminAccess();
        $target = User::find((int) ($type['id'] ?? 0));
        if (!$target || (int) $target->is_seller === 1) return $this->sendTemporaryMessage('این بخش فقط برای کاربر عادی است.');
        try {
            $profile = app(CustomerLoyaltyService::class)->profile($target);
        } catch (\RuntimeException $e) {
            return $this->sendTemporaryMessage($e->getMessage());
        }
        $detail = $this->user->tel_detail ?? [];
        $detail['customer-discount-user-id'] = $target->id;
        $this->user->tel_detail = $detail;
        $this->user->save();
        $this->updatePath('adminCustomerDiscountSave');
        $value = ($profile['manual_value'] ?? '') === '' ? 'خالی (پلکانی)' : $profile['manual_value'].'٪';
        return $this->sendMessage(['chat_id' => $this->chatId,
            'text' => "تخفیف ثابت حساب: {$value}\nدرصد صفر تا صد را ارسال کنید. صفر یعنی بدون تخفیف. برای خالی‌کردن و بازگشت به پلکان، دکمه زیر را بزنید.",
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => 'پاک‌کردن تخفیف ثابت', 'callback_data' => 'type=adminCustomerDiscountClear|id='.$target->id]],
                [['text' => 'بازگشت', 'callback_data' => 'type=adminUserDetail|id='.$target->id]],
            ]])], 'message');
    }

    protected function adminCustomerDiscountSave($type = null)
    {
        if (!$this->isAdmin) return $this->denyAdminAccess();
        $clear = ($type['type'] ?? '') === 'adminCustomerDiscountClear';
        $target = User::find((int) ($clear ? ($type['id'] ?? 0) : ($this->user->tel_detail['customer-discount-user-id'] ?? 0)));
        if (!$target || (int) $target->is_seller === 1) return $this->sendTemporaryMessage('کاربر عادی پیدا نشد.');
        $value = $clear ? '' : trim($this->text);
        if (!$clear && !preg_match('/^(100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)$/D', $value)) {
            return $this->sendTemporaryMessage('درصد صفر تا صد با حداکثر دو رقم اعشار وارد کنید.');
        }
        try {
            app(CustomerLoyaltyService::class)->request('override', ['tel_id' => (string) $target->tel_id, 'value' => $value]);
        } catch (\RuntimeException $e) {
            return $this->sendTemporaryMessage($e->getMessage());
        }
        $this->updatePath('adminUserDetail');
        return $this->sendTemporaryMessage('تخفیف ثابت حساب ذخیره شد.');
    }

    protected function adminSetting($type)
    {
        $keys = ['join-bot', 'join-with-referral', 'channel-join'];
        $settings = Setting::whereIn('key', $keys)->get();
        $buttons = [[['text' => 'تخفیف پلکانی کاربران عادی', 'callback_data' => 'type=adminCustomerLoyalty']]];

        foreach ($settings as $setting) {
            $buttons[] = [
                [
                    'text' => ($setting->value != 1 ? "✅" : null) . "غیرفعال",
                    'callback_data' => "type=adminSettingChangeValue|key={$setting->key}|value=-1"
                ], [
                    'text' => ($setting->value == 1 ? "✅" : null) . "فعال",
                    'callback_data' => "type=adminSettingChangeValue|key={$setting->key}|value=1"
                ],
                [
                    'text' => "{$setting->name}:",
                    'callback_data' => 'type=adminSettingSell',
                    'style' => 'primary'
                ],
            ];
        }
        $buttons[] = [
            [
                'text' => "═══════════════════",
                'callback_data' => 'type=adminSettingSell',
                'style' => 'danger'
            ],
        ];

        $buttons[] = [

            [
                'text' => "مشاهده تنظمیات",
                'callback_data' => 'type=adminSettingSell'
            ],
            [
                'text' => "تنظیمات فروش",
                'callback_data' => 'type=adminSettingSell',
                'style' => 'primary'
            ],
        ];

        $buttons[] = [

            [
                'text' => "مشاهده تنظمیات",
                'callback_data' => 'type=adminPaymentSetting'
            ],
            [
                'text' => "تنظیمات پرداخت",
                'callback_data' => 'type=adminPaymentSetting',
                'style' => 'primary'
            ],
        ];
        $buttons[] = [

            [
                'text' => "مشاهده تنظمیات",
                'callback_data' => 'type=adminBotSetting'
            ],
            [
                'text' => "تنظیمات ربات",
                'callback_data' => 'type=adminBotSetting',
                'style' => 'primary'
            ],
        ];


        $buttons[] = $this->adminFooterButtons();

        $text = headTitle('⚙️ تنظیمات سیستم');
        $text .= "🔧 مدیریت تنظیمات ثبت‌نام، فروش،
پرداخت، رفرال و امکانات ربات

📌 لطفاً یکی از گزینه‌های زیر را انتخاب کنید.
";
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];
        return $this->sendMessage($data, 'message');

    }

    protected function adminSettingChangeValue($data)
    {
        $key = $data['key'];
        $value = $data['value'];

        $setting = Setting::where('key', $key)->first();
        $setting->value = $value;
        $setting->save();

        return $this->adminSetting($data);
    }

    protected function adminSettingSell()
    {
        /*
        |--------------------------------------------------------------------------
        | Load or create settings
        |--------------------------------------------------------------------------
        */

        $settings = [
            'sell' => 'وضعیت فروش',
            'renew' => 'وضعیت تمدید',
            'extra' => 'وضعیت خرید حجم اضافه',
            'referral' => 'وضعیت پورسانت',
        ];

        $dataMap = [];

        foreach ($settings as $key => $label) {

            $row = Setting::firstOrCreate(
                ['key' => $key],
                [
                    'name' => $label,
                    'value' => 0
                ]
            );

            $dataMap[$key] = $row;
        }

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = "⚙️ <b>تنظیمات فروش</b>\n\n";
        $text .= "در این بخش می‌توانید تنظیمات فروش را مدیریت کنید.\n";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $buttons = [];

        foreach ($dataMap as $key => $setting) {

            $isActive = ((int)$setting->value === 1);

            $statusText = $isActive ? '🟢 فعال' : '🔴 غیرفعال';

            $buttons[] = [
                [
                    'text' => "{$setting->name} ({$statusText})",
                    'callback_data' => "type=adminToggleSetting|key={$key}",
                    'style' => $isActive ? 'success' : 'danger'
                ]
            ];
        }
        $buttons[] = [
            [
                'text' => '💰  درصد پورسانت',
                'callback_data' => 'type=adminSettingCommission',
            ],
            [
                'text' => '📝 متن پورسانت',
                'callback_data' => 'type=adminSettingCommissionText',
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Home button
        |--------------------------------------------------------------------------
        */
        $buttons[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => 'type=adminSetting',
            ]
        ];

        $buttons[] = [
            [
                'text' => '🏠 منو اصلی',
                'callback_data' => 'type=admin-home',
                'style' => 'primary'
            ]
        ];

        /*
        |--------------------------------------------------------------------------
        | Send message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminToggleSetting($type)
    {
        $key = $type['key'] ?? null;

        if (!$key) {
            return $this->sendTemporaryMessage('کلید تنظیمات نامعتبر است');
        }

        /*
        |--------------------------------------------------------------------------
        | Find or create setting
        |--------------------------------------------------------------------------
        */

        $setting = Setting::firstOrCreate(
            ['key' => $key],
            [
                'name' => $key,
                'value' => 0
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Toggle value
        |--------------------------------------------------------------------------
        */

        $setting->value = ((int)$setting->value === 1) ? 0 : 1;
        $setting->save();

        /*
        |--------------------------------------------------------------------------
        | Message
        |--------------------------------------------------------------------------
        */

        $statusText = $setting->value == 1 ? 'فعال شد 🟢' : 'غیرفعال شد 🔴';

        /*
        |--------------------------------------------------------------------------
        | Alert
        |--------------------------------------------------------------------------
        */

        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => $statusText,
            'show_alert' => true,
            'cache_time' => 1,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Reload page
        |--------------------------------------------------------------------------
        */

        return $this->adminSettingSell();
    }

    protected function adminSettingCommission()
    {

        /*
        |--------------------------------------------------------------------------
        | Get or create setting
        |--------------------------------------------------------------------------
        */

        $setting = Setting::firstOrCreate(
            ['key' => 'commission'],
            [
                'name' => 'درصد پورسانت',
                'value' => 0
            ]
        );

        $value = (int)$setting->value;

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = "⚙️ <b>تنظیم درصد پورسانت</b>\n\n";

        $text .= "💰 مقدار فعلی پورسانت: <b>{$value}%</b>\n\n";

        $text .= "✏️ لطفا درصد جدید را وارد کنید.\n";
        $text .= "این مقدار روی تمام محاسبات فروش اعمال خواهد شد.";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $buttons = [];

        $buttons[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => 'type=adminSettingSell',
                'style' => 'danger'
            ]
        ];

        /*
        |--------------------------------------------------------------------------
        | Send
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminSettingCommissionEdit()
    {
        /*
        |--------------------------------------------------------------------------
        | Get or create setting
        |--------------------------------------------------------------------------
        */

        $setting = Setting::firstOrCreate(
            ['key' => 'commission'],
            [
                'name' => 'درصد پورسانت',
                'value' => 0
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Input
        |--------------------------------------------------------------------------
        */

        $value = trim($this->text);

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($value === '' || !is_numeric($value)) {
            return $this->sendTemporaryMessage('❌ لطفا فقط عدد وارد کنید');
        }

        $value = (float)$value;

        if ($value < 0 || $value > 100) {
            return $this->sendTemporaryMessage('❌ درصد باید بین 0 تا 100 باشد');
        }

        /*
        |--------------------------------------------------------------------------
        | Save
        |--------------------------------------------------------------------------
        */

        $setting->value = $value;
        $setting->save();

        /*
        |--------------------------------------------------------------------------
        | Success message
        |--------------------------------------------------------------------------
        */

        $text = "✅ <b>درصد پورسانت بروزرسانی شد</b>\n\n";
        $text .= "💰 مقدار جدید: <b>{$value}%</b>";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $buttons = [];

        $buttons[] = [
            [
                'text' => '⚙️ بازگشت به تنظیمات پورسانت',
                'callback_data' => 'type=adminSettingSell',
                'style' => 'primary'
            ]
        ];

        $buttons[] = [
            [
                'text' => '🏠 منو اصلی',
                'callback_data' => 'type=admin-home',
                'style' => 'danger'
            ]
        ];

        /*
        |--------------------------------------------------------------------------
        | Send message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminSettingCommissionText()
    {
        /*
        |--------------------------------------------------------------------------
        | Get or create setting
        |--------------------------------------------------------------------------
        */


        $this->updatePath('adminSettingCommissionTextEdit');
        $setting = Setting::firstOrCreate(
            ['key' => 'commission_text'],
            [
                'name' => 'متن پورسانت',
                'value' => 'درصد پورسانت به صورت پیش‌فرض اعمال می‌شود.'
            ]
        );

        $value = $setting->value;

        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = "📝 <b>تنظیم متن پورسانت</b>\n\n";

        $text .= "📌 متن فعلی:\n";
        $text .= "<code>{$value}</code>\n\n";

        $text .= "✏️ لطفا متن جدید را ارسال کنید.\n";
        $text .= "این متن در بخش فروش و توضیحات نمایش داده می‌شود.";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $buttons = [];


        $buttons[] = [
            [
                'text' => '🔙 بازگشت',
                'callback_data' => 'type=adminSettingSell',
                'style' => 'danger'
            ]
        ];

        /*
        |--------------------------------------------------------------------------
        | Send message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminSettingCommissionTextEdit()
    {
        /*
        |--------------------------------------------------------------------------
        | Get setting (or create fallback)
        |--------------------------------------------------------------------------
        */

        $setting = Setting::firstOrCreate(
            ['key' => 'commission_text'],
            [
                'name' => 'متن پورسانت',
                'value' => ''
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | New value from user
        |--------------------------------------------------------------------------
        */

        $newText = trim($this->text);

        if (empty($newText)) {
            return $this->sendTemporaryMessage('❌ متن نمی‌تواند خالی باشد');
        }

        /*
        |--------------------------------------------------------------------------
        | Save
        |--------------------------------------------------------------------------
        */

        $setting->value = $newText;
        $setting->save();

        /*
        |--------------------------------------------------------------------------
        | Success message
        |--------------------------------------------------------------------------
        */

        $text = "✅ <b>متن پورسانت با موفقیت بروزرسانی شد</b>\n\n";

        $text .= "📌 متن جدید:\n";
        $text .= "<code>{$newText}</code>";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $buttons = [];

        $buttons[] = [
            [
                'text' => '⚙️ بازگشت به تنظیمات',
                'callback_data' => 'type=adminSettingSell',
                'style' => 'primary'
            ]
        ];

        $buttons[] = [
            [
                'text' => '🏠 منو اصلی',
                'callback_data' => 'type=admin-home',
                'style' => 'danger'
            ]
        ];

        /*
        |--------------------------------------------------------------------------
        | Send message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ]),
        ];

        /*
        |--------------------------------------------------------------------------
        | Clear user state (اگر داری state system)
        |--------------------------------------------------------------------------
        */

        // $this->clearPath(); // اگر سیستم مسیر داری

        return $this->sendMessage($data, 'message');
    }

    protected function adminPaymentSetting()
    {
        $buttons = [];

        $buttons[] = [

            [
                'text' => "مشاهده تنظمیات",
                'callback_data' => 'ignore'
            ],
            [
                'text' => "تنظیمات درگاه",
                'callback_data' => 'ignore',
                'style' => 'primary'
            ],
        ];

        $buttons[] = [

            [
                'text' => "مشاهده تنظمیات",
                'callback_data' => 'type=adminCartSetting'
            ],
            [
                'text' => "تنظیمات کارت به کارت",
                'callback_data' => 'type=adminCartSetting',
                'style' => 'primary'
            ],
        ];

        $buttons[] = [
            [
                'text' => "مشاهده تنظمیات",
                'callback_data' => 'type=adminChargeAmount'
            ],
            [
                'text' => "مبالغ شارژ کیف پول",
                'callback_data' => 'ignore',
                'style' => 'primary'
            ],
        ];


        $buttons[] = $this->adminFooterButtons("type=adminSetting");

        $text = "تنظیمات پرداخت";

        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function adminCartSetting()
    {


        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = headTitle("تنظیمات پرداخت");
        $text .= "در این بخش می‌توانید تنظیمات پرداخت را مدیریت کنید.\n";

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $buttons = [];

        $cartBeCart = Setting::firstOrCreate(
            ['key' => 'cart_be_cart'],
            [
                'name' => 'وضعیت کارت به کارت',
                'value' => 0,
            ]
        );

        $cartBeCartRandom = Setting::firstOrCreate(
            ['key' => 'cart_be_cart_random'],
            [
                'name' => 'نمایش تصادفی کارت‌ها',
                'value' => 0,
            ]
        );

        $cartBeCartStatus = (int)$cartBeCart->value;
        $cartBeCartRandomStatus = (int)$cartBeCartRandom->value;

        /*
        |--------------------------------------------------------------------------
        | وضعیت کارت به کارت
        |--------------------------------------------------------------------------
        */

        $buttons[] = [
            [
                'text' => "💳 وضعیت کارت به کارت",
                'callback_data' => "ignore",
                'style' => 'primary'
            ]
        ];

        $buttons[] = [
            [
                'text' => ($cartBeCartStatus === 1 ? '✅ ' : '') . "فعال",
                'callback_data' => "type=adminCartBeCartStatus|status=1",
            ],
            [
                'text' => ($cartBeCartStatus === 0 ? '✅ ' : '') . "غیرفعال",
                'callback_data' => "type=adminCartBeCartStatus|status=0",
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | نمایش تصادفی کارت‌ها
        |--------------------------------------------------------------------------
        */

        $buttons[] = [
            [
                'text' => "🎲 نمایش تصادفی کارت‌ها",
                'callback_data' => "ignore",
                'style' => 'primary'
            ]
        ];

        $buttons[] = [
            [
                'text' => ($cartBeCartRandomStatus === 1 ? '✅ ' : '') . "فعال",
                'callback_data' => "type=adminCartBeCartRandom|status=1",
            ],
            [
                'text' => ($cartBeCartRandomStatus === 0 ? '✅ ' : '') . "غیرفعال",
                'callback_data' => "type=adminCartBeCartRandom|status=0",
            ],
        ];

//        $buttons[] = [
//            [
//                'text' => "متن صفحه کارت به کارت",
//                'callback_data' => "type=adminCartBeCartText",
//            ]
//        ];

        $buttons[] = [
            [
                'text' => "شماره کارت‌ها و شباها",
                'callback_data' => "type=adminCartList",
            ]
        ];


        $buttons[] = $this->adminFooterButtons('type=adminSetting');

        /*
        |--------------------------------------------------------------------------
        | Send message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCartBeCartStatus($type)
    {
        $status = $type['status'] ?? 0;

        Setting::where('key', 'cart_be_cart')
            ->update([
                'value' => $status
            ]);

        return $this->adminCartSetting();
    }

    protected function adminCartBeCartRandom($type)
    {
        $status = $type['status'] ?? 0;

        Setting::where('key', 'cart_be_cart_random')
            ->update([
                'value' => $status
            ]);

        return $this->adminCartSetting();
    }

    protected function adminCartBeCartText($type)
    {
        $this->updatePath('adminCartBeCartTextUpdate');

        $setting = Setting::firstOrCreate(
            ['key' => 'cart_be_cart_text'],
            ['value' => '']
        );

        $oldValue = $setting->value ?? '—';

        $text = headTitle("💳 متن کارت به کارت");
        $text .= "✏️ لطفا متن کارت به کارت را وارد کنید.\n\n";
        $text .= "📌 متن فعلی:\n";
        $text .= "<code>" . htmlspecialchars($oldValue) . "</code>";

        $keyboard[] = $this->adminFooterButtons('type=adminCartSetting');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCartBeCartTextUpdate()
    {
        $setting = Setting::firstOrCreate(
            ['key' => 'cart_be_cart_text'],
            ['value' => '']
        );

        if (empty($this->text)) {
            return $this->sendTemporaryMessage('❌ متن نمی‌تواند خالی باشد');
        }

        $setting->value = $this->text;
        $setting->save();

        return $this->adminCartSetting();
    }

    protected function adminCartList()
    {

        $page = $type['page'] ?? 1;

        $list = Carts::orderbyDesc('id')
            ->paginate(10, ['*'], 'page', $page);


        $text = "🌍 <b>لیست کارت ها</b>\n";


        $keyboard = [];
        $row = [];
        foreach ($list as $country) {

            $row[] = [
                'text' => "{$country->cart}",
                'callback_data' => "type=adminCartDetail|id={$country->id}",
            ];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }
        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = $this->paginationFooterButton($list, $page, 'adminCountries');

        if (!empty($pagination)) {
            $keyboard[] = $pagination;
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = [
            [
                'text' => '➕ ایجاد کارت جدید',
                'callback_data' => 'type=adminCartCreate',
                'style' => 'success'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons('type=adminCartSetting');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCartCreate()
    {
        $user = $this->user;

        $country = new Carts();
        $country->status = 0;
        $country->admin_id = $user->id;
        $country->save();

        $type['id'] = $country->id;

        return $this->adminCartDetail($type);


    }

    protected function adminCartDetail($type)
    {
        $id = $type['id'];

        $country = Carts::find($id);

        if (is_null($country)) {
            return $this->sendTemporaryMessage('کارت پیدا نشد');
        }

        $fields = [
            'name' => [
                'label' => 'نام صاحب کارت',
                'value' => $country->name
            ],
            'cart' => [
                'label' => 'شماره کارت',
                'value' => $country->cart
            ],
            'sheba' => [
                'label' => 'شماره شبا',
                'value' => $country->sheba
            ],
            'status' => [
                'label' => 'وضعیت',
                'value' => $country->status
            ],
            'is_default' => [
                'label' => 'کارت پیشفرض',
                'value' => $country->is_default
            ],
        ];

        $text = "🌍 <b>جزئیات کارت</b>\n\n";

        $keyboard = [];
        $row = [];

        foreach ($fields as $key => $field) {

            $isEmpty = (
                $field['value'] === null ||
                $field['value'] === ''
            );

            /*
            |--------------------------------------------------------------------------
            | Value Text
            |--------------------------------------------------------------------------
            */

            if ($key == 'status' || $key == 'is_default') {

                $valueText = match ((int)$field['value']) {
                    1 => '🟢 فعال',
                    -1 => '🔴 غیرفعال',
                    default => '⚪️ نامشخص'
                };

            } else {

                $valueText = $isEmpty
                    ? '—'
                    : htmlspecialchars((string)$field['value']);
            }

            $text .= "▪️ <b>{$field['label']}</b>: <code>{$valueText}</code>\n";

            /*
            |--------------------------------------------------------------------------
            | Buttons
            |--------------------------------------------------------------------------
            */

            $btnText = $isEmpty
                ? "🔴 {$field['label']}"
                : "{$field['label']}";

            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminCartEdit|id={$id}|key={$key}"
            ];

            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = $this->adminFooterButtons('type=adminCartList');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCartEdit($type)
    {
        $id = $type['id'];
        $key = $type['key'];

        $user = $this->user;

        $this->updatePath('adminCartUpdate');

        $telDetail = $user->tel_detail ?? [];

        $telDetail['cart-key'] = $key;
        $telDetail['cart-id'] = $id;

        $user->tel_detail = $telDetail;
        $user->save();

        $country = Carts::find($id);

        if (!$country) {
            return $this->sendTemporaryMessage('کشور پیدا نشد');
        }

        $fields = [
            'name' => ['label' => 'نام صاحب حساب'],
            'cart' => ['label' => 'شماره کارت'],
            'sheba' => ['label' => 'شماره شبا'],
            'status' => ['label' => 'وضعیت'],
            'is_default' => ['label' => 'کارت پیشفرض'],
        ];

        if (!array_key_exists($key, $fields)) {
            return $this->sendTemporaryMessage('فیلد کارت معتبر نیست');
        }

        $oldValue = $country->$key ?? '—';

        /*
        |--------------------------------------------------------------------------
        | Status Text
        |--------------------------------------------------------------------------
        */


        $customFiles = ['status', 'is_default'];
        if (in_array($key, $customFiles)) {

            if ($key == 'status' || $key == 'is_default') {
                $keyboard[] = [
                    [
                        'text' => 'غیرفعال',
                        'callback_data' => "type=adminCartUpdate|id={$id}|value=-1",
                    ],
                    [
                        'text' => 'فعال',
                        'callback_data' => "type=adminCartUpdate|id={$id}|value=1",
                    ],
                ];
            }

        }

        if ($key === 'sheba' && !empty($country->sheba)) {
            $keyboard[] = [
                [
                    'text' => '🗑 خالی کردن شماره شبا',
                    'callback_data' => "type=adminCartUpdate|id={$id}|clear=1",
                    'style' => 'danger',
                ],
            ];
        }


        if ($key == 'status' || $key == 'is_default') {

            $oldValue = match ((int)$oldValue) {
                1 => 'فعال',
                -1 => 'غیرفعال',
                default => 'نامشخص'
            };
        }

        $text = "✏️ لطفا مقدار <b>{$fields[$key]['label']}</b> را وارد کنید\n";
        $text .= "📌 مقدار قبلی: <code>{$oldValue}</code>";
        if ($key === 'sheba') {
            $text .= "\nℹ️ شماره شبا اختیاری است.";
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */
        $keyboard[] = $this->adminFooterButtons("type=adminCartDetail|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCartUpdate($type = null)
    {
        $user = $this->user;

        $key = $user->tel_detail['cart-key'];
        $id = $user->tel_detail['cart-id'];

        $fields = [
            'name' => ['label' => 'نام صاحب کارت'],
            'status' => ['label' => 'وضعیت'],
            'cart' => ['label' => 'شماره کارت'],
            'sheba' => ['label' => 'شماره شبا'],
            'is_default' => ['label' => 'کارت پیشفرض'],
        ];

        if (!array_key_exists($key, $fields)) {
            return $this->sendTemporaryMessage('فیلد کارت معتبر نیست');
        }

        if ($key === 'sheba' && !Schema::hasColumn('carts', 'sheba')) {
            $text = "❌ ستون شماره شبا هنوز به دیتابیس اضافه نشده است.\n"
                . "ابتدا migrationهای پروژه را اجرا کنید و سپس همین مقدار را دوباره ارسال کنید.";

            return $this->sendMessage([
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        $this->adminFooterButtons("type=adminCartDetail|id={$id}"),
                    ],
                ]),
            ], 'message');
        }

        /*
        |--------------------------------------------------------------------------
        | Custom Fields
        |--------------------------------------------------------------------------
        */

        $customFields = ['status', 'is_default'];

        if (in_array($key, $customFields)) {
            $value = $type['value'];
        } elseif ($key === 'sheba' && !empty($type['clear'])) {
            $value = null;
        } else {
            // Text fields, including cart and sheba, follow the exact same
            // update path and are stored exactly as the admin entered them.
            $value = $this->text;
        }

        try {
            Carts::where('id', $id)
                ->update([$key => $value]);
        } catch (\Throwable $exception) {
            Log::error('Failed to update card payment field.', [
                'cart_id' => $id,
                'field' => $key,
                'error' => $exception->getMessage(),
            ]);

            return $this->sendMessage([
                'chat_id' => $this->chatId,
                'text' => '❌ ذخیره اطلاعات کارت انجام نشد. خطا در گزارش‌های سیستم ثبت شد.',
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        $this->adminFooterButtons("type=adminCartDetail|id={$id}"),
                    ],
                ]),
            ], 'message');
        }

        $this->updatePath('start');

        $text = "✅ فیلد <b>{$fields[$key]['label']}</b> با موفقیت بروزرسانی شد.";

        $keyboard[] = $this->adminFooterButtons("type=adminCartDetail|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminBotSetting()
    {

        $text = headTitle("⚙️ تنظیمات ربات");
        $text .= "📌 در این بخش می‌توانید تنظیمات اصلی ربات را مدیریت کنید.\n\n";


        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $buttons = [];

        $buttons[] = [
            [
                'text' => "=====  آیدی کانال ها  =====",
                'callback_data' => "ignore",
                'style' => 'danger'
            ]
        ];
        $buttons[] = [
            [
                'text' => "✅ تراکنشات",
                'callback_data' => "type=adminChangeSetting|k=cart_be_cart_id|p_path=adminBotSetting",
            ]
            , [
                'text' => "📊 گزارشات",
                'callback_data' => "type=adminChangeSetting|k=report_id|p_path=adminBotSetting",
            ]
        ];

        $buttons[] = [
            [
                'text' => "💳 پشتیبانی",
                'callback_data' => "type=adminChangeSetting|k=support_id|p_path=adminBotSetting",
            ], [
                'text' => "✅ کانال",
                'callback_data' => "type=adminChangeSetting|k=channel_id|p_path=adminBotSetting",
            ]
        ];

        $buttons[] = $this->adminFooterButtons('type=adminSetting');

        /*
        |--------------------------------------------------------------------------
        | Send
        |--------------------------------------------------------------------------
        */

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ], 'message');
    }

    protected function adminChangeSetting($type)
    {
        $key = $type['k'] ?? null;
        $prevPath = $type['p_path'] ?? "adminSetting";

        if (!$key) {
            return $this->sendTemporaryMessage('⚠️ کلید تنظیمات مشخص نشده است.');
        }

        $this->updatePath('adminChangeSettingSubmit');

        $user = $this->user;
        $tel_detail = $user->tel_detail ?? [];

        $tel_detail['setting-key'] = $key;
        $tel_detail['setting-prev-path'] = $prevPath;

        $user->tel_detail = $tel_detail;
        $user->save();

        $setting = Setting::where('key', $key)->first();

        if (!$setting) {
            return $this->sendTemporaryMessage('⚠️ تنظیم مورد نظر یافت نشد.');
        }
        $currentValue = $setting->value ?? '—';

        $text = "✏️ <b>ویرایش تنظیم</b>\n\n";
        $text .= "⚙️ <b>{$setting->name}</b>\n";
        $text .= "📌 مقدار فعلی: <code>{$currentValue}</code>\n\n";
        $text .= "✍️ لطفا مقدار جدید را ارسال کنید.";

        $buttons = [];
        $buttons[] = $this->adminFooterButtons("type={$prevPath}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminChangeSettingSubmit($type = null)
    {
        $user = $this->user;
        $tel_detail = $user->tel_detail ?? [];

        $key = $tel_detail['setting-key'] ?? null;
        $prevPath = $tel_detail['setting-prev-path'] ?? 'adminSetting';

        if (!$key) {
            return $this->sendTemporaryMessage('⚠️ کلید تنظیمات یافت نشد.');
        }

        $setting = Setting::where('key', $key)->first();

        if (!$setting) {
            return $this->sendTemporaryMessage('⚠️ تنظیم مورد نظر یافت نشد.');
        }

        // مقدار جدید
        $value = $type['value'] ?? $this->text;

        if ($key == 'support_id' || $key == 'report_id' || $key == 'cart_be_cart_id') {
            $value = str_replace(['https://t.me/', '@'], '', $value);
        }

        if ($value === null || $value === '') {
            return $this->sendTemporaryMessage('❌ مقدار وارد شده معتبر نیست.');
        }

        // ذخیره مقدار
        $setting->value = $value;
        $setting->save();

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        $text = "✅ تنظیم با موفقیت بروزرسانی شد\n\n";
        $text .= "⚙️ <b>{$setting->name}</b>\n";
        $text .= "📌 مقدار جدید: <code>{$setting->value}</code>";

        $buttons[] = $this->adminFooterButtons("type={$prevPath}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons
            ])
        ];

        return $this->sendMessage($data, 'message');
    }

    //Country

    protected function adminCountries($type)
    {
        $page = $type['page'] ?? 1;

        $list = Countries::orderByRaw("CASE WHEN status = '1' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->with('Service')
            ->paginate(10, ['*'], 'page', $page);

        $text = "🌍 <b>لیست کشورها</b>\n";
        $text .= "📄 صفحه: <code>{$list->currentPage()}</code>\n";
        $text .= "📊 تعداد: <code>{$list->total()}</code>\n\n";

        $keyboard = [];
        $row = [];
        foreach ($list as $country) {

            $status = ((int)$country->status === 1) ? '🟢' : '🔴';

            $name = !is_null($country->Service) ? $country->Service->name : '-';
            $row[] = [
                'text' => "{$status} {$country->name} | {$name}",
                'callback_data' => "type=adminCountriesDetail|id={$country->id}",
            ];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }
        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = $this->paginationFooterButton($list, $page, 'adminCountries');

        if (!empty($pagination)) {
            $keyboard[] = $pagination;
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = [
            [
                'text' => '➕ ایجاد کشور جدید',
                'callback_data' => 'type=adminCountriesCreate',
                'style' => 'success'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons('type=adminPanelMenu');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCountriesCreate($type)
    {
        $user = $this->user;

        $country = new Countries();
        $country->status = -1;
        $country->save();

        $type['id'] = $country->id;

        return $this->adminCountriesDetail($type);
    }

    protected function adminCountriesDetail($type)
    {
        $id = $type['id'];

        $country = Countries::find($id);

        if (is_null($country)) {
            return $this->sendTemporaryMessage('کشور پیدا نشد');
        }

        $fields = [
            'name' => [
                'label' => 'نام کشور',
                'value' => $country->name
            ],
            'status' => [
                'label' => 'وضعیت',
                'value' => $country->status
            ],
            'type' => [
                'label' => 'نوع',
                'value' => $country->type
            ],
        ];

        $text = "🌍 <b>جزئیات کشور</b>\n\n";

        $keyboard = [];
        $row = [];

        foreach ($fields as $key => $field) {

            $isEmpty = (
                $field['value'] === null ||
                $field['value'] === ''
            );

            /*
            |--------------------------------------------------------------------------
            | Value Text
            |--------------------------------------------------------------------------
            */

            if ($key == 'status') {

                $valueText = match ((int)$field['value']) {
                    1 => '🟢 فعال',
                    -1 => '🔴 غیرفعال',
                    default => '⚪️ نامشخص'
                };

            } elseif ($key == 'type') {
                $service = Service::find($field['value']);

                $valueText = !is_null($service) ? $service->name : '-';
            } else {

                $valueText = $isEmpty
                    ? '—'
                    : htmlspecialchars((string)$field['value']);
            }

            $text .= "▪️ <b>{$field['label']}</b>: <code>{$valueText}</code>\n";

            /*
            |--------------------------------------------------------------------------
            | Buttons
            |--------------------------------------------------------------------------
            */

            $btnText = $isEmpty
                ? "🔴 {$field['label']}"
                : "🟢 {$field['label']}";

            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminCountriesEdit|id={$id}|key={$key}"
            ];

            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = $this->adminFooterButtons('type=adminCountries');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCountriesEdit($type)
    {
        $id = $type['id'];
        $key = $type['key'];

        $user = $this->user;

        $this->updatePath('adminCountriesUpdate');

        $telDetail = $user->tel_detail ?? [];

        $telDetail['country-key'] = $key;
        $telDetail['country-id'] = $id;

        $user->tel_detail = $telDetail;
        $user->save();

        $country = Countries::find($id);

        if (!$country) {
            return $this->sendTemporaryMessage('کشور پیدا نشد');
        }

        $fields = [
            'name' => ['label' => 'نام کشور'],
            'status' => ['label' => 'وضعیت'],
            'type' => [
                'label' => 'نوع',
            ],
        ];

        $oldValue = $country->$key ?? '—';

        /*
        |--------------------------------------------------------------------------
        | Status Text
        |--------------------------------------------------------------------------
        */


        $customFiles = ['type', 'status'];
        if (in_array($key, $customFiles)) {

            if ($key == 'type') {
                $services = Service::where('status', 1)->get();

                $keyboard = [];
                $row = [];

                foreach ($services as $service) {

                    if ($service->id == $country->type) {
                        $oldValue = $service->name;
                    }

                    $row[] = [
                        'text' => $service->name,
                        'callback_data' => "type=adminCountriesUpdate|id={$id}|value={$service->id}",
                    ];

                    // دو ستونه
                    if (count($row) == 2) {
                        $keyboard[] = $row;
                        $row = [];
                    }
                }

                if (!empty($row)) {
                    $keyboard[] = $row;
                }
            } elseif ($key == 'status') {
                $keyboard[] = [
                    [
                        'text' => 'غیرفعال',
                        'callback_data' => "type=adminCountriesUpdate|id={$id}|value=-1",
                    ],
                    [
                        'text' => 'فعال',
                        'callback_data' => "type=adminCountriesUpdate|id={$id}|value=1",
                    ],
                ];
            }

        }


        if ($key == 'status') {

            $oldValue = match ((int)$oldValue) {
                1 => 'فعال',
                -1 => 'غیرفعال',
                default => 'نامشخص'
            };
        }

        $text = "✏️ لطفا مقدار <b>{$fields[$key]['label']}</b> را وارد کنید\n";
        $text .= "📌 مقدار قبلی: <code>{$oldValue}</code>";

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */
        $keyboard[] = $this->adminFooterButtons("type=adminCountriesDetail|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminCountriesUpdate($type = null)
    {
        $this->updatePath('start');

        $user = $this->user;

        $key = $user->tel_detail['country-key'];
        $id = $user->tel_detail['country-id'];

        $fields = [
            'name' => ['label' => 'نام کشور'],
            'status' => ['label' => 'وضعیت'],
            'type' => ['label' => 'نوع'],
        ];

        /*
        |--------------------------------------------------------------------------
        | Custom Fields
        |--------------------------------------------------------------------------
        */

        $customFields = ['status', 'type'];

        if (in_array($key, $customFields)) {

            $value = $type['value'];

            Countries::where('id', $id)
                ->update([$key => $value]);

        } else {

            Countries::where('id', $id)
                ->update([$key => $this->text]);
        }

        $text = "✅ فیلد <b>{$fields[$key]['label']}</b> با موفقیت بروزرسانی شد.";

        $keyboard[] = $this->adminFooterButtons("type=adminCountriesDetail|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }


    // Admin Service
    protected function adminService($type)
    {
        $page = $type['page'] ?? 1;

        $list = Service::orderByDesc('id')
            ->paginate(10, ['*'], 'page', $page);

        $text = "🌍 <b>لیست سرویس ها</b>\n";
        $text .= "📄 صفحه: <code>{$list->currentPage()}</code>\n";
        $text .= "📊 تعداد: <code>{$list->total()}</code>\n\n";

        $keyboard = [];
        $row = [];


        foreach ($list as $country) {

            $status = ((int)$country->status === 1)
                ? '🟢 فعال'
                : '🔴 غیرفعال';

            $name = !is_null($country->name) ? $country->name : 'بدون نام';
            $row[] = [
                'text' => "{$name} | {$status}",
                'callback_data' => "type=adminServiceDetail|id={$country->id}",
            ];

            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $keyboard[] = $row;
        }
        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = $this->paginationFooterButton($list, $page, 'adminService');

        if (!is_null($pagination)) {
            $keyboard[] = $pagination;
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = [
            [
                'text' => '➕ ایجاد سرویس جدید',
                'callback_data' => 'type=adminServiceCreate',
                'style' => 'success'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons('type=adminPanelMenu');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminServiceCreate($type)
    {
        $user = $this->user;

        $country = new Service();
        $country->status = 0;
        $country->save();

        $type['id'] = $country->id;

        return $this->adminServiceDetail($type);
    }

    protected function adminServiceDetail($type)
    {
        $id = $type['id'];

        $country = Service::find($id);

        if (is_null($country)) {
            return $this->sendTemporaryMessage('سرویس پیدا نشد');
        }

        $fields = [
            'name' => [
                'label' => 'نام سرویس',
                'value' => $country->name
            ],
            'status' => [
                'label' => 'وضعیت',
                'value' => $country->status
            ],
            'price_per_gb' => [
                'label' => 'قیمت هر گیگ',
                'value' => $country->price_per_gb
            ],
        ];

        $text = "🌍 <b>جزئیات سرویس</b>\n\n";

        $keyboard = [];
        $row = [];

        foreach ($fields as $key => $field) {

            $isEmpty = (
                $field['value'] === null ||
                $field['value'] === ''
            );

            /*
            |--------------------------------------------------------------------------
            | Value Text
            |--------------------------------------------------------------------------
            */

            if ($key == 'status') {

                $valueText = match ((int)$field['value']) {
                    1 => '🟢 فعال',
                    -1 => '🔴 غیرفعال',
                    default => '⚪️ نامشخص'
                };

            } elseif ($key == 'price_per_gb') {
                $valueText = number_format($field['value']) . ' تومان ';
            } else {

                $valueText = $isEmpty
                    ? '—'
                    : htmlspecialchars((string)$field['value']);
            }

            $text .= "▪️ <b>{$field['label']}</b>: <code>{$valueText}</code>\n";

            /*
            |--------------------------------------------------------------------------
            | Buttons
            |--------------------------------------------------------------------------
            */

            $btnText = $isEmpty
                ? "🔴"
                : "🟢";

            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminServiceEdit|id={$id}|key={$key}"
            ];

            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        $text .= "راهنما: \n قیمت هر گیگ فقط برای فروش حجم اضافه محاسبه می شود . بر روی تعرفه ها هیچ تاثیری ندارد.\n\n";

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = [
            ['text' => 'حذف سرویس',
                'callback_data' => "type=adminServiceDeleteDetail|id={$id}",
                'style' => 'danger']
        ];

        $keyboard[] = $this->adminFooterButtons('type=adminService');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminServiceEdit($type)
    {
        $id = $type['id'];
        $key = $type['key'];

        $user = $this->user;

        $this->updatePath('adminServiceUpdate');

        $telDetail = $user->tel_detail ?? [];

        $telDetail['service-key'] = $key;
        $telDetail['service-id'] = $id;

        $user->tel_detail = $telDetail;
        $user->save();

        $country = Service::find($id);

        if (!$country) {
            return $this->sendTemporaryMessage('کشور پیدا نشد');
        }

        $fields = [
            'name' => ['label' => 'نام سرویس'],
            'status' => ['label' => 'وضعیت'],
            'price_per_gb' => [
                'label' => 'قیمت هر گیگ',
            ],
        ];

        $oldValue = $country->$key ?? '—';

        /*
        |--------------------------------------------------------------------------
        | Status Text
        |--------------------------------------------------------------------------
        */

        if ($key == 'status') {

            $oldValue = match ((int)$oldValue) {
                1 => 'فعال',
                -1 => 'غیرفعال',
                default => 'نامشخص'
            };
        }

        $text = "✏️ لطفا مقدار <b>{$fields[$key]['label']}</b> را وارد کنید\n";
        $text .= "📌 مقدار قبلی: <code>{$oldValue}</code>";

        /*
        |--------------------------------------------------------------------------
        | Status Buttons
        |--------------------------------------------------------------------------
        */

        if ($key == 'status') {

            $keyboard[] = [
                [
                    'text' => '🟢 فعال',
                    'callback_data' => "type=adminServiceUpdate|id={$id}|value=1",
                    'style' => 'success'
                ],
                [
                    'text' => '🔴 غیرفعال',
                    'callback_data' => "type=adminServiceUpdate|id={$id}|value=-1",
                    'style' => 'danger'
                ]
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = $this->adminFooterButtons("type=adminServiceDetail|id={$id}");
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminServiceUpdate($type = null)
    {
        $this->updatePath('start');

        $user = $this->user;

        $key = $user->tel_detail['service-key'];
        $id = $user->tel_detail['service-id'];

        $fields = [
            'name' => ['label' => 'نام سرویس'],
            'status' => ['label' => 'وضعیت'],
            'price_per_gb' => [
                'label' => 'قیمت هر گیگ',
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Custom Fields
        |--------------------------------------------------------------------------
        */

        $customFields = ['status'];

        if (in_array($key, $customFields)) {

            $value = $type['value'];

            Service::where('id', $id)
                ->update([$key => $value]);

        } else {

            Service::where('id', $id)
                ->update([$key => $this->text]);
        }

        $text = "✅ فیلد <b>{$fields[$key]['label']}</b> با موفقیت بروزرسانی شد.";

        $keyboard[] = $this->adminFooterButtons("type=adminServiceDetail|id={$id}");
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function adminServiceDeleteDetail($type)
    {
        $id = $type['id'];

        $service = Service::find($id);

        if (is_null($service)) {
            return $this->sendTemporaryMessage('❌ سرویس موردنظر پیدا نشد.');
        }

        /*
        |--------------------------------------------------------------------------
        | Check Usage
        |--------------------------------------------------------------------------
        */

        if ($service->is_used) {
            return $this->sendTemporaryMessage('⚠️ این سرویس در حال استفاده است و امکان حذف آن وجود ندارد.');
        }

        /*
        |--------------------------------------------------------------------------
        | Service Details
        |--------------------------------------------------------------------------
        */

        $plansUsed = Plans::where('type', $service->id)
            ->pluck('name')
            ->toArray();
        $plansUsed = !empty($plansUsed) ? implode(',', $plansUsed) : '—';
        $panelUsed = Panels::where('panel_type', $service->id)
            ->pluck('name')
            ->toArray();

        $panelUsed = !empty($panelUsed) ? implode(',', $panelUsed) : '—';

        $status = match ((int)$service->status) {
            1 => '🟢 فعال',
            -1 => '🔴 غیرفعال',
            default => '🟡 معلق'
        };

        $text = "╔════════════════════╗\n";
        $text .= "      🗑 حذف سرویس\n";
        $text .= "╚════════════════════╝\n\n";

        $text .= "⚠️ آیا از حذف این سرویس اطمینان دارید؟\n\n";

        $text .= "📦 نام سرویس:\n";
        $text .= "<code>{$service->name}</code>\n\n";

        $text .= "📊 وضعیت:\n";
        $text .= "<code>{$status}</code>\n\n";

        $text .= "📊 پنل های فعال:\n";
        $text .= "<code>{$panelUsed}</code>\n\n";

        $text .= "📊 پلن های فعال:\n";
        $text .= "<code>{$plansUsed}</code>\n\n";

        $text .= "❗ پس از حذف، امکان بازگردانی سرویس وجود نخواهد داشت.\n\n";

        if (!empty($panelUsed) || !empty($plansUsed)) {
            $text .= "❗ تازمانی که این سرویس٬ پنل یا پلن فعالی داشته باشد امکان حذف آن وجود ندارد";

        }

        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard = [];

        $keyboard[] = [
            [
                'text' => '🗑 بله، حذف شود',
                'callback_data' => "type=adminServiceDeleteSubmit|id={$id}",
                'style' => 'danger'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons(
            "type=adminServiceDetail|id={$id}"
        );

        /*
        |--------------------------------------------------------------------------
        | Send Message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminServiceDeleteSubmit($type)
    {
        $id = $type['id'];

        $service = Service::find($id);

        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس پیدا نشد');
        }

        $plansUsed = Plans::where('type', $service->id)->first();
        $panelUsed = Panels::where('panel_type', $service->id)->first();

        if (!is_null($plansUsed) || !is_null($panelUsed)) {
            return $this->sendTemporaryMessage('این سرویس در حال استفاده می باشد.');
        }

        $service->delete();

        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => "سرویس با موفقیت حذف شد.",
            'show_alert' => true,
            'cache_time' => 1,
        ]);


        return $this->adminService(['page' => 1]);
    }


    // Extra Bandwidth
    protected function adminExtraBandwidths($type)
    {
        $page = $type['page'] ?? 1;

        $list = ExtraBandwidth::orderByDesc('id')
            ->paginate(10, ['*'], 'page', $page);

        $text = "📦 <b>لیست حجم های اضافه</b>\n";
        $text .= "مبلغ هر حجم اضافه به ازای هر گیگ محاسبه میشود.\n\n";
        $text .= "قیمت به ازای هر گیگ  میاشد. قیمت هر گیگ در قسمت تنظیمات همین صفحه قابل تنظیم می باشد.\n\n";
        $text .= "حهت فعال یا غیرفعال کردن فروش حجم اضافه از طریق تنظمیات اقدام نمایید.\n\n.";

        $keyboard = [];
        $row = [];


        foreach ($list as $plan) {

            // نوع پلن
            $planType = Service::find($plan->type);
            if (!is_null($planType)) {
                $planType = $planType->name;
            } else {
                $planType = '--';
            }

            // وضعیت
            $status = ((int)$plan->status === 1)
                ? '🟢 فعال'
                : '🔴 غیرفعال';

            $row[] = [
                'text' => "حجم:{$plan->name} گیگ| {$planType} | {$status}",
                'callback_data' => "type=adminExtraBandwidthsDetail|id={$plan->id}"
            ];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        // صفحه‌بندی
        $pagination = $this->paginationFooterButton($list, $page, 'adminExtraBandwidths');
        if (!is_null($pagination)) {
            $keyboard[] = $pagination;
        }

        // ایجاد پلن
        $keyboard[] = [
            [
                'text' => 'تنظیمات',
                'callback_data' => 'type=adminPlanCreate',
                'style' => 'primary'
            ],
            [
                'text' => '➕ ایجاد حجم اضافه جدید',
                'callback_data' => 'type=adminExtraBandwidthsCreate',
                'style' => 'success'
            ],

        ];

        // برگشت

        $keyboard[] = $this->adminFooterButtons('type=adminPanelMenu');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function adminExtraBandwidthsCreate($type)
    {
        $user = $this->user;

        $newPanel = new ExtraBandwidth();
        $newPanel->admin_id = $user->id;
        $newPanel->status = 0;
        $newPanel->save();

        $type['id'] = $newPanel->id;
        return $this->adminExtraBandwidthsDetail($type);
    }

    protected function adminExtraBandwidthsDetail($type)
    {
        $id = $type['id'];

        $plan = ExtraBandwidth::find($id);

        if (is_null($plan)) {
            return $this->sendTemporaryMessage('حجم اضافه پیدا نشد، دوباره تلاش کنید');
        }

        $fields = [
            'name' => ['label' => 'مقدار حجم', 'value' => $plan->name],
            'type' => ['label' => 'نوع', 'value' => $plan->type],
            'status' => ['label' => 'وضعیت', 'value' => $plan->status],
            'discount' => ['label' => 'درصد تخفیف', 'value' => $plan->discount],
        ];

        $text = "📦 <b>جزئیات حجم اضافه</b>\n\n";

        $keyboard = [];
        $row = [];
        $pricePerGb = null;
        foreach ($fields as $key => $field) {

            $isEmpty = ($field['value'] === null || $field['value'] === '');

            // نمایش وضعیت در متن
            $valueText = $isEmpty
                ? '—'
                : htmlspecialchars((string)$field['value']);

            if ($key == 'type') {

                $service = Service::find($field['value']);
                if (!is_null($service)) {
                    $pricePerGb = $service->price_per_gb;
                    $valueText = htmlspecialchars($service->name);
                } else {
                    $valueText = "--";
                }
            } elseif ($key == 'price') {
                $valueText = number_format($field['value']) . ' تومان ';
            } elseif ($key == 'status') {
                $valueText = match ((int)$field['value']) {
                    1 => '🟢 فعال',
                    -1 => '🔴 غیرفعال',
                    0 => '🟡 معلق',
                    default => '⚪ نامشخص',
                };
            }

            $text .= "▪️ <b>{$field['label']}</b>: <code>{$valueText}</code>\n";

            // دکمه‌ها
            $btnText = $isEmpty
                ? "🔴 " . $field['label']
                : $field['label'];

            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminExtraBandwidthsEdit|id={$id}|key={$key}"
            ];

            // هر دو دکمه یک ردیف
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        if (is_null($plan->name)) {
            $text .= "▪️ <b>برای محاسبه مبلغ لطفا حجم را وارد نمایید</b>\n\n";
        } elseif (isset($service) && $pricePerGb == 0 && is_null($pricePerGb)) {

            $keyboard[] = [
                [
                    'text' => 'تنظیم قیمت هر گیگ',
                    'callback_data' => "type=adminServiceDetail|id={$service->id}",
                    'style' => 'primary'
                ]
            ];
        } elseif (!isset($service)) {
            $text .= "▪️ <b>برای محاسبه مبلغ لطفا نوع را انتخاب نمایید</b>\n\n";
        } else {
            $price = calculateExtraDiscount($plan, $pricePerGb);
            $basePrice = number_format($price['base-price']);
            $price = number_format($price['price']);

            $text .= "▪️ <b>مبلغ</b>: <code>{$basePrice}</code> تومان\n";
            $text .= "▪️ <b>مبلغ با احتساب تخفیف</b>: <code>{$price}</code> تومان\n";
        }

        $keyboard[] = [
            [
                'text' => 'حذف آیتم',
                'callback_data' => "type=adminExtraBandwidthsDelete|id={$id}",
                'style' => 'danger'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons('type=adminExtraBandwidths');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminExtraBandwidthsEdit($type)
    {
        $id = $type['id'];
        $key = $type['key'];
        $user = $this->user;

        $this->updatePath('adminExtraBandwidthsUpdate');
        $telDetail = $user->tel_detail ?? [];
        $telDetail['extra-key'] = $key;
        $telDetail['extra-id'] = $id;
        $user->tel_detail = $telDetail;
        $user->save();

        $plan = ExtraBandwidth::find($id);

        $fields = [
            'name' => ['label' => 'مقدار حجم', 'value' => $plan->name],
            'type' => ['label' => 'نوع', 'value' => $plan->type],
            'status' => ['label' => 'وضعیت', 'value' => $plan->status],
            'discount' => ['label' => 'درصد تخفیف', 'value' => $plan->discount],
        ];
        $oldValue = $plan->$key ?? '—';

        $text = "✏️ لطفا مقدار <b>{$fields[$key]['label']}</b> را وارد کنید\n";
        if ($key == 'name') {
            $text .= "📌 حجم را به گیگ وارد کنید";
        }

        $customFiles = ['type', 'status'];
        if (in_array($key, $customFiles)) {

            if ($key == 'type') {
                $services = Service::where('status', 1)->get();

                $keyboard = [];
                $row = [];

                foreach ($services as $service) {

                    if ($service->id == $plan->type) {
                        $oldValue = $service->name;
                    }

                    $row[] = [
                        'text' => $service->name,
                        'callback_data' => "type=adminExtraBandwidthsUpdate|id={$id}|value={$service->id}",
                    ];

                    // دو ستونه
                    if (count($row) == 2) {
                        $keyboard[] = $row;
                        $row = [];
                    }
                }

                if (!empty($row)) {
                    $keyboard[] = $row;
                }
            } elseif ($key == 'status') {
                $keyboard[] = [
                    [
                        'text' => 'غیرفعال',
                        'callback_data' => "type=adminExtraBandwidthsUpdate|id={$id}|value=-1",
                    ],
                    [
                        'text' => 'فعال',
                        'callback_data' => "type=adminExtraBandwidthsUpdate|id={$id}|value=1",
                    ],
                ];
            }

        }
        $text .= "📌 مقدار قبلی: <code>" . htmlspecialchars((string)$oldValue) . "</code> \n";


        $keyboard[] = $this->adminFooterButtons("type=adminExtraBandwidthsDetail|id={$id}");


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminExtraBandwidthsUpdate($type = null)
    {
        $this->updatePath('start');

        $user = $this->user;
        $key = $user->tel_detail['extra-key'];
        $id = $user->tel_detail['extra-id'];

        $fields = [
            'name' => ['label' => 'مقدار حجم'],
            'type' => ['label' => 'نوع'],
            'status' => ['label' => 'وضعیت'],
            'discount' => ['label' => 'درصد تخفیف'],
        ];

        $customFiles = ['type', 'status'];
        if (in_array($key, $customFiles)) {
            $value = $type['value'];
            ExtraBandwidth::where('id', $id)->update([$key => $value]);

        } else {
            ExtraBandwidth::where('id', $id)->update([$key => $this->text]);
        }

        $text = "فیلد `{$fields[$key]['label']}` با موفقیت ویرایش شد.";

        $keyboard[] = $this->adminFooterButtons("type=adminExtraBandwidthsDetail|id={$id}");


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminExtraBandwidthsDelete($type)
    {
        $id = $type['id'];

        $service = ExtraBandwidth::find($id);

        if (is_null($service)) {
            return $this->sendTemporaryMessage('❌ سرویس موردنظر پیدا نشد.');
        }

        /*
        |--------------------------------------------------------------------------
        | Check Usage
        |--------------------------------------------------------------------------
        */

        $status = match ((int)$service->status) {
            1 => '🟢 فعال',
            -1 => '🔴 غیرفعال',
            default => '🟡 معلق'
        };

        $text = "╔════════════════════╗\n";
        $text .= "      🗑 حذف آیتم\n";
        $text .= "╚════════════════════╝\n\n";

        $text .= "⚠️ آیا از حذف این آیتم اطمینان دارید؟\n\n";

        $text .= "📦 مقدار حجم (گیگ):\n";
        $text .= "<code>{$service->name}</code>\n\n";

        $text .= "📊 وضعیت:\n";
        $text .= "<code>{$status}</code>\n\n";


        $text .= "❗ پس از حذف، امکان بازگردانی سرویس وجود نخواهد داشت.\n\n";


        /*
        |--------------------------------------------------------------------------
        | Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard = [];

        $keyboard[] = [
            [
                'text' => '🗑 بله، حذف شود',
                'callback_data' => "type=adminExtraBandwidthsDeleteSubmit|id={$id}",
                'style' => 'danger'
            ]
        ];

        $keyboard[] = $this->adminFooterButtons(
            "type=adminExtraBandwidthsDetail|id={$id}"
        );

        /*
        |--------------------------------------------------------------------------
        | Send Message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminExtraBandwidthsDeleteSubmit($type)
    {
        $id = $type['id'];

        $service = ExtraBandwidth::find($id);

        if (is_null($service)) {
            return $this->sendTemporaryMessage('سرویس پیدا نشد');
        }

        $service->delete();

        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => "سرویس با موفقیت حذف شد.",
            'show_alert' => true,
            'cache_time' => 1,
        ]);

        return $this->adminExtraBandwidths(['page' => 1]);
    }


    // Inbounds
    protected function adminInbounds($data)
    {
        $keyboard = [];
        $keyboard[] = [
            [
                'text' => "اینبوند های ثنایی",
                'callback_data' => "type=adminInboundList|value=sanaie"
            ], [
                'text' => "اینبوند های پاسارگاد",
                'callback_data' => "type=adminPasarGuardGroups|value=pasarguard"
            ],
        ];
        $keyboard[] = $this->adminFooterButtons('type=adminPanelMenu');

        $text = headTitle('لیست اینبوند ها');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function adminInboundList($data)
    {
        $type = $data['value'];
        $page = $data['page'] ?? 1;

        /*
        |--------------------------------------------------------------------------
        | Panels
        |--------------------------------------------------------------------------
        */

        $panels = Panels::where('system_type', $type)
            ->pluck('id')
            ->toArray();
        /*
        |--------------------------------------------------------------------------
        | Inbounds List
        |--------------------------------------------------------------------------
        */

        $list = Inbounds::wherein('panel_id', $panels)
            ->with(['panel'])
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page', $page);
        /*
        |--------------------------------------------------------------------------
        | Text
        |--------------------------------------------------------------------------
        */

        $text = headTitle('لیست اینبوند های ثنایی');

        if ($list->count() == 0) {
            $text .= "❌ هیچ اینبوندی یافت نشد.";
        }

        /*
        |--------------------------------------------------------------------------
        | Keyboard
        |--------------------------------------------------------------------------
        */

        $keyboard = [];

        foreach ($list as $inbound) {
            $name = $inbound?->panel?->name;
            $statusIcon = $inbound->status ? '🟢' : '🔴';
            $keyboard[][] = [
                'text' => "{$statusIcon} {$inbound->remark}:{$inbound->port} - نام پنل: {$name}",
                'callback_data' => "type=adminToggleInboundStatus|id={$inbound->id}|value={$type}"
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = $this->paginationFooterButton(
            $list,
            $page,
            "adminInboundList|value={$type}"
        );

        if (!empty($pagination)) {
            $keyboard[] = $pagination;
        }

        /*
        |--------------------------------------------------------------------------
        | Footer Buttons
        |--------------------------------------------------------------------------
        */

        $keyboard[] = $this->adminFooterButtons('type=adminInbounds');

        /*
        |--------------------------------------------------------------------------
        | Send Message
        |--------------------------------------------------------------------------
        */

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminToggleInboundStatus($data)
    {
        $id = $data['id'];

        $inbound = Inbounds::find($id);
        if ($inbound->status == 0) {
            $inbound->status = 1;
            $message = ' `فعال` ';
        } else {
            $inbound->status = 0;
            $message = ' `غیر فعال` ';
        }
        $inbound->save();


        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => "وضعیت اینبوند با موفقیت به {$message}تغییر پیدا کرد. ",
            'show_alert' => true,
            'cache_time' => 1,
        ]);

        return $this->adminInboundList($data);

    }

    protected function adminPasarGuardGroups($data)
    {
        $type = $data['value'] ?? 'pasarguard';
        $id = $data['id'] ?? null;
        $page = $data['page'] ?? 1;


        $path = null;
        if (!is_null($id)) {
            $panels = [$id];
            $path = "|b=true|v_id=$id";
        } else {
            $panels = Panels::where('system_type', 'pasarguard');
            $panels = $panels->pluck('id')->toArray();
        }

        $list = Inbounds::wherein('panel_id', $panels)
            ->with(['panel'])
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page', $page);

        $text = headTitle('لیست گروه های پاسارگاد');

        if ($list->count() == 0) {
            $text .= "❌ هیچ اینبوندی یافت نشد.";
        }

        $keyboard = [];
        foreach ($list as $inbound) {
            $name = $inbound?->panel?->name;
            $statusIcon = $inbound->status == 1 ? '🟢' : '🔴';
            $keyboard[][] = [
                'text' => "{$statusIcon} {$inbound->remark}:{$inbound->port} - نام پنل: {$name}",
                'callback_data' => "type=AdminPGGDA|id={$inbound->id}|value={$type}{$path}"
            ];
        }


        $pagination = $this->paginationFooterButton(
            $list,
            $page,
            "adminInboundList|value={$type}"
        );

        if (!empty($pagination)) {
            $keyboard[] = $pagination;
        }
        if (!is_null($id)) {
            $keyboard[] = $this->adminFooterButtons("type=adminPanelDetail|id=$id");
        } else {
            $keyboard[] = $this->adminFooterButtons('type=adminInbounds');
        }
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function adminPasarGuardGroupDetail($data)
    {
        $id = $data['id'];
        $back = $data['b'] ?? null;
        $value = $data['v_id'] ?? null;

        $plan = Inbounds::find($id);

        if (is_null($plan)) {
            return $this->sendTemporaryMessage('گروه پیدا نشد، دوباره تلاش کنید');
        }

        $fields = [
            'country_id' => ['label' => 'نام کشور', 'value' => $plan->country_id],
            'status' => ['label' => 'وضعیت', 'value' => $plan->status],

        ];

        $text = "📦 <b>جزئیات گروه</b>\n\n";

        $keyboard = [];
        $row = [];

        foreach ($fields as $key => $field) {

            $isEmpty = ($field['value'] === null || $field['value'] === '');

            // نمایش وضعیت در متن
            $valueText = $isEmpty
                ? '—'
                : htmlspecialchars((string)$field['value']);

            if ($key == 'status') {
                $valueText = match ((int)$field['value']) {
                    1 => '🟢 فعال',
                    -1 => '🔴 غیرفعال',
                    0 => '🟡 معلق',
                    default => '⚪ نامشخص',
                };
            } elseif ($key == 'country_id') {
                $valueText = Countries::find($field['value'])->name ?? '—';
            }

            $text .= "▪️ <b>{$field['label']}</b>: <code>{$valueText}</code>\n";

            // دکمه‌ها
            $btnText = $isEmpty
                ? "🔴 " . $field['label']
                : $field['label'];

            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminPasarGuardGroupsEdit|id={$id}|key={$key}"
            ];

            // هر دو دکمه یک ردیف
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        $keyboard[] = [
            [
                'text' => 'حذف پلن',
                'callback_data' => "type=adminPlanDeleteDetail|id={$id}",
                'style' => 'danger'
            ]
        ];

        if (is_null($back)) {
            $keyboard[] = $this->adminFooterButtons('type=adminPasarGuardGroups');
        } else {
            $keyboard[] = $this->adminFooterButtons("type=adminPasarGuardGroups|id={$value}");
        }


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminPasarGuardGroupsEdit($type)
    {
        $id = $type['id'];
        $key = $type['key'];
        $user = $this->user;

        $this->updatePath('adminPasarGuardGroupUpdate');
        $telDetail = $user->tel_detail ?? [];
        $telDetail['inbound-key'] = $key;
        $telDetail['inbound-id'] = $id;
        $user->tel_detail = $telDetail;
        $user->save();

        $fields = [
            'country_id' => ['label' => 'کشور'],
            'status' => ['label' => 'وضعیت'],
        ];

        $panel = Inbounds::find($id);

        $oldValue = $panel->$key ?? '—';

        $text = "✏️ لطفا مقدار <b>{$fields[$key]['label']}</b> را وارد کنید\n";

        $customFiles = ['system_type', 'type', 'panel_type', 'status', 'country_id'];
        if (in_array($key, $customFiles)) {

            if ($key == 'status') {
                $keyboard[] = [
                    [
                        'text' => 'فعال',
                        'callback_data' => "type=adminPasarGuardGroupsUpdate|id={$id}|value=1",
                    ],
                    [
                        'text' => 'غیرفعال',
                        'callback_data' => "type=adminPasarGuardGroupsUpdate|id={$id}|value=-1",
                    ],
                ];
            } elseif ($key == 'country_id') {
                $parentPanel = Panels::find($panel->panel_id);
                $country = Countries::where('type', $parentPanel->panel_type)->get();
                foreach ($country as $item) {

                    $isSelected = ((int)$panel->country_id === (int)$item->id);

                    if ($isSelected) {
                        $oldValue = $item->name;
                    }
                    $keyboard[] = [
                        [
                            'text' => $item->name . ($isSelected ? ' ✅' : ''),
                            'callback_data' => "type=adminPasarGuardGroupsUpdate|id={$id}|value={$item->id}",
                            'style' => $isSelected ? 'success' : ''
                        ]
                    ];
                }
            }
        }
        $text .= "📌 مقدار قبلی: <code>" . htmlspecialchars((string)$oldValue) . "</code>";


        $keyboard[] = $this->adminFooterButtons("type=AdminPGGDA|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');

    }

    protected function adminPasarGuardGroupsUpdate($type = null)
    {
        $user = $this->user;
        $key = $user->tel_detail['inbound-key'];
        $id = $user->tel_detail['inbound-id'];

        $fields = [
            'country_id' => ['label' => 'کشور'],
            'status' => ['label' => 'وضعیت'],
        ];


        $customFiles = ['status', 'country_id'];
        if (in_array($key, $customFiles)) {
            $value = $type['value'];
            Inbounds::where('id', $id)->update([$key => $value]);

        } else {
            $text = rtrim($this->text, '/');
            Inbounds::where('id', $id)->update([$key => $text]);

            if ($key == 'url') {
                $checkSub = Panels::find($id);
                if (is_null($checkSub->sub_address)) {
                    $scheme = parse_url($text, PHP_URL_SCHEME) ?? 'https';
                    $host = parse_url($text, PHP_URL_HOST);
                    $result = $scheme . '://' . $host . ':2096/sub/';
                    $checkSub->sub_address = $result;
                    $checkSub->save();
                }
            }
        }

        $text = "فیلد `{$fields[$key]['label']}` با موفقیت ویرایش شد.";

        $keyboard[] = $this->adminFooterButtons("type=AdminPGGDA|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');

    }

    protected function adminPasarGuardSellSetting($type)
    {
        $id = $type['id'];
        $panel = Panels::find($id);
        if (is_null($panel)) {
            return $this->sendTemporaryMessage('panel not found please try again');
        }
        $text = headTitle("📋 تنظیمات فروش چند کشور");
        $planDetail = $panel->detail;
        $multiSellStatus = 0;
        if (array_key_exists('status', $planDetail) && $planDetail['status'] == 1) {
            $multiSellStatus = 1;
        }
        $keyboard[] = [
            [
                'text' => "وضعیت فروش:" . ($multiSellStatus == 1 ? ' فعال ' : ' غیرفعال '),
                'callback_data' => "type=adminPGSellChangeStatus|id=$panel->id|status={$multiSellStatus}",
            ]
        ];
        $keyboard[] = [
            [
                'text' => 'درصد افزایش مبلغ',
                'callback_data' => "type=adminPGSellChangePercent|id=$panel->id",
            ]
        ];
        $keyboard[] = $this->adminFooterButtons("type=adminPanelDetail|id=$panel->id");
        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function adminPGSellChangeStatus($data)
    {
        $id = $data['id'];
        $status = $data['status'];
        $panel = Panels::find($id);
        $detail = $panel->detail;
        if ($status == 1) {
            $status = 0;
        } else {
            $status = 1;
        }
        $detail['status'] = $status;

        $panel->detail = $detail;
        $panel->save();

        return $this->adminPasarGuardSellSetting($data);
    }

    protected function adminPGSellChangePercent($data)
    {
        $id = $data['id'];
        $user = $this->user;
        $telDetail = $user->tel_detail;
        $telDetail['panel-id'] = $id;
        $user->tel_detail = $telDetail;
        $user->save();

        $panel = Panels::find($id);
        $discount = $panel->detail;

        $oldValue = $discount['percent'] ?? '—';

        $text = "✏️ درصد مورد نظر را وارد کنید\n";
        $text .= "📌 مقدار قبلی: <code>" . htmlspecialchars((string)$oldValue) . "</code> \n";

        $keyboard[] = $this->adminFooterButtons("type=adminPanelDetail|id={$id}");


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        $this->updatePath('adminPGSellChangePercentSubmit');

        return $this->sendMessage($data, 'message');
    }

    protected function adminPGSellChangePercentSubmit()
    {
        $user = $this->user;
        $telDetail = $user->tel_detail;
        $id = $telDetail['panel-id'];

        $panel = Panels::find($id);
        $detail = $panel->detail;

        $detail['percent'] = $this->text;
        $panel->detail = $detail;
        $panel->save();

        $text = "فیلد درصد با موفقیت ویرایش شد.";

        $keyboard[] = $this->adminFooterButtons("type=adminPanelDetail|id={$id}");

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');

    }

    protected function adminChargeAmount($data)
    {
        $setting = Setting::where('key', 'charge_amount')->first();

        $keyboard = [
            'inline_keyboard' => []
        ];

        if (!is_null($setting) && !empty($setting->value)) {

            $amounts = explode(',', $setting->value);

            $row = [];

            foreach ($amounts as $key => $amount) {

                $amount = number_format(trim($amount));

                if ($amount === '') {
                    continue;
                }

                $row[] = [
                    'text' => "💰 {$amount} T",
                    'callback_data' => "type=adminChargeAmountDelete|amount={$amount}|key=$key",
                ];

                // دو ستون در هر ردیف
                if (count($row) == 2) {
                    $keyboard['inline_keyboard'][] = $row;
                    $row = [];
                }
            }

            // اگر تعداد فرد بود
            if (!empty($row)) {
                $keyboard['inline_keyboard'][] = $row;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | دکمه بازگشت
        |--------------------------------------------------------------------------
        */

        $keyboard['inline_keyboard'][][] = [
            'text' => "افزودن مبلغ",
            'callback_data' => "type=adminChargeAmountAdd",
        ];

        $keyboard['inline_keyboard'][] = $this->adminFooterButtons("type=adminPaymentSetting");

        $text = "
💳 <b>مدیریت مبالغ شارژ</b>

یکی از مبالغ زیر را برای مدیریت یا تنظیم انتخاب کنید.
";

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ], 'message');
    }

    protected function adminChargeAmountAdd()
    {
        $this->updatePath('adminChargeAmountSubmit');

        $keyboard['inline_keyboard'][] = $this->adminFooterButtons("type=adminPaymentSetting");

        $text = "لطفا مبلع مورد نظر را به تومان وارد نمایید";

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ], 'message');
    }

    protected function adminChargeAmountSubmit($data)
    {
        $input = trim($this->text);


        $validator = Validator::make(
            ['amount' => PersianNumToEn($input)],
            [
                'amount' => 'required|numeric|min:0',
            ],
            [
                'amount.required' => 'مبلغ الزامی است',
                'amount.numeric' => 'فقط عدد مجاز است',
                'amount.min' => 'مبلغ نمی‌تواند منفی باشد',
            ]
        );
        if ($validator->fails()) {
            return $this->sendTemporaryMessage($validator->errors()->first());
        }

        $amounts = [];

        $setting = Setting::where('key', 'charge_amount')->first();
        if (!is_null($setting) && !empty($setting->value)) {
            $amounts = explode(',', $setting->value);
        }
        $amounts[] = $this->text;
        $amounts = array_map('trim', $amounts);
        $amounts = array_filter($amounts, fn($v) => $v !== '');

        $amounts = array_map('floatval', $amounts);

        sort($amounts, SORT_NUMERIC);

        $setting->value = implode(',', $amounts);
        $setting->save();


        $keyboard['inline_keyboard'][] = $this->adminFooterButtons("type=adminChargeAmount");

        $text = "مبلغ مورد نظر با موفقیت ثبت شد.";

        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ], 'message');

    }

    protected function adminChargeAmountDelete($data)
    {
        $key = $data['key'];
        $setting = Setting::where('key', 'charge_amount')->first();

        if (!is_null($setting) && !empty($setting->value)) {
            $amounts = explode(',', $setting->value);
            unset($amounts[$key]);

            $setting->value = implode(',', $amounts);
            $setting->save();
        }

        $this->telegramSdk->answerCallback([
            'callback_query_id' => $this->callbackId,
            'text' => "مبلغ با موفقیت حذف شد.",
            'show_alert' => true,
            'cache_time' => 1,
        ]);

        return $this->adminChargeAmount($data);
    }

    protected function adminMessage($data)
    {
        $page = $data['page'] ?? 1;

        $messages = Message::ordebyDesc('id')
            ->paginate(10, ['*'], 'page', $page);

        $keyboard = [];
        foreach ($messages as $message) {

            $btnText = $message->status == 1 ? " 🟢" : ($message->status == -1 ? " 🔴" : " 🟡");
            $btnText .= $message->title;
            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminMessageSingle|$message->id"
            ];
            $keyboard[] = $row;
        }

        $text = headTitle('لیست پیام های ارسالی');
        $text .= 'لیست پیام های ارسالی به کاربر';


        $keyboard[] = [
            [
                'text' => 'افزودن',
                'callback_data' => 'type=adminOrderSearch'
            ],
        ];

        $pagination = $this->paginationFooterButton(
            $messages,
            $page,
            "adminMessage"
        );

        if (!empty($pagination)) {
            $keyboard[] = $pagination;
        }


        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];


        return $this->sendMessage($data, 'message');
    }

    // Orders
    protected function adminOrdersList($data)
    {
        if (!$this->isAdmin) {
            return $this->denyAdminAccess();
        }

        $page = $data['page'] ?? 1;
        $filter = $data['filter'] ?? null;
        $search = $data['search'] ?? null;
        $userId = $data['userId'] ?? null;
        $lifecycle = app(OrderLifecycleService::class);
        $lifecycle->reconcileTimeStatuses($userId ? (int) $userId : null);

        $query = Orders::query();

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */
        if (is_null($userId)) {
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {

                    if (is_numeric($search)) {
                        $q->where('id', (int)$search);
                    }

                    $q->orWhere('uid', 'LIKE', "%{$search}%")
                        ->orWhere('remark', 'LIKE', "%{$search}%")
                        ->orWhereIn('user_id', function ($userQuery) use ($search) {
                            $userQuery->select('id')
                                ->from('users')
                                ->where('username', 'LIKE', "%{$search}%")
                                ->orWhere('first_name', 'LIKE', "%{$search}%")
                                ->orWhere('last_name', 'LIKE', "%{$search}%");
                        });
                });
            }
        } else {
            $query->where('user_id', $userId);
        }


        if (!empty($filter)) {
            $query->where('status', $filter);
        }

        $orders = $lifecycle->orderByStatus($query)
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page', $page);

        $text = headTitle("👥 لیست سفارشات");
        $text .= "🔎 جستجو بر اساس:
• آیدی سفارش
• نام کاربری
• ریمارک

📌 برای مشاهده جزئیات،
روی سفارش موردنظر کلیک کنید.
";

        if ($filter) {
            $text .= "📌 فیلتر فعال: <code>{$filter}</code>\n\n";
        }

        if ($search) {
            $text .= "🔍 جستجوی فعال: <code>{$search}</code>\n\n";
        }

        $keyboard = [];
        $row = [];

        /*
        |--------------------------------------------------------------------------
        | Order Buttons
        |--------------------------------------------------------------------------
        */

        foreach ($orders as $order) {

            $status = $lifecycle->statusMeta($order);
            $btnText = "{$status['icon']} #{$order->id}";
            $row[] = [
                'text' => $btnText,
                'callback_data' => "type=adminOrderSingle|id={$order->id}|search=$search|userId=$userId"
            ];
            // دو ستونه
            if (count($row) == 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        // باقی مانده
        if (!empty($row)) {
            $keyboard[] = $row;
        }

        if ($orders->isEmpty()) {
            $keyboard[] = [
                [
                    'text' => 'سفارشی یافت نشد',
                    'callback_data' => 'ignore'
                ]
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $pagination = [];

        $callbackBase = "type=adminOrdersList|search={$search}|userId={$userId}";

        if (!empty($filter)) {
            $callbackBase .= '|filter=' . $filter;
        }

        if (!empty($search)) {
            $callbackBase .= '|search=' . $search;
        }

        $pagination = $this->paginationFooterButton(
            $orders,
            $page,
            $callbackBase
        );

        if (!empty($pagination)) {
            $keyboard[] = $pagination;
        }


        /*
        |--------------------------------------------------------------------------
        | Filter Buttons
        |--------------------------------------------------------------------------
        */
        $keyboard[] = [
            [
                'text' => '🔍 جستجو',
                'callback_data' => 'type=adminOrderSearch'
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Home Button
        |--------------------------------------------------------------------------
        */

        if (!is_null($userId)) {
            $keyboard[] = $this->adminFooterButtons("type=adminUserDetail|id=$userId");
        } else {
            $keyboard[] = $this->adminFooterButtons('type=admin-home');
        }

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];

        return $this->sendMessage($data, 'message');
    }

    protected function adminOrderSearch($type)
    {
        $text = headTitle("👥جستجو سفارشات");
        $text .= "🔎 جستجو بر اساس:
• آیدی سفارش
• نام کاربری
• ریمارک

📌 برای مشاهده جزئیات،
روی کاربر موردنظر کلیک کنید.
";

        $keyboard[] = $this->adminFooterButtons('type=adminOrderList');

        $data = [
            'chat_id' => $this->chatId,
            'text' => trim($text),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ]),
        ];
        $this->updatePath('adminOrdersList');

        return $this->sendMessage($data, 'message');
    }

    protected function adminOrderSingle($data)
    {
        if (!$this->isAdmin) {
            return $this->denyAdminAccess();
        }

        $id = $data['id'] ?? null;
        $userId = $data['userId'] ?? null;
        $search = $data['search'] ?? null;
        if (!$id) {
            return $this->telegramSdk->sendMessage([
                'chat_id' => $this->chatId,
                'text' => "❌ <b>سفارش نامعتبر است!</b>\n\nلطفا دوباره از بخش «سرویس های من» سفارش خود را انتخاب کنید.",
                'parse_mode' => 'HTML',
            ]);
        }

        $order = Orders::where('id', $id)
            ->first();
        if (is_null($order)) {
            return $this->telegramSdk->sendMessage([
                'chat_id' => $this->chatId,
                'text' => "🚫 <b>سفارش یافت نشد</b>\n\nاین سفارش وجود ندارد یا متعلق به حساب شما نیست.",
                'parse_mode' => 'HTML',
            ]);
        }

        $targetUser = User::find($order->user_id);
        $userId = $order->user_id;
        $lifecycle = app(OrderLifecycleService::class);
        $lifecycle->refreshTimeStatus($order);

        $detail = is_array($order->detail)
            ? $order->detail
            : json_decode($order->detail, true);

        $buttons = [];


        $buttons[] = [
            [
                'text' => '✏️ تغییر نام',
                'callback_data' => "type=clientChangeConfigName|id={$order->id}",
            ],
            [
                'text' => '🔗 تغییر کد',
                'callback_data' => "type=clientChangeConfigUid|id={$order->id}",
            ],
        ];

        $buttons[] = [
            [
                'text' => '➕ تغییر حجم',
                'callback_data' => "type=adminOrderChangeBw|id={$order->id}",
            ],
            [
                'text' => '🔄 تغییر زمان',
                'callback_data' => "type=adminOrderChangeTime|id={$order->id}",
            ],
        ];

        $buttons[] = [
            [
                'text' => '📚 تغییر وضعیت',
                'callback_data' => "type=clientGuides|id={$order->id}",
            ],
        ];

        $buttons[] = [
            [
                'text' => 'نمایش کد',
                'callback_data' => "type=adminOrderShowCode|id={$order->id}",
            ],
            [
                'text' => '📋 لیست تراکنش‌ها',
                'callback_data' => "type=adminOrderTransactions|id={$order->id}",
            ],
        ];

        $buttons[] = [

            [
                'text' => '🏠 منو اصلی',
                'callback_data' => 'type=admin-home',
            ],
            [
                'text' => '🔙 بازگشت',
                'callback_data' => "type=adminOrdersList|search=$search|userId=$userId",
            ],
        ];

        $jdf = new Jdf();

        if ($lifecycle->shouldSkipProviderRefresh($order)) {
            $configDetail = ['status' => false];
        } else {
            try {
                $configDetail = getConfigDetail($order);
            } catch (\Throwable $exception) {
                Log::warning('Could not load live admin order details', [
                    'order_id' => $order->id,
                    'message' => $exception->getMessage(),
                ]);
                $configDetail = ['status' => false];
            }
        }

        if ($configDetail['status'] ?? false) {
            $lifecycle->applyConfigDetail($order, $configDetail);
            $totalGb = $configDetail['data']['totalGb'];
            $totalUsed = $configDetail['data']['totalUsed'];
            $left = $configDetail['data']['left'];
            $code = $configDetail['data']['code'];
            $expireTime = $configDetail['data']['code'] ? $jdf->jdate('H:i:s d-m-Y', strtotime($configDetail['data']['expire'])) : $jdf->jdate('H:i:s d-m-Y', strtotime($order->expire_at));
        } else {
            $cached = is_array($detail['lifecycle'] ?? null) ? $detail['lifecycle'] : [];
            $totalGb = $cached['total_gb'] ?? 'نامشخص';
            $totalUsed = $cached['used_gb'] ?? 'نامشخص';
            $left = $cached['left_gb'] ?? 'نامشخص';
            $code = $detail['code'] ?? null;
            $expireTime = $order->expire_at ? $jdf->jdate('H:i:s d-m-Y', strtotime($order->expire_at)) : 'نامشخص';
        }


//        $configCodeRaw = $code ?? '-';
//        $subUrl = rtrim($panel->sub_address, '/') . $order->sub_id;
//        $configCode = htmlspecialchars($configCodeRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
//        $subUrlSafe = htmlspecialchars($subUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $message = "<b>✅ جزئیات سفارش #{$order->id}</b>\n\n";
        $status = $lifecycle->statusMeta($order->fresh());
        $message .= "<b>وضعیت:</b> {$status['icon']} {$status['label']}\n";
        $message .= "<b>ریمارک:</b> {$order->remark}\n";
        $message .= "<b>حجم کل:</b> {$totalGb} گیگ\n";
        $message .= "<b>حجم مصرف شده:</b> {$totalUsed} گیگ\n";
        $message .= "<b>حجم باقی مانده:</b> {$left} گیگ\n";
        $message .= "<b>زمان پایان:</b> {$expireTime}\n\n";

        $message .= "<b>✅اطلاعات کاربر</b>\n\n";
        $message .= "<b>نام کاربری:</b> " . ($targetUser?->username ?? 'نامشخص') . "\n";
        $message .= "<b>آیدی تلگرام:</b> " . ($targetUser?->tel_id ?? 'نامشخص') . "\n";

        $data = [
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'text' => $message,
            'reply_markup' => json_encode([
                'inline_keyboard' => $buttons,
            ]),
            'parse_mode' => 'HTML',
        ];
        return $this->sendMessage($data, 'message');
    }

    protected function adminOrderChangeBw($data)
    {
        $id = $data['id'] ?? null;
        $order = Orders::find($id);

        $user = $this->user;
        $telDetail = $user->tel_detail;
        $telDetail['order-bw'] = $order->id;
        $user->tel_detail = $telDetail;
        $user->save();

        $data = getConfigDetail($order);

        if ($data['status']) {
            $totalGb = $data['data']['totalGb'];
            $totalUsed = $data['data']['totalUsed'];
            $left = $data['data']['left'];
        } else {
            return $this->sendTemporaryMessage($data['msg']);
        }

        $this->updatePath('adminOrderChangeBwSubmit');

        $message = "<b>تغییر حجم</b>\n\n";
        $message .= "<b>حجم کل:</b> {$totalGb}\n";
        $message .= "<b>حجم مصرف شده:</b> {$totalUsed}\n";
        $message .= "<b>حجم باقی مانده:</b> {$left}\n";

        $data = [
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'text' => $message,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '📄 جزئیات سفارش',
                            'callback_data' => "type=adminOrderSingle|id={$order->id}",
                        ]
                    ]
                ]
            ]),
            'parse_mode' => 'HTML',
        ];
        return $this->sendMessage($data, 'message');

    }

    protected function adminOrderChangeBwSubmit($data)
    {
        $text = intval($data['text']);
        $user = $this->user;
        $order = Orders::find($user->tel_detail['order-bw']);
        $panel = Panels::find($order->panel_id);

        $data = getConfigDetail($order);

        if ($data['status']) {
            $totalGb = $data['data']['totalGb'];
            $totalUsed = $data['data']['totalUsed'];
            $left = $data['data']['left'];
        } else {
            return $this->sendTemporaryMessage($data['msg']);
        }


        if ($text >= 0) {
            $totalGb = $totalGb + $text;
            $txtType = ' عملیات افزایش حجم';
        } else {
            $value = str_replace('-', '', $text);
            $totalGb = $totalGb - $value;
            $txtType = ' عملیات کاهش حجم';
        }

        if ($panel->system_type == 'pasarguard') {
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
            $result = $pasarGuard->getUserById($order->uid);

            $expire = Carbon::parse($result['expire'])->format('Y-m-d H:i:s');
            $band = gbToByte($totalGb);
            $data = [
                'status' => 'active',
                'expire' => $expire,
                'data_limit' => $band,
            ];
            $result = $pasarGuard->updateUserById($order->uid, $data);

            if ($result['status'] != false) {
                $caption = "$txtType با موفقیت انجام شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');

            } else {
                $caption = "خطا در انجام عملیات";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');
            }


        } else {
            $loginData = [
                'username' => $panel->username,
                'password' => $panel->password,
                'url' => $panel->url,
            ];
            $session = loginToSanaie($loginData);

            $clientRequestData = [
                'sessionCookie' => $session['session'],
                'serverUrl' => $panel->url,
                'uuid' => $order->uid,
            ];

            $clientData = getClient($clientRequestData)['obj'][0];
            $band = gbToByte($totalGb);


            $expiryTimestamp = $clientData['expiryTime'];
            $result = [
                'serverUrl' => $panel->url,
                'sessionCookie' => $session['session'],
                'inboundId' => $clientData['inboundId'],
                'uuid' => $order->uid,
                'email' => $clientData['email'],
                'expiryTimestamp' => $expiryTimestamp,
                'limitIp' => 0,
                'subId' => $clientData['subId'],
                'totalGB' => $band,
            ];

            $result = updateClient($result);
            if ($result['success']) {
                $caption = "$txtType با موفقیت انجام شد.";
                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');
            } else {
                $caption = "خطا در انجام عملیات";
                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');
            }
        }
    }

    protected function adminOrderChangeTime($data)
    {
        $id = $data['id'] ?? null;

        $order = Orders::find($id);

        if (!$order) {
            return $this->sendTemporaryMessage('سفارش مورد نظر یافت نشد.');
        }

        $jdf = new Jdf();

        $user = $this->user;
        $telDetail = $user->tel_detail ?? [];
        $telDetail['order-time'] = $order->id;
        $user->tel_detail = $telDetail;
        $user->save();

        $configDetail = getConfigDetail($order);

        if (!($configDetail['status'] ?? false)) {
            return $this->sendTemporaryMessage($configDetail['msg'] ?? 'دریافت اطلاعات سرویس با خطا مواجه شد.');
        }

        $expire = !empty($configDetail['data']['code'])
            ? ($configDetail['data']['expire'] ?? null)
            : $order->expire_at;

        $expireTime = $expire
            ? $jdf->jdate('H:i:s d-m-Y', strtotime($expire))
            : 'نامشخص';

        $this->updatePath('adminOrderChangeTimeSubmit');

        $message = "⏳ <b>تغییر زمان سرویس</b>\n\n";
        $message .= "📦 <b>شماره سفارش:</b> <code>{$order->id}</code>\n";
        $message .= "📅 <b>تاریخ اتمام فعلی:</b> <code>{$expireTime}</code>\n\n";
        $message .= "برای تغییر زمان سرویس، تعداد روز مورد نظر را ارسال کنید.\n\n";
        $message .= "✅ <b>افزایش زمان:</b>\n";
        $message .= "مثلا <code>10</code> یعنی ۱۰ روز به سرویس اضافه شود.\n\n";
        $message .= "➖ <b>کاهش زمان:</b>\n";
        $message .= "مثلا <code>-10</code> یعنی ۱۰ روز از سرویس کم شود.\n\n";
        $message .= "لطفا فقط عدد را ارسال کنید.";

        $this->updatePath('adminOrderChangeTimeSubmit');
        return $this->sendMessage([
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '📄 جزئیات سفارش',
                            'callback_data' => "type=adminOrderSingle|id={$order->id}",
                        ],
                    ],
                ],
            ]),
        ], 'message');
    }

    protected function adminOrderChangeTimeSubmit($data)
    {
        $text = intval($data['text']);
        $user = $this->user;
        $order = Orders::find($user->tel_detail['order-bw']);
        $panel = Panels::find($order->panel_id);

        $data = getConfigDetail($order);


        if ($data['status']) {
            $totalGb = $data['data']['totalGb'];
            $expire = $data['data']['expire'];

        } else {
            return $this->sendTemporaryMessage($data['msg']);
        }


        $expire = Carbon::parse($expire);
        if ($text >= 0) {
            $expire->addDays((int)$text);
            $txtType = 'عملیات افزایش زمان';
        } else {
            $expire->subDays(abs((int)$text));
            $txtType = 'عملیات کاهش زمان';
        }

        if ($panel->system_type == 'pasarguard') {
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
            $result = $pasarGuard->getUserById($order->uid);

            $expire = $expire->format('Y-m-d H:i:s');
            $band = gbToByte($totalGb);
            $data = [
                'status' => 'active',
                'expire' => $expire,
                'data_limit' => $band,
            ];
            $result = $pasarGuard->updateUserById($order->uid, $data);

            if ($result['status'] != false) {
                $caption = "$txtType با موفقیت انجام شد.";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');

            } else {
                $caption = "خطا در انجام عملیات";

                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');
            }


        } else {
            $loginData = [
                'username' => $panel->username,
                'password' => $panel->password,
                'url' => $panel->url,
            ];
            $session = loginToSanaie($loginData);

            $clientRequestData = [
                'sessionCookie' => $session['session'],
                'serverUrl' => $panel->url,
                'uuid' => $order->uid,
            ];

            $clientData = getClient($clientRequestData)['obj'][0];
            $band = gbToByte($totalGb);


            $expiryTimestamp = $clientData['expiryTime'];
            $result = [
                'serverUrl' => $panel->url,
                'sessionCookie' => $session['session'],
                'inboundId' => $clientData['inboundId'],
                'uuid' => $order->uid,
                'email' => $clientData['email'],
                'expiryTimestamp' => $expiryTimestamp,
                'limitIp' => 0,
                'subId' => $clientData['subId'],
                'totalGB' => $band,
            ];

            $result = updateClient($result);
            if ($result['success']) {
                $caption = "$txtType با موفقیت انجام شد.";
                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');
            } else {
                $caption = "خطا در انجام عملیات";
                $this->method = 'toUser';
                $this->sendMessage([
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📄 جزئیات سفارش',
                                    'callback_data' => "type=adminOrderSingle|id={$order->id}",
                                ]
                            ]
                        ]
                    ]),
                ], 'message');
            }
        }
    }

    protected function adminOrderShowCode($data)
    {
        $id = $data['id'];

        $order = Orders::find($id);
        $panel = Panels::find($order->panel_id);
        $data = getConfigDetail($order);
        if ($data['status']) {
            $code = $data['data']['code'];
        } else {
            return $this->sendTemporaryMessage($data['msg']);
        }

        $configCodeRaw = $code ?? '-';
        $subUrl = rtrim($panel->sub_address, '/') . $order->sub_id;
        $configCode = htmlspecialchars($configCodeRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subUrlSafe = htmlspecialchars($subUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $message = "<b>✅ جزئیات سفارش #{$order->id}</b>\n\n";
        $message .= "<b>کد کانفیگ:</b>
        <code>$configCode</code> \n";
        $message .= "<b>لینک ساب:</b>
         <code>$subUrlSafe</code> \n\n";

        $data = [
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'text' => $message,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '📄 جزئیات سفارش',
                            'callback_data' => "type=adminOrderSingle|id={$order->id}",
                        ]
                    ]
                ]
            ]),
            'parse_mode' => 'HTML',
        ];
        return $this->sendMessage($data, 'message');
    }


    public function ipsvp()
    {
        $update = $this->telData;

        $callbackData = data_get($update, 'callback_query.data');

        try {
            $wordpressWebhookUrl = 'https://ip-sabet.me/wp-json/ipsvp/v1/telegram/yUYdlWxRO0B6UiKNTPRt0yDP';

            $response = Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->post($wordpressWebhookUrl, $update);

            if (!$response->successful()) {
                Log::error('IP Sabet WP webhook failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'data' => $callbackData,
                ]);
                return $this->telegramSdk->answerCallback([
                    'callback_query_id' => $this->callbackId,
                    'text' => 'خطا در ارتباط با سایت. لطفا از پنل ادمین بررسی کنید.',
                    'show_alert' => true,
                    'cache_time' => 1,
                ]);
            }

            return response()->json(['ok' => true]);

        } catch (\Throwable $e) {
            Log::error('IP Sabet WP webhook exception', [
                'message' => $e->getMessage(),
                'data' => $callbackData,
            ]);

            return $this->telegramSdk->answerCallback([
                'callback_query_id' => $this->callbackId,
                'text' => 'خطا در پردازش درخواست.',
                'show_alert' => true,
                'cache_time' => 1,
            ]);

            return response()->json(['ok' => true]);
        }


    }
    /**
     * Admin Area
     */
    // Default Values
}
