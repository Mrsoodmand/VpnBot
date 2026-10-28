<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Carts;
use App\Models\Countries;
use App\Models\ExtraBandwidth;
use App\Models\Inbounds;
use App\Models\Orders;
use App\Models\Panels;
use App\Models\Plans;
use App\Models\Service;
use App\Services\BotCatalogService;
use App\Services\OrderPricingService;
use App\Services\PanelCatalogSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

trait ManagesBotCatalog
{
    private function adminButton(string $label, string $callback): array
    {
        if (str_starts_with($callback, 'type=adminC|')) {
            $callback = strtr($callback, ['|k=system_type' => '|k=sys', '|k=price_per_gb' => '|k=gb', '|k=sub_address' => '|k=sub', '|k=is_default' => '|k=def', '|v=pasarguard' => '|v=pg', '|v=sanaie' => '|v=sx']);
        }

        return ['text' => $label, 'callback_data' => $callback];
    }

    private function adminPage(string $title, string $body, array $rows = [], string $back = 'type=admin-home')
    {
        $rows[] = $this->adminFooterButtons($back);

        return $this->sendMessage(['chat_id' => $this->chatId, 'text' => headTitle($title).$body,
            'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $rows])], 'message');
    }

    private function catalogSpec(string $entity): ?array
    {
        $text = ['required', 'string', 'max:100'];
        $status = ['required', 'integer', 'in:-1,0,1'];
        $percent = ['required', 'integer', 'between:0,100'];
        $money = ['required', 'integer', 'between:0,2000000000'];

        return match ($entity) {
            'p' => [Panels::class, 'پنل اتصال', [
                'name' => ['نام پنل', $text], 'system_type' => ['سیستم پنل', ['required', 'in:pasarguard,sanaie']],
                'url' => ['آدرس پنل', ['required', 'url:http,https', 'max:255']],
                'username' => ['نام کاربری', ['required', 'string', 'max:255']],
                'password' => ['رمز عبور', ['required', 'string', 'max:255']],
                'sub_address' => ['آدرس اشتراک', ['nullable', 'url:http,https', 'max:255']],
                'status' => ['فروش از پنل', $status],
            ], 'type=adminPanelMenu'],
            'c' => [Countries::class, 'کشور', ['name' => ['نام و پرچم کشور', $text], 'status' => ['فروش کشور', $status]], 'type=adminPanelMenu'],
            'l' => [Plans::class, 'تعرفه', ['name' => ['نام تعرفه', $text],
                'bandwidth' => ['حجم (گیگابایت؛ صفر = نامحدود)', ['required', 'numeric', 'between:0,1000000']],
                'days' => ['اعتبار (روز)', ['required', 'integer', 'between:1,3650']],
                'price' => ['قیمت پایه (تومان)', $money], 'discount' => ['تخفیف تعرفه (درصد)', $percent], 'status' => ['فروش تعرفه', $status]], 'type=adminPanelMenu'],
            'x' => [ExtraBandwidth::class, 'بسته حجم اضافه', ['name' => ['حجم (گیگابایت)', ['required', 'numeric', 'between:0.1,1000000']],
                'discount' => ['تخفیف بسته (درصد)', $percent], 'status' => ['فروش بسته', $status]], 'type=adminPanelMenu'],
            'v' => [Service::class, 'قیمت حجم اضافه', ['price_per_gb' => ['قیمت پایه هر گیگ (تومان)', $money]], 'type=adminC|e=x'],
            'b' => [Carts::class, 'کارت بانکی', ['name' => ['صاحب کارت', $text],
                'cart' => ['شماره کارت', ['required', 'regex:/^[0-9]{16}$/']],
                'sheba' => ['شماره شبا (اختیاری)', ['nullable', 'regex:/^IR[0-9]{24}$/']],
                'is_default' => ['کارت پیش‌فرض', $status], 'status' => ['نمایش کارت', $status]], 'type=adminS|g=payment'],
            default => null,
        };
    }

    protected function adminCatalog($type = [])
    {
        if (! $this->isAdmin) {
            return $this->denyAdminAccess();
        }
        $entity = (string) ($type['e'] ?? 'c');
        $spec = $this->catalogSpec($entity);
        if (! $spec) {
            return $this->adminPage('مدیریت', 'این گزینه معتبر نیست.');
        }
        [$class, $title, $fields, $back] = $spec;
        $action = $type['a'] ?? 'list';
        $page = max(1, (int) ($type['page'] ?? 1));
        try {
            $vip = app(BotCatalogService::class)->vip();
        } catch (\RuntimeException $e) {
            if ($entity !== 'b') {
                return $this->adminPage('تنظیم سرویس', $this->escapeHtml($e->getMessage()), [], $back);
            }
            $vip = null;
        }
        if ($entity === 'v') {
            $type['id'] = $vip->id;
            if ($action === 'list') {
                $action = 'show';
            }
        }
        if ($action === 'list') {
            $query = $class::orderByDesc('id');
            if ($entity === 'l') {
                $query->whereNull('deleted_at');
            }
            $list = $query->paginate(10, ['*'], 'page', $page);
            $rows = [];
            if ($entity === 'c') {
                $rows[] = [$this->adminButton('🌍 همه کشورها و درصد افزایش', 'type=adminN|a=all')];
            }
            if ($entity === 'x') {
                $rows[] = [$this->adminButton('💰 قیمت هر گیگ', 'type=adminC|e=v')];
            }
            foreach ($list as $item) {
                $rows[] = [$this->adminButton(((int) $item->status === 1 ? '🟢 ' : '🔴 ').($item->name ?: 'بدون نام').($entity === 'x' ? ' گیگ' : ''), "type=adminC|e={$entity}|a=show|id={$item->id}")];
            }
            if ($pagination = $this->paginationFooterButton($list, $page, "adminC|e={$entity}")) {
                $rows[] = $pagination;
            }
            $rows[] = [$this->adminButton('➕ افزودن '.$title, "type=adminC|e={$entity}|a=new")];
            if ($entity === 'c') {
                $rows[] = [$this->adminButton('🔗 اتصال‌های بدون کشور', 'type=adminN|u=1')];
            }

            return $this->adminPage('مدیریت '.$title, "تعداد: {$list->total()} · صفحه {$list->currentPage()} از {$list->lastPage()}\n".
                ($entity === 'c' ? 'کشور را باز کنید تا نام، وضعیت و اتصال‌های آن را مدیریت کنید.' : 'برای مشاهده و ویرایش، یک مورد را انتخاب کنید.'), $rows, $back);
        }
        if ($action === 'new') {
            if ($entity === 'v') {
                return $this->adminCatalog(['e' => 'v']);
            }
            $item = new $class;
            $item->status = -1;
            if (in_array($entity, ['p', 'l', 'x'], true)) {
                $item->admin_id = $this->user->id;
            }
            if ($entity === 'p') {
                $item->panel_type = $vip->id;
                $item->system_type = 'pasarguard';
            } elseif (in_array($entity, ['c', 'l', 'x'], true)) {
                $item->type = $vip->id;
            }
            $item->save();

            return $this->adminCatalog(['e' => $entity, 'a' => 'show', 'id' => $item->id]);
        }
        $item = $class::find($type['id'] ?? 0);
        if (! $item) {
            return $this->adminPage($title, 'مورد انتخاب‌شده پیدا نشد؛ فهرست را دوباره باز کنید.', [], "type=adminC|e={$entity}");
        }
        $base = "type=adminC|e={$entity}|id={$item->id}";
        if ($action === 'edit') {
            $key = $type['k'] ?? '';
            if (! isset($fields[$key])) {
                return $this->adminCatalog(['e' => $entity, 'a' => 'show', 'id' => $item->id]);
            }
            $rows = [];
            if (in_array($key, ['status', 'is_default'], true)) {
                $rows[] = [$this->adminButton('🟢 فعال', "$base|a=save|k={$key}|v=1"), $this->adminButton('🔴 غیرفعال', "$base|a=save|k={$key}|v=-1")];
            } elseif ($key === 'system_type') {
                $rows[] = [$this->adminButton('PasarGuard', "$base|a=save|k=system_type|v=pasarguard"), $this->adminButton('X-Sanaei', "$base|a=save|k=system_type|v=sanaie")];
            } else {
                $detail = $this->user->tel_detail ?? [];
                $detail['catalog_edit'] = ['e' => $entity, 'id' => $item->id, 'k' => $key];
                $this->user->tel_detail = $detail;
                $this->user->save();
                $this->updatePath('adminCatalogSave');
            }
            $old = $key === 'password' ? ($item->$key ? 'ثبت شده' : 'ثبت نشده') : $this->escapeHtml($item->$key ?? '—');

            return $this->adminPage('ویرایش '.$fields[$key][0], "مقدار فعلی: <code>{$old}</code>\n\n".
                ($rows ? 'مقدار جدید را انتخاب کنید.' : 'مقدار جدید را ارسال کنید.'.(in_array($key, ['sub_address', 'sheba'], true) ? ' برای پاک کردن، - بفرستید.' : '')), $rows, "$base|a=show");
        }
        if ($action === 'save') {
            $key = $type['k'] ?? '';
            if (! isset($fields[$key])) {
                return $this->adminPage($title, 'فیلد ویرایش معتبر نیست.', [], "$base|a=show");
            }
            $value = $type['v'] ?? $this->text;
            if ($key !== 'password') {
                $value = trim((string) $value);
            }
            if (in_array($key, ['sub_address', 'sheba'], true) && $value === '-') {
                $value = null;
            }
            if ($key === 'sheba' && $value !== null) {
                $value = strtoupper(str_replace([' ', '-'], '', $value));
            }
            if ($key === 'cart') {
                $value = str_replace([' ', '-'], '', $value);
            }
            $validator = Validator::make(['value' => $value], ['value' => $fields[$key][1]]);
            if ($validator->fails()) {
                return $this->adminPage('مقدار نامعتبر', 'مقدار '.$fields[$key][0].' معتبر نیست. '.($key === 'status' ? '' : 'مقدار صحیح را دوباره بفرستید.'), [], "$base|a=edit|k={$key}");
            }
            if ($entity === 'p' && $key === 'system_type' && $item->system_type && $item->system_type !== $value && ($item->inbounds()->exists() || Orders::where('panel_id', $item->id)->exists())) {
                return $this->adminPage($title, 'این پنل اتصال یا سفارش ثبت‌شده دارد. برای سیستم دیگر، پنل جدید بسازید.', [], "$base|a=show");
            }
            $item->$key = $value;
            // The catalog is VIP-only; new and previously unassigned items need no type-selection step.
            if ($entity === 'p' && ! $item->panel_type) {
                $item->panel_type = $vip->id;
            }
            if (in_array($entity, ['c', 'l', 'x'], true) && ! $item->type) {
                $item->type = $vip->id;
            }
            if ($key === 'status' && (int) $value === 1) {
                $rules = [];
                foreach ($fields as $field => $definition) {
                    $rules[$field] = $definition[1];
                }
                if (Validator::make($item->getAttributes(), $rules)->fails()) {
                    return $this->adminPage($title, 'پیش از فعال‌سازی، فیلدهای لازم را کامل و صحیح وارد کنید.', [], "$base|a=show");
                }
                if ($entity === 'x' && (float) $vip->price_per_gb <= 0) {
                    return $this->adminPage($title, 'ابتدا قیمت هر گیگ را تنظیم کنید.', [[$this->adminButton('قیمت هر گیگ', 'type=adminC|e=v')]], "$base|a=show");
                }
            }
            if ($entity === 'p' && in_array($key, ['url', 'username', 'password'], true)) {
                $item->session = null;
                $panelDetail = $item->detail ?? [];
                foreach (['Expires', 'session', 'cookies'] as $sessionKey) {
                    unset($panelDetail[$sessionKey]);
                }
                $item->detail = $panelDetail;
                Cache::forget('pasarguard-token-'.$item->id);
            }
            if ($entity === 'b' && $key === 'is_default' && (int) $value === 1) {
                Carts::where('id', '!=', $item->id)->update(['is_default' => -1]);
            }
            $item->save();
            $detail = $this->user->tel_detail ?? [];
            unset($detail['catalog_edit']);
            $this->user->tel_detail = $detail;
            $this->user->save();
            $this->updatePath('start');

            return $this->adminCatalog(['e' => $entity, 'a' => 'show', 'id' => $item->id, 'saved' => true]);
        }
        if ($action === 'del') {
            return $this->adminPage('توقف فروش', 'سفارش‌های قبلی حفظ می‌شوند. فروش جدید این مورد غیرفعال شود؟', [[$this->adminButton('تأیید توقف فروش', "$base|a=save|k=status|v=-1")]], "$base|a=show");
        }
        $body = ! empty($type['saved']) ? "✅ با موفقیت بروزرسانی شد\n\n" : '';
        $rows = [];
        foreach ($fields as $key => [$label]) {
            $value = $item->$key;
            if ($key === 'password') {
                $value = $value ? 'ثبت شده' : 'ثبت نشده';
            } elseif (in_array($key, ['status', 'is_default'], true)) {
                $value = (int) $value === 1 ? '🟢 فعال' : '🔴 غیرفعال';
            }
            $body .= $label.': <code>'.$this->escapeHtml($value ?? '—')."</code>\n";
            $rows[] = [$this->adminButton('✏️ '.$label, "$base|a=edit|k={$key}")];
        }
        if ($entity === 'c') {
            $routes = app(BotCatalogService::class)->forCountry(Inbounds::catalogPresent(), $item->id)->count();
            $body .= "\nاتصال‌های متصل: {$routes}\n";
            $rows[] = [$this->adminButton('🔗 مدیریت اتصال‌های این کشور', "type=adminN|c={$item->id}")];
            $rows[] = [$this->adminButton('➕ اتصال اینباند / گروه', "type=adminN|a=pick|c={$item->id}")];
            $rows[] = [$this->adminButton('🔄 دریافت از پنل', "type=adminN|a=panels|c={$item->id}")];
        }
        if ($entity === 'p') {
            $rows[] = [$this->adminButton('🔌 بررسی اتصال', "type=adminN|a=check|p={$item->id}"), $this->adminButton('🔄 دریافت / به‌روزرسانی اتصال‌ها', "type=adminN|a=sync|p={$item->id}")];
            $rows[] = [$this->adminButton('🔗 گروه‌ها / اینباندها', "type=adminN|p={$item->id}")];
            if ($item->system_type === 'pasarguard') {
                $rows[] = [$this->adminButton('🌍 همه کشورها و قیمت', "type=adminN|a=all|p={$item->id}")];
            }
            $body .= "\nدریافت مجدد، کشور و وضعیت فروش اتصال‌های قبلی را حفظ می‌کند.\n";
        }
        if ($entity === 'l') {
            $body .= '\nقیمت پس از تخفیف تعرفه: '.number_format(app(OrderPricingService::class)->amount((float) $item->price, (float) $item->discount)).' تومان';
        }
        if ($entity === 'x' || $entity === 'v') {
            $body .= "\nنرخ هر گیگ برای خرید حجم اضافه است. افزایش قیمت همه کشورها جداگانه از تنظیم همان پنل اعمال می‌شود.";
            if ($entity === 'x') {
                $body .= "\nقیمت پایه بسته پس از تخفیف: ".number_format(app(OrderPricingService::class)->amount((float) $item->name * (float) $vip->price_per_gb, (float) $item->discount)).' تومان';
                $rows[] = [$this->adminButton('💰 قیمت هر گیگ', 'type=adminC|e=v')];
            }
        }

        return $this->adminPage($title, str_replace('\\n', "\n", $body), $rows, $entity === 'v' ? $back : "type=adminC|e={$entity}");
    }

    protected function adminCatalogSave()
    {
        if (! $this->isAdmin) {
            return $this->denyAdminAccess();
        }
        $context = $this->user->tel_detail['catalog_edit'] ?? null;
        if (! is_array($context)) {
            return $this->adminPage('ویرایش', 'این مرحله منقضی شده است؛ مورد را دوباره باز کنید.');
        }

        return $this->adminCatalog($context + ['a' => 'save']);
    }

    protected function adminNetwork($type = [])
    {
        if (! $this->isAdmin) {
            return $this->denyAdminAccess();
        }
        $action = $type['a'] ?? 'list';
        $panelId = (int) ($type['p'] ?? 0);
        $countryId = (int) ($type['c'] ?? 0);
        $id = (int) ($type['id'] ?? 0);
        $page = max(1, (int) ($type['page'] ?? 1));
        $catalog = app(BotCatalogService::class);
        $back = $countryId ? "type=adminC|e=c|a=show|id={$countryId}" : ($panelId ? "type=adminC|e=p|a=show|id={$panelId}" : 'type=adminC|e=c');
        $context = ($countryId ? "|c={$countryId}" : '').($panelId ? "|p={$panelId}" : '');
        $rows = [];
        if ($action === 'panels' || ($action === 'all' && ! $panelId)) {
            $query = Panels::query()->orderBy('name');
            if ($action === 'all') {
                $query->where('system_type', 'pasarguard');
            }
            $list = $query->paginate(10, ['*'], 'page', $page);
            foreach ($list as $panel) {
                $rows[] = [$this->adminButton($panel->name ?: 'پنل '.$panel->id, 'type=adminN|a='.($action === 'all' ? 'all' : 'sync')."|p={$panel->id}".($countryId ? "|c={$countryId}" : ''))];
            }
            if ($pagination = $this->paginationFooterButton($list, $page, "adminN|a={$action}".($countryId ? "|c={$countryId}" : ''))) {
                $rows[] = $pagination;
            }

            return $this->adminPage($action === 'all' ? 'همه کشورها' : 'انتخاب پنل', $list->total() ? 'پنل مورد نظر را انتخاب کنید.' : 'پنلی ثبت نشده است.', $rows, $back);
        }
        if (in_array($action, ['check', 'sync', 'all', 'allstatus', 'percent'], true)) {
            $panel = Panels::find($panelId);
            if (! $panel) {
                return $this->adminPage('اتصال', 'پنل پیدا نشد.', [], $back);
            }
            if ($action === 'check' || $action === 'sync') {
                try {
                    $service = app(PanelCatalogSyncService::class);
                    $result = $action === 'check' ? $service->check($panel) : $service->sync($panel);
                    $body = $action === 'check' ? '✅ اتصال به پنل برقرار است.' : "✅ {$result} اتصال از پنل دریافت شد.\nتنظیمات اتصال‌های موجود حفظ شد؛ اتصال‌های جدید غیرفعال هستند تا کشور و فروش آن‌ها را تنظیم کنید.\nاتصال‌های ناموجود به آرشیو منتقل شدند؛ سفارش‌های قبلی حفظ شده‌اند.";
                } catch (\RuntimeException $e) {
                    $body = '❌ '.$this->escapeHtml($e->getMessage());
                }

                return $this->adminPage('اتصال پنل', $body, [[$this->adminButton('مدیریت اتصال‌ها', 'type=adminN'.$context)]], $back);
            }
            if ($panel->system_type !== 'pasarguard') {
                return $this->adminPage('همه کشورها', 'این قابلیت مربوط به پنل پاسارگارد است.', [], $back);
            }
            $detail = $panel->detail ?? [];
            if ($action === 'allstatus') {
                $enabled = (int) ($type['v'] ?? -1) === 1;
                if ($enabled && ((int) $panel->status !== 1 || ! Inbounds::where('panel_id', $panel->id)->where('status', 1)->exists())) {
                    return $this->adminPage('همه کشورها', 'ابتدا پنل و حداقل یک گروه را فعال کنید.', [], $back);
                }
                $detail['status'] = $enabled ? 1 : 0;
                $panel->detail = $detail;
                $panel->save();
            }
            if ($action === 'percent') {
                $userDetail = $this->user->tel_detail ?? [];
                $userDetail['all_country_panel'] = $panel->id;
                $this->user->tel_detail = $userDetail;
                $this->user->save();
                $this->updatePath('adminAllCountryPercent');

                return $this->adminPage('افزایش قیمت همه کشورها', 'درصد افزایش قیمت را از صفر تا ۱۰۰۰ وارد کنید؛ مثلاً 70 برای افزایش ۷۰ درصدی. این درصد برای خرید، تمدید و هر گیگ حجم اضافه استفاده می‌شود.', [], "type=adminN|a=all|p={$panelId}");
            }
            $enabled = (int) ($detail['status'] ?? 0) === 1;
            $percent = app(OrderPricingService::class)->percent($panel);
            $body = 'پنل: '.$this->escapeHtml($panel->name)."\nوضعیت فروش: ".($enabled ? 'فعال' : 'غیرفعال')."\nافزایش قیمت: {$percent}٪\n\nقیمت خرید و تمدید = قیمت تعرفه × (۱ + درصد ÷ ۱۰۰)\nقیمت حجم اضافه = تعداد گیگ × نرخ هر گیگ × (۱ + درصد ÷ ۱۰۰)\nتخفیف‌ها پس از این افزایش اعمال می‌شوند.";
            $rows[] = [$this->adminButton($enabled ? '🔴 توقف فروش همه کشورها' : '🟢 فعال‌سازی همه کشورها', "type=adminN|a=allstatus|p={$panelId}|v=".($enabled ? 0 : 1))];
            $rows[] = [$this->adminButton('✏️ درصد افزایش قیمت', "type=adminN|a=percent|p={$panelId}")];
            $rows[] = [$this->adminButton('گروه‌های قابل فروش', "type=adminN|p={$panelId}")];

            return $this->adminPage('🌍 همه کشورها', $body, $rows, 'type=adminN|a=all');
        }
        if (in_array($action, ['show', 'toggle', 'assign', 'set', 'detach'], true)) {
            $route = Inbounds::with('panel')->find($id);
            if (! $route || ! $route->panel) {
                return $this->adminPage('اتصال', 'این اتصال یا پنل آن پیدا نشد.', [], $back);
            }
            if ($route->isCatalogMissing()) {
                $countryName = Countries::find($route->country_id)?->name ?? 'بدون کشور';
                $body = 'نام: '.$this->escapeHtml($route->remark)."\nپنل: ".$this->escapeHtml($route->panel->name).
                    "\nکشور قبلی: ".$this->escapeHtml($countryName).
                    "\nوضعیت: در آخرین دریافت از پنل موجود نیست؛ فروش متوقف است.\n\nسفارش‌های قبلی و اطلاعات آن‌ها محفوظ است. اگر سرویس کاربر بدون گروه شده، از «تغییر کشور» در جزئیات سفارش، مقصد فعال انتخاب کنید.";

                return $this->adminPage('🗂 اتصال آرشیوشده', $body,
                    [[$this->adminButton('🔄 دریافت مجدد از پنل', "type=adminN|a=sync|p={$route->panel_id}")]],
                    'type=adminN|a=arch'.($countryId ? "|c={$countryId}" : "|p={$route->panel_id}"));
            }
            $effectiveCountry = $route->country_id ?? ($route->panel->system_type === 'sanaie' ? $route->panel->country_id : null);
            if ($action === 'assign') {
                $list = Countries::where('type', $route->panel->panel_type)->orderBy('name')->paginate(10, ['*'], 'page', $page);
                foreach ($list as $country) {
                    $rows[] = [$this->adminButton($country->name ?: 'بدون نام', "type=adminN|a=set|id={$id}|c={$country->id}")];
                }
                if ($pagination = $this->paginationFooterButton($list, $page, "adminN|a=assign|id={$id}")) {
                    $rows[] = $pagination;
                }

                return $this->adminPage('انتخاب کشور اتصال', 'کشور مقصد را انتخاب کنید.', $rows, "type=adminN|a=show|id={$id}");
            }
            if ($action === 'set') {
                $country = Countries::where('type', $route->panel->panel_type)->find($countryId);
                if (! $country) {
                    return $this->adminPage('اتصال کشور', 'کشور با سرویس پنل سازگار نیست.', [], $back);
                }
                if ((int) $effectiveCountry !== 0 && (int) $effectiveCountry !== $countryId && ($type['ok'] ?? '') !== '1') {
                    return $this->adminPage('جابه‌جایی اتصال', 'این اتصال متعلق به کشور دیگری است. انتقال به '.$this->escapeHtml($country->name).' تأیید شود؟', [[$this->adminButton('تأیید انتقال', "type=adminN|a=set|id={$id}|c={$countryId}|ok=1")]], $back);
                }
                $route->country_id = $countryId;
                $route->save();
            }
            if ($action === 'detach') {
                if (($type['ok'] ?? '') !== '1') {
                    return $this->adminPage('قطع ارتباط کشور', 'کشور این اتصال حذف و فروش آن متوقف شود؟ سفارش‌های قبلی حفظ می‌شوند.', [[$this->adminButton('تأیید', "type=adminN|a=detach|id={$id}|ok=1")]], "type=adminN|a=show|id={$id}");
                }
                $route->country_id = 0;
                $route->status = -1;
                $route->save();
            }
            if ($action === 'toggle') {
                $enabled = (int) ($type['v'] ?? -1) === 1;
                if ($enabled && (int) $route->panel->status !== 1) {
                    return $this->adminPage('فروش اتصال', 'ابتدا فروش پنل را فعال کنید.', [], "type=adminC|e=p|a=show|id={$route->panel_id}");
                }
                $remote = json_decode((string) $route->setting, true) ?: [];
                if ($enabled && (! empty($remote['_bot_catalog_missing']) || ! empty($remote['is_disabled']) || (array_key_exists('inbound_tags', $remote) && empty($remote['inbound_tags'])) || (isset($remote['enable']) && ! $remote['enable']))) {
                    return $this->adminPage('فروش اتصال', 'این اتصال در آخرین دریافت از پنل، حذف‌شده یا غیرفعال بوده است. ابتدا پنل را اصلاح و دریافت اتصال‌ها را دوباره اجرا کنید.', [], $back);
                }
                $route->status = $enabled ? 1 : -1;
                $route->save();
            }
            $effectiveCountry = $route->country_id ?? ($route->panel->system_type === 'sanaie' ? $route->panel->country_id : null);
            $countryName = Countries::find($effectiveCountry)?->name ?? 'بدون کشور';
            $enabled = (int) $route->status === 1;
            $body = 'نام: '.$this->escapeHtml($route->remark).'\nپنل: '.$this->escapeHtml($route->panel->name).'\nکشور: '.$this->escapeHtml($countryName).'\nوضعیت فروش: '.($enabled ? 'فعال' : 'غیرفعال');
            $rows = [[$this->adminButton($enabled ? '🔴 توقف فروش اتصال' : '🟢 فعال‌سازی اتصال', "type=adminN|a=toggle|id={$id}|v=".($enabled ? -1 : 1))],
                [$this->adminButton('🌍 تغییر کشور', "type=adminN|a=assign|id={$id}"), $this->adminButton('قطع ارتباط کشور', "type=adminN|a=detach|id={$id}")],
                [$this->adminButton('🖥 پنل و بررسی اتصال', "type=adminC|e=p|a=show|id={$route->panel_id}")]];
            $back = $countryId ? "type=adminN|c={$countryId}" : "type=adminN|p={$route->panel_id}";

            return $this->adminPage($route->panel->system_type === 'pasarguard' ? 'گروه پاسارگارد' : 'اینباند', str_replace('\\n', "\n", $body), $rows, $back);
        }
        $query = Inbounds::with('panel')->whereHas('panel');
        if ($panelId) {
            $query->where('panel_id', $panelId);
        }
        if ($countryId && $action !== 'pick') {
            $catalog->forCountry($query, $countryId);
        }
        if ($countryId && $action === 'pick') {
            $country = Countries::find($countryId);
            if (! $country) {
                return $this->adminPage('کشور', 'کشور پیدا نشد.', [], 'type=adminC|e=c');
            }
            $query->whereHas('panel', fn ($q) => $q->where('panel_type', $country->type));
        }
        if (! empty($type['u'])) {
            $query->where(fn ($q) => $q->where('country_id', 0)->orWhere(fn ($q) => $q->whereNull('country_id')->whereHas('panel', fn ($p) => $p->where('system_type', 'pasarguard')->orWhereNull('country_id')->orWhere('country_id', 0))));
        }
        $archived = $action === 'arch';
        $missingCount = $action !== 'pick' ? (clone $query)->catalogMissing()->count() : 0;
        $archived ? $query->catalogMissing() : $query->catalogPresent();
        $list = $query->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', ['1'])
            ->orderByDesc('id')->paginate(10, ['*'], 'page', $page);
        foreach ($list as $route) {
            $rows[] = [$this->adminButton(($archived ? '🗂 ' : ((int) $route->status === 1 ? '🟢 ' : '🔴 ')).$route->remark.' · '.$route->panel->name,
                'type=adminN|a='.($action === 'pick' ? 'set' : 'show')."|id={$route->id}".($countryId ? "|c={$countryId}" : ''))];
        }
        $filter = "adminN|a={$action}".$context.(! empty($type['u']) ? '|u=1' : '');
        if ($pagination = $this->paginationFooterButton($list, $page, $filter)) {
            $rows[] = $pagination;
        }
        if ($countryId && ! in_array($action, ['pick', 'arch'], true)) {
            $rows[] = [$this->adminButton('➕ اتصال گروه / اینباند', "type=adminN|a=pick|c={$countryId}")];
        }
        if ($archived) {
            $rows[] = [$this->adminButton('🔗 اتصال‌های موجود', 'type=adminN'.$context.(! empty($type['u']) ? '|u=1' : ''))];
        } elseif ($missingCount > 0) {
            $rows[] = [$this->adminButton("🗂 آرشیو اتصال‌های ناموجود ({$missingCount})", 'type=adminN|a=arch'.$context.(! empty($type['u']) ? '|u=1' : ''))];
        }
        $rows[] = [$this->adminButton('🔄 دریافت از پنل', 'type=adminN|a='.($panelId ? 'sync' : 'panels').$context)];

        return $this->adminPage($archived ? '🗂 آرشیو اتصال‌ها' : '🔗 اتصال‌های کشور',
            "تعداد: {$list->total()} · صفحه {$list->currentPage()} از {$list->lastPage()}\n".
            ($archived ? 'این اتصال‌ها در آخرین دریافت از پنل موجود نبودند. سفارش‌های قبلی حفظ شده‌اند.' :
                ($list->total() ? 'اتصال‌های فعال در ابتدای فهرست هستند. برای مدیریت، اتصال را باز کنید.' : 'اتصالی پیدا نشد؛ از پنل دریافت کنید.')), $rows, $back);
    }

    protected function adminAllCountryPercent()
    {
        if (! $this->isAdmin) {
            return $this->denyAdminAccess();
        }
        $panel = Panels::where('system_type', 'pasarguard')->find($this->user->tel_detail['all_country_panel'] ?? 0);
        $value = trim((string) $this->text);
        if (! $panel || ! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 1000) {
            return $this->adminPage('درصد نامعتبر', 'عدد بین صفر تا ۱۰۰۰ وارد کنید.');
        }
        $detail = $panel->detail ?? [];
        $detail['percent'] = (float) $value;
        $panel->detail = $detail;
        $panel->save();
        $this->updatePath('start');

        return $this->adminNetwork(['a' => 'all', 'p' => $panel->id]);
    }
}
