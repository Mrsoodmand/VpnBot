<?php

namespace App\Services;

use App\Models\Countries;
use App\Models\Inbounds;
use App\Models\Panels;
use App\Models\Plans;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/** One VIP catalog for the bot; existing IDs remain stable for historical orders. */
class BotCatalogService
{
    public function vip(): Service
    {
        $services = Service::orderBy('id')->get();
        $vip = $services->filter(fn ($s) => preg_match('/\bvip\b/i', (string) $s->name));
        if ($vip->count() === 1) {
            return $vip->first();
        }
        if ($services->count() === 1) {
            return $services->first();
        }
        throw new RuntimeException('سرویس VIP مشخص نیست؛ مدیر باید نام سرویس اصلی را در اطلاعات فعلی بررسی کند.');
    }

    public function enabled(string $key): bool
    {
        $value = Setting::where('key', $key)->value('value');

        return $value === null || (int) $value === 1;
    }

    public function routes(int $serviceId, ?int $countryId = null): Builder
    {
        $query = Inbounds::where('status', 1)->whereHas('panel', fn ($q) => $q
            ->where('status', 1)->where('panel_type', $serviceId)->whereIn('system_type', ['pasarguard', 'sanaie']));
        if ($countryId !== null) {
            $this->forCountry($query, $countryId);
        }

        return $query;
    }

    public function forCountry(Builder $query, int $countryId): Builder
    {
        return $query->where(fn ($q) => $q->where('country_id', $countryId)
            ->orWhere(fn ($q) => $q->whereNull('country_id')->whereHas('panel', fn ($p) => $p
                ->where('system_type', 'sanaie')->where('country_id', $countryId))));
    }

    public function allCountryPanels(int $serviceId)
    {
        return Panels::where('panel_type', $serviceId)->where('system_type', 'pasarguard')
            ->where('status', 1)->orderBy('id')->get()->filter(fn ($p) => (int) ($p->detail['status'] ?? 0) === 1
                && Inbounds::where('panel_id', $p->id)->where('status', 1)->exists());
    }

    public function selection(array $input, bool $withPlan = false): array
    {
        $service = $this->vip();
        if ((int) $service->status !== 1 || ! $this->enabled('sell')) {
            throw new RuntimeException('فروش سرویس در حال حاضر غیرفعال است.');
        }
        if (isset($input['s_id']) && (int) $input['s_id'] !== (int) $service->id) {
            throw new RuntimeException('این گزینه قدیمی است؛ خرید را از منوی اصلی شروع کنید.');
        }
        $countryId = (int) ($input['co_id'] ?? 0);
        $panelId = (int) ($input['p_id'] ?? 0);
        $panel = null;
        $country = null;
        if ($panelId > 0) {
            $panel = $this->allCountryPanels($service->id)->firstWhere('id', $panelId);
            if (! $panel || $countryId !== 0) {
                throw new RuntimeException('فروش همه کشورها برای این پنل در دسترس نیست؛ کشور را دوباره انتخاب کنید.');
            }
        } else {
            $country = Countries::where('type', $service->id)->where('status', 1)->find($countryId);
            if (! $country || ! $this->routes($service->id, $countryId)->exists()) {
                throw new RuntimeException('این کشور اتصال فعال برای فروش ندارد؛ کشور دیگری انتخاب کنید.');
            }
        }
        $plan = null;
        if ($withPlan) {
            $plan = Plans::where('type', $service->id)->where('status', 1)->whereNull('deleted_at')->find($input['pl_id'] ?? 0);
            if (! $plan || ! is_numeric($plan->price) || $plan->price < 0) {
                throw new RuntimeException('تعرفه انتخاب‌شده در دسترس نیست؛ تعرفه را دوباره انتخاب کنید.');
            }
        }
        $count = filter_var($input['cu'] ?? 1, FILTER_VALIDATE_INT);
        if ($count === false || $count < 1 || $count > 10) {
            throw new RuntimeException('تعداد خرید باید بین ۱ تا ۱۰ باشد.');
        }

        return compact('service', 'country', 'panel', 'plan', 'count');
    }
}
