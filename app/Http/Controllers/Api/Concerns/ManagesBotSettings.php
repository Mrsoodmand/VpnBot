<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Payment;
use App\Models\Plans;
use App\Models\Setting;
use App\Services\BotCatalogService;
use App\Services\PaymentReceiptFormatter;
use App\Services\OrderNotificationSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

trait ManagesBotSettings
{
    protected function adminMenu($type = [])
    {
        return $this->adminPage('👑 مدیریت ربات', 'از بخش مورد نظر وارد شوید. فروش ربات فقط VIP است.', [
            [$this->adminButton('🌍 کشورها و اتصال‌ها', 'type=adminC|e=c'), $this->adminButton('🖥 پنل‌های اتصال', 'type=adminC|e=p')],
            [$this->adminButton('📦 تعرفه‌های فروش', 'type=adminC|e=l'), $this->adminButton('💾 حجم اضافه و نرخ هر گیگ', 'type=adminC|e=x')],
            [$this->adminButton('👥 کاربران', 'type=adminUserList'), $this->adminButton('📑 سفارش‌ها', 'type=adminOrdersList')],
            [$this->adminButton('🧾 رسیدها و تحویل سفارش', 'type=adminReceipts')],
            [$this->adminButton('💳 پرداخت و کیف پول', 'type=adminS|g=payment'), $this->adminButton('⚙️ تنظیمات ربات', 'type=adminS')],
            [$this->adminButton('⭐ تخفیف پلکانی کاربران عادی', 'type=adminCustomerLoyalty')],
            [$this->adminButton('🔔 اعلان حجم و تمدید', 'type=adminS|g=notices')],
            [$this->adminButton('🩺 بررسی آمادگی فروش', 'type=adminHealth'), $this->adminButton('📘 راهنمای مدیریت', 'type=adminServiceHelp')],
        ], 'type=home');
    }

    protected function adminPanelMenu($type = [])
    {
        return $this->adminPage('کاتالوگ فروش VIP', 'کشور و اتصال‌هایش از صفحهٔ همان کشور مدیریت می‌شوند.', [
            [$this->adminButton('🌍 کشورها', 'type=adminC|e=c'), $this->adminButton('🌐 همه کشورها', 'type=adminN|a=all')],
            [$this->adminButton('🖥 پنل‌ها', 'type=adminC|e=p')],
            [$this->adminButton('📦 تعرفه‌ها', 'type=adminC|e=l'), $this->adminButton('💾 حجم اضافه', 'type=adminC|e=x')],
            [$this->adminButton('🔗 اتصال‌های بدون کشور', 'type=adminN|u=1')],
        ]);
    }

    private function botSettingDefinitions(): array
    {
        return [
            'sell' => ['فروش سرویس جدید', 'sales', 'bool', '1'],
            'renew' => ['تمدید سرویس', 'sales', 'bool', '1'],
            'extra' => ['خرید حجم اضافه', 'sales', 'bool', '1'],
            'notify_renew' => ['اعلان نزدیک شدن انقضا', 'notices', 'bool', '1'],
            'notify_volume' => ['اعلان کم شدن حجم', 'notices', 'bool', '1'],
            'notify_hours' => ['اعلان چند ساعت قبل از انقضا', 'notices', 'notice_hours', '24'],
            'notify_gb' => ['حجم باقی‌مانده برای اعلان (گیگ)', 'notices', 'notice_gb', '1'],
            'notify_interval' => ['فاصله بررسی پنل (دقیقه)', 'notices', 'notice_interval', '10'],
            'join-bot' => ['ثبت‌نام کاربر جدید', 'access', 'bool', '1'],
            'channel-join' => ['عضویت اجباری کانال', 'access', 'bool', '0'],
            'cart_be_cart' => ['پرداخت کارت به کارت', 'payment', 'bool', '0'],
            'cart_be_cart_random' => ['انتخاب تصادفی کارت', 'payment', 'bool', '0'],
            'charge_amount' => ['مبالغ پیشنهادی شارژ (تومان)', 'payment', 'amounts', '100000,200000,500000'],
            'cart_be_cart_id' => ['شناسه کانال / گروه رسیدها', 'channels', 'chat', ''],
            'report_id' => ['شناسه گزارش‌ها', 'channels', 'chat', ''],
            'channel_id' => ['کانال عضویت اجباری', 'channels', 'channel', ''],
            'support_id' => ['نام کاربری پشتیبانی', 'channels', 'username', ''],
            'home-page' => ['متن خوش‌آمدگویی', 'texts', 'text', 'به ربات فروش خوش آمدید.'],
            'cart_be_cart_text' => ['راهنمای ارسال رسید', 'texts', 'text', 'پس از واریز، تصویر رسید را ارسال کنید.'],
            'join-with-referral' => ['ثبت‌نام با معرف (قدیمی)', 'legacy', 'bool', '0'],
            'referral' => ['پورسانت معرف (قدیمی)', 'legacy', 'bool', '0'],
            'commission' => ['درصد پورسانت (قدیمی)', 'legacy', 'percent', '0'],
            'commission_text' => ['متن پورسانت (قدیمی)', 'legacy', 'text', '—'],
        ];
    }

