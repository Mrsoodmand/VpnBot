<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Countries;
use App\Models\Plans;
use App\Models\Setting;
use App\Services\BotCatalogService;
use App\Services\CustomerLoyaltyService;
use App\Services\OrderPricingService;

trait BotPurchaseFlow
{
    protected function clientService($type = [])
    {
        if ((int) Setting::where('key', 'channel-join')->value('value') === 1) {
            $this->ifUserIsJoined();
            if (! $this->isJoined) {
                return $this->joinFirst();
            }
        }

        return $this->clientSelectCountry($type);
    }

    private function purchasePage(string $title, string $text, array $keyboard)
    {
        return $this->sendMessage(['chat_id' => $this->chatId, 'text' => headTitle($title).$text,
            'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $keyboard])], 'message');
    }

    private function purchaseError(string $text)
    {
        return $this->purchasePage('خرید سرویس', $this->escapeHtml($text), [
            [['text' => '🔄 انتخاب کشور', 'callback_data' => 'type=clientService']],
            $this->clientFooterButtons(),
        ]);
    }

    protected function clientSelectCountry($type = [])
    {
        $catalog = app(BotCatalogService::class);
        try {
            $service = $catalog->vip();
        } catch (\RuntimeException $e) {
            return $this->purchaseError($e->getMessage());
        }
        if ((int) $service->status !== 1 || ! $catalog->enabled('sell')) {
            return $this->purchaseError('فروش سرویس در حال حاضر غیرفعال است.');
        }
        $page = max(1, (int) ($type['page'] ?? 1));
        $ids = $catalog->routes($service->id)->with('panel')->get()->map(fn ($route) => $route->country_id ?? $route->panel->country_id)->filter()->unique();
        $list = Countries::where('type', $service->id)->where('status', 1)->whereIn('id', $ids)
            ->orderBy('name')->paginate(10, ['*'], 'page', $page);
        $keyboard = [];
        foreach ($list as $country) {
            $keyboard[] = [['text' => $country->name ?: 'بدون نام',
                'callback_data' => "type=CSP|s={$service->id}|c={$country->id}|p=0"]];
        }
        $panels = $catalog->allCountryPanels($service->id);
        foreach ($panels as $panel) {
            $keyboard[] = [['text' => '🌍 همه کشورها'.($panels->count() > 1 ? ' · '.$panel->name : ''),
                'callback_data' => "type=CSP|s={$service->id}|c=0|p={$panel->id}"]];
        }
        $text = 'کشور مورد نظر را انتخاب کنید.';
        if ($panels->isNotEmpty()) {
            $text .= "\n\n🌍 همه کشورها: اتصال به تمام کشورهای فعال این پنل با یک اشتراک.";
        }
        if ($list->total() === 0 && $panels->isEmpty()) {
            $text = 'در حال حاضر کشوری برای فروش آماده نیست؛ لطفاً با پشتیبانی تماس بگیرید.';
        }
        if ($pagination = $this->paginationFooterButton($list, $page, 'clientSelectCountry')) {
            $keyboard[] = $pagination;
        }
        $keyboard[] = $this->clientFooterButtons();

        return $this->purchasePage('🌍 انتخاب کشور', $text, $keyboard);
    }

    private function purchaseSelection(array $type, bool $plan = false): ?array
    {
        try {
            return app(BotCatalogService::class)->selection($type, $plan);
        } catch (\RuntimeException $e) {
            $this->purchaseError($e->getMessage());

            return null;
        }
    }

    private function purchaseParams(array $selection): string
    {
        return 's='.$selection['service']->id.'|c='.($selection['country']?->id ?? 0).'|p='.($selection['panel']?->id ?? 0);
    }

    protected function clientSelectPlan($type)
    {
        if (! $selection = $this->purchaseSelection($type)) {
            return;
        }
        ['service' => $service, 'country' => $country, 'panel' => $panel] = $selection;
        $page = max(1, (int) ($type['page'] ?? 1));
        $list = Plans::where('type', $service->id)->where('status', 1)->whereNull('deleted_at')->orderBy('id')->paginate(10, ['*'], 'page', $page);
        $params = $this->purchaseParams($selection);
        $keyboard = [];
        $loyalty = app(CustomerLoyaltyService::class);
        foreach ($list as $plan) {
            $base = app(OrderPricingService::class)->amount((float) $plan->price, (float) $plan->discount, $panel);
            try {
                $price = number_format($loyalty->quote($this->user, (int) $service->id, $base)['amount']);
            } catch (\RuntimeException $e) {
                return $this->purchaseError($e->getMessage());
            }
            $keyboard[] = [['text' => ($plan->name ?: 'تعرفه').' | '.$price.' تومان',
                'callback_data' => "type=CSC|{$params}|l={$plan->id}"]];
        }
        $text = '🌐 کشور: '.$this->escapeHtml($country?->name ?? 'همه کشورها')."\n\n";
        $text .= $list->total() ? 'تعرفه مورد نظر را انتخاب کنید.' : 'تعرفه فعالی موجود نیست؛ لطفاً با پشتیبانی تماس بگیرید.';
        if ($panel) {
            $text .= '\nافزایش قیمت همه کشورها: '.app(OrderPricingService::class)->percent($panel).'٪ (در مبلغ‌ها محاسبه شده)';
        }
        if ($pagination = $this->paginationFooterButton($list, $page, "CSP|{$params}")) {
            $keyboard[] = $pagination;
        }
        $keyboard[] = $this->clientFooterButtons('type=clientService');

        return $this->purchasePage('📦 انتخاب تعرفه', str_replace('\\n', "\n", $text), $keyboard);
    }

    protected function clientSelectCount($type)
    {
        if (! $selection = $this->purchaseSelection($type, true)) {
            return;
        }
        $count = $selection['count'];
        $params = $this->purchaseParams($selection).'|l='.$selection['plan']->id;
        $keyboard = [[
            ['text' => '➖', 'callback_data' => $count > 1 ? 'type=CSC|'.$params.'|n='.($count - 1) : 'ignore'],
            ['text' => (string) $count, 'callback_data' => 'ignore'],
            ['text' => '➕', 'callback_data' => $count < 10 ? 'type=CSC|'.$params.'|n='.($count + 1) : 'ignore'],
        ], [['text' => 'مرحله بعد', 'callback_data' => "type=CLN|{$params}|n={$count}"]],
            $this->clientFooterButtons('type=CSP|'.$this->purchaseParams($selection))];

        return $this->purchasePage('انتخاب تعداد', '🌐 '.$this->escapeHtml($selection['country']?->name ?? 'همه کشورها')."\n📦 ".
            $this->escapeHtml($selection['plan']->name)."\n\nتعداد سرویس را انتخاب کنید (۱ تا ۱۰).", $keyboard);
    }

    protected function clientSelectName($type)
    {
        if (! $selection = $this->purchaseSelection($type, true)) {
            return;
        }
        $detail = $this->user->tel_detail ?? [];
        $detail['order-service-id'] = $selection['service']->id;
        $detail['order-country-id'] = $selection['country']?->id ?? 0;
        $detail['order-pasarguard-id'] = $selection['panel']?->id ?? 0;
        $detail['order-plan-id'] = $selection['plan']->id;
        $detail['order-count'] = $selection['count'];
        $detail['order-extra'] = null;
        $this->user->tel_detail = $detail;
        $this->user->save();
        $this->updatePath('clientFinalStep');

        return $this->purchasePage('نام کانفیگ', 'نام دلخواه را با حروف انگلیسی بفرستید یا انتخاب خودکار را بزنید.', [
            [['text' => 'انتخاب خودکار', 'callback_data' => 'type=clientFinalStep|name=random']],
            $this->clientFooterButtons('type=CSC|'.$this->purchaseParams($selection).'|l='.$selection['plan']->id.'|n='.$selection['count']),
        ]);
    }
}