    protected function adminSettings($type = [])
    {
        if (! $this->isAdmin) {
            return $this->denyAdminAccess();
        }
        $groups = ['sales' => 'فروش و تمدید', 'payment' => 'پرداخت و کیف پول', 'access' => 'ثبت‌نام و عضویت',
            'channels' => 'کانال‌ها و پشتیبانی', 'texts' => 'متن‌های ربات', 'notices' => '🔔 اعلان حجم و تمدید', 'legacy' => 'تنظیمات قدیمی'];
        $definitions = $this->botSettingDefinitions();
        $key = $type['k'] ?? '';
        if ($key !== '') {
            if (! isset($definitions[$key])) {
                return $this->adminPage('تنظیمات', 'این تنظیم قابل ویرایش نیست.');
            }
            [$label, $group, $kind, $default] = $definitions[$key];
            $setting = Setting::firstOrCreate(['key' => $key], ['name' => $label, 'value' => $default]);
            $back = "type=adminS|g={$group}";
            if (($type['a'] ?? '') === 'save') {
                $value = trim((string) ($type['v'] ?? $this->text));
                if (str_starts_with($kind, 'notice_')) {
                    $value = strtr($value, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9', '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9', '٫'=>'.']);
                }
                if (in_array($kind, ['chat', 'channel', 'username'], true)) {
                    $value = preg_replace('#^https?://t\.me/#i', '', $value);
                    $value = ltrim($value, '@');
                    if ($kind !== 'username' && ! preg_match('/^-?\d+$/', $value)) {
                        $value = '@'.$value;
                    }
                }
                if ($kind === 'amounts') {
                    $parts = preg_split('/[,،\s]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
                    if (! $parts || count($parts) > 20 || collect($parts)->contains(fn ($n) => ! ctype_digit($n) || (int) $n < 1000 || (int) $n > 2000000000)) {
                        return $this->adminPage('مبلغ نامعتبر', 'حداکثر ۲۰ مبلغ صحیح از ۱٬۰۰۰ تا ۲٬۰۰۰٬۰۰۰٬۰۰۰ تومان با ویرگول جدا کنید.', [], $back);
                    }
                    $parts = array_unique(array_map('intval', $parts));
                    sort($parts, SORT_NUMERIC);
                    $value = implode(',', $parts);
                }
                $rules = match ($kind) {
                    'bool' => ['required', 'in:0,1,-1'], 'percent' => ['required', 'numeric', 'between:0,100'],
                    'notice_hours' => ['required', 'integer', 'between:1,720'],
                    'notice_gb' => ['required', 'numeric', 'between:0.1,1000'],
                    'notice_interval' => ['required', 'integer', 'between:5,60'],
                    'username' => ['required', 'regex:/^[a-zA-Z][a-zA-Z0-9_]{4,31}$/'],
                    'chat', 'channel' => ['required', 'regex:/^(?:-?[0-9]{1,20}|@[a-zA-Z][a-zA-Z0-9_]{4,31})$/'],
                    'text' => ['required', 'string', 'max:2500'], default => ['required', 'string', 'max:255'],
                };
                if (Validator::make(['value' => $value], ['value' => $rules])->fails()) {
                    return $this->adminPage('مقدار نامعتبر', 'مقدار '.$label.' معتبر نیست؛ مقدار صحیح را دوباره ارسال کنید.', [], $back);
                }
                if ($key === 'channel-join' && (int) $value === 1 && ! Setting::where('key', 'channel_id')->where('value', '!=', '')->exists()) {
                    return $this->adminPage('عضویت کانال', 'ابتدا کانال عضویت اجباری را تنظیم کنید.', [[$this->adminButton('تنظیم کانال', 'type=adminS|k=channel_id')]], $back);
                }
                if ($key === 'sell' && (int) $value === 1) {
                    try {
                        $vip = app(BotCatalogService::class)->vip();
                    } catch (\RuntimeException $e) {
                        return $this->adminPage('فروش VIP', $this->escapeHtml($e->getMessage()), [], $back);
                    }
                    $vip->status = 1;
                    $vip->save();
                }
                $setting->value = $value;
                $setting->save();
                $detail = $this->user->tel_detail ?? [];
                unset($detail['bot_setting_edit']);
                $this->user->tel_detail = $detail;
                $this->user->save();
                $this->updatePath('admin-home');

                return $this->adminSettings(['g' => $group]);
            }
            $rows = [];
            if ($kind === 'bool') {
                $rows[] = [$this->adminButton('فعال', "type=adminS|a=save|k={$key}|v=1"), $this->adminButton('غیرفعال', "type=adminS|a=save|k={$key}|v=0")];
            } else {
                $detail = $this->user->tel_detail ?? [];
                $detail['bot_setting_edit'] = $key;
                $this->user->tel_detail = $detail;
                $this->user->save();
                $this->updatePath('adminSettingsSave');
            }
            $hint = match ($kind) {
                'amounts' => 'مبالغ را به تومان با ویرگول جدا کنید؛ مانند 100000,200000.',
                'chat', 'channel' => 'شناسه عددی مثل -1001234567890 یا @نام‌کانال را بفرستید. ربات باید دسترسی لازم را داشته باشد.',
                'username' => 'نام کاربری پشتیبانی را بفرستید؛ مانند @support_name.',
                'text' => 'متن سادهٔ جدید را بفرستید (حداکثر ۲۵۰۰ نویسه).', default => 'مقدار جدید را انتخاب یا ارسال کنید.',
                'notice_hours' => 'تعداد ساعت را از ۱ تا ۷۲۰ بفرستید؛ مثلاً ۲۴ یعنی یک روز قبل از پایان سرویس.',
                'notice_gb' => 'حجم باقی‌مانده را بین ۰٫۱ تا ۱۰۰۰ گیگ بفرستید؛ مثلاً ۱ یا ۰٫۵. سرویس نامحدود اعلان حجم ندارد.',
                'notice_interval' => 'فاصلهٔ بررسی گروهی پنل را از ۵ تا ۶۰ دقیقه بفرستید. مقدار پیشنهادی: ۱۰. فاصلهٔ کمتر یعنی درخواست بیشتر.',
            };

            return $this->adminPage($label, 'مقدار فعلی: <code>'.$this->escapeHtml($setting->value)."</code>\n\n".$hint, $rows, $back);
        }
        $group = $type['g'] ?? '';
        $rows = [];
        if (! isset($groups[$group])) {
            foreach ($groups as $id => $label) {
                $rows[] = [$this->adminButton($label, "type=adminS|g={$id}")];
            }
            $rows[] = [$this->adminButton('⭐ تخفیف پلکانی کاربران عادی', 'type=adminCustomerLoyalty'), $this->adminButton('🩺 بررسی تنظیمات', 'type=adminHealth')];

            return $this->adminPage('⚙️ تنظیمات ربات', 'بخش مورد نظر را انتخاب کنید.', $rows);
        }
        foreach ($definitions as $key => [$label, $belongs, $kind, $default]) {
            if ($belongs !== $group) {
                continue;
            }
            $value = Setting::where('key', $key)->value('value') ?? $default;
            $suffix = $kind === 'bool' ? ((int) $value === 1 ? ' · 🟢' : ' · 🔴') : '';
            $rows[] = [$this->adminButton($label.$suffix, "type=adminS|k={$key}")];
        }
        if ($group === 'payment') {
            $rows[] = [$this->adminButton('💳 کارت‌ها و شباها', 'type=adminC|e=b')];
        }
        if ($group === 'sales') {
            $rows[] = [$this->adminButton('🌍 قیمت همه کشورها', 'type=adminN|a=all'), $this->adminButton('💰 قیمت هر گیگ', 'type=adminC|e=v')];
        }
        $body = $group === 'legacy' ? 'این تنظیمات برای حفظ داده‌های نسخه قدیمی در دسترس‌اند. در سورس فعلی، جریان ثبت معرف و پرداخت پورسانت پیاده‌سازی نشده است و تغییر این مقادیر، پرداخت خودکار ایجاد نمی‌کند.' : 'هر تنظیم را برای مشاهدهٔ مقدار و ویرایش باز کنید.';
        if ($group === 'payment') {
            $body .= "\nپرداخت آنلاین در این نسخه پیاده‌سازی نشده؛ روش‌های موجود، کیف پول و کارت به کارت هستند.";
        }
        if ($group === 'notices') {
            $settings = OrderNotificationSettings::load();
            $body = 'اعلان زمان: '.$settings['hours'].' ساعت قبل از انقضا'
                ."\nاعلان حجم: ".$settings['gb'].' گیگ یا کمتر'
                ."\nفاصله بررسی گروهی پنل: ".$settings['interval'].' دقیقه'
                ."\n\nپیام‌ها کوتاه و دارای دکمهٔ اقدام هستند؛ هر نوع اعلان در هر دورهٔ سرویس یک بار ارسال می‌شود. غیرفعال بودن تمدید یا خرید حجم، اعلان مربوط را متوقف می‌کند."
                ."\nفقط سفارش‌های موعدرسیده، حداکثر ۲۰۰ سفارش برای اعلان در هر نوبت، بررسی می‌شوند. سفارش‌های قدیمی پس از نصب به‌تدریج زمان‌بندی می‌شوند."
                ."\nبرای اجرا، زمان‌بند سرور باید هر دقیقه فعال باشد و نصب جدول زمان‌بندی انجام شده باشد.";
            $last = Cache::get('orders:lifecycle:last-run');
            if (is_array($last)) {
                $body .= "\n\nآخرین اجرای زمان‌بند: ".$this->escapeHtml($last['at'] ?? '—')
                    ."\nدرخواست گروهی این اجرا: ".($last['synchronization']['requests'] ?? 0)
                    ." · بررسی‌شده: ".($last['synchronization']['checked'] ?? 0)
                    ."\nبررسی اعلان از دیتابیس: ".($last['notifications']['checked'] ?? 0)
                    ."\nاعلان ارسال‌شده: ".(($last['notifications']['renewal_sent'] ?? 0) + ($last['notifications']['volume_sent'] ?? 0))
                    ." · خطا: ".(($last['synchronization']['failed'] ?? 0) + ($last['notifications']['failed'] ?? 0));
                if (!empty($last['at']) && \Carbon\Carbon::parse($last['at'])->lt(now()->subMinutes(5))) {
                    $body .= "\n⚠️ زمان‌بند بیش از ۵ دقیقه اجرا نشده؛ کرون و لاگ سرور را بررسی کنید.";
                }
            } else {
                $body .= "\n\nهنوز اجرای زمان‌بند ثبت نشده است؛ پس از نصب، کرون سرور را بررسی کنید.";
            }
        }

        return $this->adminPage($groups[$group], $body, $rows, 'type=adminS');
    }

    protected function adminSettingsSave()
    {
        return $this->adminSettings(['a' => 'save', 'k' => $this->user->tel_detail['bot_setting_edit'] ?? '']);
    }

    protected function adminHealth($type = [])
    {
        $body = '';
        try {
            $vip = app(BotCatalogService::class)->vip();
            $body .= 'VIP: '.$this->escapeHtml($vip->name).' · '.((int) $vip->status === 1 ? 'فعال' : 'غیرفعال')."\n";
            $body .= 'تعرفه‌های فعال: '.Plans::where('type', $vip->id)->where('status', 1)->whereNull('deleted_at')->count()."\n";
            $body .= 'اتصال‌های قابل فروش: '.app(BotCatalogService::class)->routes($vip->id)->count()."\n";
            $body .= 'پنل‌های همه کشورها: '.app(BotCatalogService::class)->allCountryPanels($vip->id)->count()."\n";
            $body .= 'قیمت هر گیگ: '.number_format((float) $vip->price_per_gb)." تومان\n";
        } catch (\RuntimeException $e) {
            $body .= $this->escapeHtml($e->getMessage())."\n";
        }
        $body .= "\nشناسه دریافت رسید: ".(Setting::where('key', 'cart_be_cart_id')->value('value') ? 'ثبت شده' : 'ثبت نشده؛ ادمین پیش‌فرض استفاده می‌شود');
        $body .= "\nاتصال تخفیف سایت: ".(config('loyalty.enabled') ? 'فعال' : 'غیرفعال');
        $body .= "\n\nاین صفحه وضعیت محلی را نشان می‌دهد. آزمایش واقعی اتصال، داخل صفحه هر پنل است. اطلاعات محرمانه اتصال سایت و توکن ربات در تنظیمات سرور نگهداری می‌شوند.";

        return $this->adminPage('🩺 آمادگی فروش', $body, [[$this->adminButton('پنل‌ها و بررسی اتصال', 'type=adminC|e=p')], [$this->adminButton('کشورها', 'type=adminC|e=c'), $this->adminButton('تعرفه‌ها', 'type=adminC|e=l')]]);
    }

    protected function adminServiceHelp($type = [])
    {
        return $this->adminPage('📘 راهنمای مدیریت', "۱. پنل‌ها: آدرس و اطلاعات ورود را ثبت و اتصال را بررسی کنید.\n۲. دریافت اتصال‌ها: گروه‌ها / اینباندها را از پنل دریافت کنید.\n۳. کشورها: کشور را بسازید، اتصال را از همان صفحه وصل و فعال کنید.\n۴. تعرفه‌ها: حجم، روز، مبلغ و تخفیف را تعیین کنید. VIP خودکار است.\n۵. همه کشورها: فروش و درصد افزایش را از بالای فهرست کشورها تنظیم کنید.\n۶. حجم اضافه: بسته‌ها و قیمت هر گیگ در یک بخش هستند.\n۷. رسیدها: تأیید، رد و تلاش مجدد تحویل از جزئیات تراکنش انجام می‌شود.\n\nغیرفعال‌سازی، سفارش‌های قبلی را حذف نمی‌کند. پنل‌های قدیمی و تاریخچهٔ مالی حفظ می‌شوند.");
    }

    protected function adminReceipts($type = [])
    {
        if (! $this->isAdmin) {
            return $this->denyAdminAccess();
        }
        $page = max(1, (int) ($type['page'] ?? 1));
        $status = $type['f'] ?? 'pending';
        $query = Payment::orderByDesc('id');
        if ($status === 'pending') {
            $query->where('status', 0)->where('method', 'cart-be-cart')->whereNotNull('detail->value');
        } elseif ($status === 'failed') {
            $query->where('status', 1)->whereIn('type', [1, 2, 3])->whereIn('method', ['cart-be-cart', 'wallet'])->where(fn ($q) => $q->whereNull('detail->fulfillment->status')->orWhere('detail->fulfillment->status', '!=', 'completed'));
        }
        $list = $query->paginate(10, ['*'], 'page', $page);
        $rows = [[$this->adminButton('رسیدهای منتظر بررسی', 'type=adminReceipts'), $this->adminButton('تحویل‌های نیازمند پیگیری', 'type=adminReceipts|f=failed')], [$this->adminButton('همه تراکنش‌ها', 'type=adminReceipts|f=all')]];
        foreach ($list as $payment) {
            $rows[] = [$this->adminButton('#'.$payment->id.' · '.number_format($payment->price).' تومان', "type=adminReceipt|id={$payment->id}")];
        }
        if ($pagination = $this->paginationFooterButton($list, $page, "adminReceipts|f={$status}")) {
            $rows[] = $pagination;
        }

        return $this->adminPage('🧾 رسیدها و تحویل', $list->total() ? 'تراکنش را برای جزئیات و اقدام باز کنید.' : 'تراکنشی در این فهرست نیست.', $rows);
    }

    protected function adminReceipt($type)
    {
        if (! $this->isAdmin) {
            return $this->denyAdminAccess();
        }
        $payment = Payment::find($type['id'] ?? 0);
        if (! $payment) {
            return $this->adminReceipts([]);
        }
        if (! in_array((string) $payment->type, ['1', '2', '3', '4'], true)) {
            return $this->adminPage('تراکنش #'.$payment->id, $this->formatTransactionEntry($payment, true), [], 'type=adminReceipts');
        }
        $state = match ((int) $payment->status) {
            -1 => 'rejected', -2 => 'refunded', 1 => $this->canRetryPayment($payment) ? 'needs_review' : 'approved', default => 'pending'
        };
        $rows = [];
        $detail = $payment->detail ?? [];
        // A payment draft or an unsubmitted receipt must never acquire approval buttons here.
        if ((string) $payment->status === '0' && $payment->method === 'cart-be-cart' && ! empty($detail['value']) && ($detail['receipt_submission_state'] ?? '') !== 'sending') {
            $rows[] = [$this->adminButton('✅ تأیید رسید', 'type=adminConfirmCartReceipt|p_id='.$payment->id), $this->adminButton('❌ رد رسید', 'type=adminRejectCartReceipt|p_id='.$payment->id)];
        }
        if ($this->canRetryPayment($payment)) {
            $rows[] = [$this->adminButton('🔄 تلاش مجدد تحویل', 'type=adminRetryPayment|p_id='.$payment->id)];
        }
        $rows[] = [$this->adminButton('تراکنش‌های این کاربر', 'type=adminUserPayments|id='.$payment->user_id)];
        $parts = app(PaymentReceiptFormatter::class)->parts($payment, $state, 3300);
        // Send the saved receipt in the admin's current chat so its photo remains reviewable.
        if (! empty($detail['receipt_is_photo']) && ! empty($detail['value'])) {
            $this->telegramSdk->sendPhoto(['chat_id' => $this->chatId, 'photo' => $detail['value'], 'caption' => headTitle('🧾 تصویر رسید #'.$payment->id), 'parse_mode' => 'HTML']);
        }
        $body = array_shift($parts);
        foreach ($parts as $part) {
            $this->telegramSdk->sendMessage(['chat_id' => $this->chatId, 'text' => headTitle('🧾 ادامهٔ جزئیات تراکنش #'.$payment->id).$part, 'parse_mode' => 'HTML']);
        }
        if (empty($detail['receipt_is_photo']) && ! empty($detail['value'])) {
            $body .= "\nرسید: ".$this->escapeHtml(mb_substr((string) $detail['value'], 0, 300));
        }

        return $this->adminPage('تراکنش #'.$payment->id, $body, $rows, 'type=adminReceipts');
    }
}
