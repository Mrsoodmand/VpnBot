<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TelegramBotController;
use App\Models\Countries;
use App\Models\Inbounds;
use App\Models\Orders;
use App\Models\Panels;
use App\Models\Payment;
use App\Models\Plans;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Telegram;
use App\Services\BotCatalogService;
use App\Services\PanelCatalogSyncService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BotAdminCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['loyalty.enabled' => false]);
        foreach (['0001_01_01_000000_create_users_table.php', '2026_05_17_112153_create_plans_table.php',
            '2026_05_17_112208_create_inbounds_table.php', '2026_05_17_112220_create_settings_table.php',
            '2026_05_17_113131_create_panels_table.php', '2026_05_17_114351_create_telegram_data_table.php',
            '2026_05_18_093434_create_extra_bandwidths_table.php', '2026_05_18_155104_create_countries_table.php',
            '2026_05_19_100650_create_services_table.php', '2026_05_20_104904_add_price_column_to_services_table.php',
            '2026_05_20_120513_add_type_column_to_countries_table.php', '2026_05_20_145319_create_pre_orders_table.php',
            '2026_05_20_151321_create_carts_table.php', '2026_05_21_173105_create_payments_table.php',
            '2026_05_22_092352_add_session_to_panels_table.php', '2026_05_22_214954_add_sub_url_to_panels_table.php',
            '2026_05_26_123119_add_setting_to_inbounds_table.php', '2026_05_31_164514_add_column_to_inbounds_table.php',
            '2026_08_22_000000_add_sheba_to_carts_table.php', '2026_05_18_090247_create_seller_inbounds_table.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id');
            $t->integer('panel_id')->nullable();
            $t->string('status')->default('active');
            $t->json('detail')->nullable();
            $t->string('uid')->nullable();
            $t->integer('inbound_id')->default(0);
            $t->string('remark')->nullable();
            $t->integer('plan')->nullable();
            $t->timestamp('expire_at')->nullable();
            $t->timestamps();
        });
        DB::table('users')->insert(['id' => 1, 'tel_id' => '1001', 'is_admin' => 1, 'status' => 1, 'balance' => 1000000]);
        DB::table('services')->insert(['id' => 5, 'name' => 'VIP', 'status' => 1, 'price_per_gb' => 10000]);
        DB::table('countries')->insert(['id' => 10, 'name' => '🇩🇪 آلمان', 'type' => 5, 'status' => 1]);
        DB::table('panels')->insert(['id' => 7, 'name' => 'پنل اصلی', 'panel_type' => 5, 'system_type' => 'pasarguard', 'status' => 1,
            'url' => 'https://panel.test', 'username' => 'admin', 'password' => 'test-password', 'detail' => json_encode(['status' => 1, 'percent' => 70])]);
        DB::table('inbounds')->insert(['id' => 20, 'panel_id' => 7, 'inbound_id' => 100, 'country_id' => 10, 'remark' => 'Germany', 'status' => 1]);
        for ($i = 1; $i <= 12; $i++) {
            DB::table('plans')->insert(['id' => $i, 'name' => 'پلن '.$i, 'type' => 5, 'status' => 1, 'price' => 100000, 'bandwidth' => 20, 'days' => 30, 'discount' => 0]);
        }
    }

    private function controller(bool $admin = true): CatalogBotController
    {
        $user = User::find(1);
        $user->is_admin = $admin;
        $user->save();

        return new CatalogBotController($user);
    }

    private function buttons(CatalogBotController $bot): array
    {
        return array_merge(...json_decode($bot->last()['reply_markup'], true)['inline_keyboard']);
    }

    private function click(CatalogBotController $bot, string $label): void
    {
        foreach ($this->buttons($bot) as $button) {
            if (str_contains($button['text'], $label)) {
                $bot->callback($button['callback_data']);

                return;
            }
        }
        $this->fail('Missing button '.$label.' in '.$bot->last()['text']);
    }

    private function callbackLengths(CatalogBotController $bot): void
    {
        foreach ($this->buttons($bot) as $button) {
            if (isset($button['callback_data'])) {
                $this->assertLessThanOrEqual(64, strlen($button['callback_data']), $button['callback_data']);
            }
        }
    }

    public function test_purchase_skips_service_picker_and_all_countries_pagination_reaches_payment(): void
    {
        $bot = $this->controller(false);
        $bot->callback('type=clientService');
        $this->assertStringContainsString('انتخاب کشور', $bot->last()['text']);
        $this->click($bot, 'همه کشورها');
        $this->assertStringContainsString('170,000', json_encode($this->buttons($bot), JSON_UNESCAPED_UNICODE));
        $this->callbackLengths($bot);
        $this->click($bot, 'بعدی');
        $this->click($bot, 'پلن 11');
        $this->click($bot, '➕');
        $this->click($bot, 'مرحله بعد');
        $this->click($bot, 'انتخاب خودکار');
        $this->assertSame(340000, (int) Payment::first()->price);
        $selection = DB::table('pre_orders')->first();
        $data = json_decode($selection->data, true);
        $this->assertSame(0, $data['country-id']);
        $this->assertSame(7, $data['pasarguard-id']);
        $this->assertSame(11, $data['plan-id']);
        $this->assertSame(2, $data['count']);
        $this->assertSame(1000000, (int) User::find(1)->balance);
        Http::assertNothingSent();
    }

    public function test_single_country_purchase_has_no_all_country_markup(): void
    {
        $bot = $this->controller(false);
        $bot->callback('type=clientService');
        $this->click($bot, 'آلمان');
        $this->assertStringContainsString('100,000', json_encode($this->buttons($bot), JSON_UNESCAPED_UNICODE));
        $this->click($bot, 'پلن 1');
        $this->click($bot, 'مرحله بعد');
        $this->click($bot, 'انتخاب خودکار');
        $this->assertSame(100000, (int) Payment::first()->price);
    }

    public function test_disabled_all_country_panel_and_unrelated_plan_cannot_be_used_by_old_buttons(): void
    {
        $bot = $this->controller(false);
        DB::table('panels')->where('id', 7)->update(['detail' => json_encode(['status' => 0, 'percent' => 70])]);
        $bot->callback('type=clientSelectPlan|s_id=5|co_id=0|p_id=7');
        $this->assertStringContainsString('در دسترس نیست', $bot->last()['text']);
        DB::table('plans')->where('id', 1)->update(['type' => 88]);
        $bot->callback('type=CSC|s=5|c=10|p=0|l=1');
        $this->assertStringContainsString('در دسترس نیست', $bot->last()['text']);
        $this->assertSame(0, Payment::count());
    }

    public function test_sale_switch_is_enforced_at_final_step(): void
    {
        $bot = $this->controller(false);
        $bot->callback('type=CLN|s=5|c=10|p=0|l=1|n=1');
        Setting::create(['key' => 'sell', 'name' => 'فروش', 'value' => 0]);
        $bot->callback('type=clientFinalStep|name=random');
        $this->assertStringContainsString('غیرفعال', $bot->last()['text']);
        $this->assertSame(0, Payment::count());
    }

    public function test_inactive_routes_are_not_offered_and_multiple_panels_are_supported(): void
    {
        DB::table('inbounds')->where('id', 20)->update(['status' => -1]);
        $bot = $this->controller(false);
        $bot->callback('type=clientService');
        $this->assertStringContainsString('آماده نیست', $bot->last()['text']);
        DB::table('inbounds')->where('id', 20)->update(['status' => 1]);
        $panel = Panels::find(7)->replicate();
        $panel->name = 'پنل دوم';
        $panel->save();
        $route = Inbounds::find(20)->replicate();
        $route->panel_id = $panel->id;
        $route->save();
        $bot->callback('type=clientService');
        $all = array_filter($this->buttons($bot), fn ($b) => str_contains($b['text'], 'همه کشورها'));
        $this->assertCount(2, $all);
    }

    public function test_sanaei_country_uses_inbound_mapping_and_legacy_panel_fallback(): void
    {
        DB::table('panels')->where('id', 7)->update(['system_type' => 'sanaie', 'country_id' => 10]);
        DB::table('inbounds')->where('id', 20)->update(['country_id' => null]);
        $bot = $this->controller(false);
        $bot->callback('type=clientService');
        $this->click($bot, 'آلمان');
        $this->assertStringContainsString('انتخاب تعرفه', $bot->last()['text']);
        DB::table('inbounds')->where('id', 20)->update(['country_id' => 0]);
        $bot->callback('type=clientService');
        $this->assertStringContainsString('آماده نیست', $bot->last()['text']);
    }

    public function test_new_records_have_vip_type_and_no_service_type_selector(): void
    {
        $bot = $this->controller();
        foreach (['p' => 'panels', 'c' => 'countries', 'l' => 'plans', 'x' => 'extra_bandwidths'] as $e => $table) {
            $bot->callback('type=adminC|e='.$e.'|a=new');
            $item = DB::table($table)->orderByDesc('id')->first();
            $field = $e === 'p' ? 'panel_type' : 'type';
            $this->assertSame(5, (int) $item->$field);
            $this->assertSame(-1, (int) $item->status);
            $this->assertStringNotContainsString('نوع سرویس', json_encode($this->buttons($bot), JSON_UNESCAPED_UNICODE));
            $this->callbackLengths($bot);
        }
    }

    public function test_country_management_reaches_connections_and_preserves_assignment_on_import(): void
    {
        Http::fake(['*/api/admin/token' => Http::response(['access_token' => 'token']), '*/api/groups*' => Http::response(['total' => 2, 'groups' => [['id' => 100, 'name' => 'Germany new'], ['id' => 200, 'name' => 'France']]])]);
        $bot = $this->controller();
        $bot->callback('type=adminC|e=c|a=show|id=10');
        $this->click($bot, 'مدیریت اتصال');
        $this->click($bot, 'Germany');
        $this->assertStringContainsString('آلمان', $bot->last()['text']);
        $bot->callback('type=adminN|a=sync|p=7|c=10');
        $this->assertSame(10, Inbounds::find(20)->country_id);
        $this->assertSame('1', Inbounds::find(20)->status);
        $this->assertSame('Germany new', Inbounds::find(20)->remark);
        $this->assertSame('-1', Inbounds::where('inbound_id', 200)->first()->status);
        $this->assertSame(['status' => 1, 'percent' => 70], Panels::find(7)->detail);
    }

    public function test_failed_connection_does_not_erase_surcharge_or_report_success(): void
    {
        Http::fake(['*' => Http::response(['detail' => 'Unauthorized'], 401)]);
        $bot = $this->controller();
        $bot->callback('type=adminConnectPanel|id=7');
        $this->assertStringContainsString('برقرار نشد', $bot->last()['text']);
        $this->assertSame(['status' => 1, 'percent' => 70], Panels::find(7)->detail);
    }

    public function test_successful_connection_preserves_all_country_settings(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'token'])]);
        $bot = $this->controller();
        $bot->callback('type=adminConnectPanel|id=7');
        $this->assertStringContainsString('برقرار است', $bot->last()['text']);
        $this->assertSame(['status' => 1, 'percent' => 70], Panels::find(7)->detail);
    }

    public function test_failed_or_malformed_import_is_atomic(): void
    {
        Http::fake(['*/api/admin/token' => Http::response(['access_token' => 'token']), '*/api/groups*' => Http::response(['total' => 2, 'groups' => [['id' => 100, 'name' => 'changed'], ['id' => 0, 'name' => 'bad']]])]);
        $bot = $this->controller();
        $bot->callback('type=adminGetInbounds|id=7');
        $this->assertStringContainsString('معتبر نیست', $bot->last()['text']);
        $this->assertSame('Germany', Inbounds::find(20)->remark);
        $this->assertSame(1, Inbounds::count());
    }

    public function test_country_reassignment_requires_confirmation_and_updates_the_same_list(): void
    {
        DB::table('countries')->insert(['id' => 11, 'name' => 'فرانسه', 'type' => 5, 'status' => 1]);
        $bot = $this->controller();
        $bot->callback('type=adminN|a=set|id=20|c=11');
        $this->assertSame(10, Inbounds::find(20)->country_id);
        $this->click($bot, 'تأیید انتقال');
        $this->assertSame(11, Inbounds::find(20)->country_id);
        $this->assertStringContainsString('فرانسه', $bot->last()['text']);
    }

    public function test_admin_routes_and_pending_text_saves_reject_non_admins(): void
    {
        $bot = $this->controller(false);
        foreach (['type=adminC|e=c|a=new', 'type=adminS|k=sell|a=save|v=0', 'type=adminN|a=toggle|id=20|v=-1', 'type=AdminPGGDA|id=20'] as $data) {
            $bot->callback($data);
        }
        $user = User::find(1);
        $user->path = 'adminCatalogSave';
        $user->tel_detail = ['catalog_edit' => ['e' => 'p', 'id' => 7, 'k' => 'password']];
        $user->save();
        $bot->input('stolen');
        $this->assertSame(1, Countries::count());
        $this->assertSame(0, Setting::count());
        $this->assertSame('test-password', Panels::find(7)->password);
        $this->assertSame('1', Inbounds::find(20)->status);
    }

    public function test_prices_and_percentages_reject_invalid_input_and_keep_the_editor_open(): void
    {
        $bot = $this->controller();
        $bot->callback('type=adminC|e=l|a=edit|id=1|k=price');
        $bot->input('-100');
        $this->assertSame(100000, Plans::find(1)->price);
        $this->assertStringContainsString('معتبر نیست', $bot->last()['text']);
        $bot->input('250000');
        $this->assertSame(250000, Plans::find(1)->price);
        $bot->callback('type=adminN|a=percent|p=7');
        $bot->input('not-a-number');
        $this->assertSame(70, Panels::find(7)->detail['percent']);
        $bot->input('25');
        $this->assertSame(25, Panels::find(7)->detail['percent']);
        $this->assertSame(1, Panels::find(7)->detail['status']);
    }

    public function test_card_and_settings_fields_are_allowlisted_and_escape_html(): void
    {
        $bot = $this->controller();
        $bot->callback('type=adminC|e=p|a=save|id=7|k=admin_id|v=999');
        $this->assertNull(Panels::find(7)->admin_id);
        $bot->callback('type=adminC|e=c|a=edit|id=10|k=name');
        $bot->input('DE <test> & country');
        $this->assertStringContainsString('&lt;test&gt; &amp;', $bot->last()['text']);
        $bot->callback('type=adminC|e=p|a=show|id=7');
        $this->assertStringNotContainsString('test-password', $bot->last()['text']);
        $bot->callback('type=adminS|k=unlisted|a=save|v=1');
        $this->assertFalse(Setting::where('key', 'unlisted')->exists());
    }

    public function test_group_page_never_deletes_a_plan_with_the_same_id(): void
    {
        DB::table('plans')->insert(['id' => 20, 'name' => 'protected', 'status' => 1]);
        $bot = $this->controller();
        $bot->callback('type=AdminPGGDA|id=20');
        $this->assertStringNotContainsString('adminPlanDelete', json_encode($this->buttons($bot)));
        $bot->callback('type=adminPlanDeleteSubmit|id=20');
        $this->assertNotNull(Plans::find(20));
        $this->assertSame('1', Plans::find(20)->status);
    }

    public function test_settings_include_all_supported_groups_and_changes_use_correct_types(): void
    {
        $bot = $this->controller();
        $bot->callback('type=adminS');
        $this->callbackLengths($bot);
        foreach (['sales', 'payment', 'access', 'channels', 'texts', 'notices', 'legacy'] as $group) {
            $bot->callback('type=adminS|g='.$group);
            $this->callbackLengths($bot);
        }
        $bot->callback('type=adminS|k=cart_be_cart_id');
        $bot->input('https://t.me/receipts_group');
        $this->assertSame('@receipts_group', Setting::where('key', 'cart_be_cart_id')->value('value'));
        $bot->callback('type=adminS|k=support_id');
        $bot->input('@support_name');
        $this->assertSame('support_name', Setting::where('key', 'support_id')->value('value'));
        $bot->callback('type=adminS|k=charge_amount');
        $bot->input('200000,100000,200000');
        $this->assertSame('100000,200000', Setting::where('key', 'charge_amount')->value('value'));
    }

    public function test_notification_settings_validate_ranges_and_accept_persian_digits(): void
    {
        $bot = $this->controller();
        $bot->callback('type=adminS|g=notices');
        $this->assertStringContainsString('۲۴', strtr($bot->last()['text'], ['2'=>'۲','4'=>'۴']));
        $this->callbackLengths($bot);
        foreach (['notify_hours'=>['۴۸','48','0'], 'notify_gb'=>['۰٫۵','0.5','-1'], 'notify_interval'=>['۱۵','15','1']] as $key=>[$input, $expected, $invalid]) {
            $bot->callback('type=adminS|k='.$key);
            $bot->input($input);
            $this->assertSame($expected, Setting::where('key', $key)->value('value'));
            $bot->callback('type=adminS|k='.$key);
            $bot->input($invalid);
            $this->assertSame($expected, Setting::where('key', $key)->value('value'));
            $this->assertStringContainsString('معتبر نیست', $bot->last()['text']);
        }
        $bot->callback('type=adminS|a=save|k=notify_renew|v=0');
        $this->assertSame('0', Setting::where('key', 'notify_renew')->value('value'));
    }

    public function test_unknown_and_malformed_buttons_have_a_visible_response(): void
    {
        $bot = $this->controller();
        $bot->callback('type=missing-route|bad');
        $this->assertStringContainsString('قدیمی یا نامعتبر', $bot->last()['text']);
        $bot->callback('not-valid');
        $this->assertNotEmpty($bot->answers);
    }

    public function test_paginated_sync_preserves_settings_and_disables_only_confirmed_missing_or_disabled_routes(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/token')) {
                return Http::response(['access_token' => 'token']);
            }
            if ((int) $request['offset'] > 0) {
                return Http::response(['groups' => [['id' => 200, 'name' => 'France', 'is_disabled' => true]], 'total' => 2]);
            }

            return Http::response(['groups' => [['id' => 100, 'name' => 'Germany', 'inbound_tags' => ['vless']]], 'total' => 2]);
        });
        DB::table('inbounds')->insert(['id' => 21, 'panel_id' => 7, 'inbound_id' => 300, 'remark' => 'deleted', 'country_id' => 10, 'status' => 1]);
        $bot = $this->controller();
        $bot->callback('type=adminN|a=sync|p=7');
        $this->assertSame(3, Inbounds::count());
        $this->assertSame('1', Inbounds::find(20)->status);
        $this->assertSame('-1', Inbounds::find(21)->status);
        $missing = Inbounds::where('inbound_id', 200)->first();
        $bot->callback('type=adminN|a=toggle|id='.$missing->id.'|v=1');
        $this->assertSame('-1', $missing->fresh()->status);
        $this->assertSame(['status' => 1, 'percent' => 70], Panels::find(7)->detail);
    }

    public function test_sync_fetches_every_page_and_reconciles_missing_groups_only_on_this_panel(): void
    {
        $groups = array_map(fn ($id) => ['id' => $id, 'name' => 'Group '.$id, 'inbound_tags' => ['vless'], 'is_disabled' => false], range(1, 205));
        Http::fake(function ($request) use ($groups) {
            if (str_contains($request->url(), '/token')) return Http::response(['access_token' => 'token']);
            $this->assertSame(100, (int) $request['limit']);
            return Http::response(['groups' => array_slice($groups, (int) $request['offset'], 100), 'total' => count($groups)]);
        });
        DB::table('inbounds')->insert([
            ['id' => 21, 'panel_id' => 7, 'inbound_id' => 900, 'remark' => 'removed', 'status' => 1],
            ['id' => 22, 'panel_id' => 8, 'inbound_id' => 901, 'remark' => 'other panel', 'status' => 1],
        ]);
        $this->assertSame(205, app(PanelCatalogSyncService::class)->sync(Panels::find(7)));
        Http::assertSentCount(4); // Login and three catalog pages, never requests per order.
        $this->assertSame(205, Inbounds::where('panel_id', 7)->catalogPresent()->count());
        $this->assertTrue(Inbounds::find(21)->isCatalogMissing());
        $this->assertSame('1', Inbounds::find(22)->status);
        $this->assertSame('1', Inbounds::find(20)->status);
        $this->assertSame(10, Inbounds::find(20)->country_id);
        $this->assertSame('-1', Inbounds::where('panel_id', 7)->where('inbound_id', 205)->first()->status);
    }

    public function test_bad_or_incomplete_pages_do_not_archive_or_modify_any_routes(): void
    {
        $cases = [
            [['groups' => [['id' => 100, 'name' => 'new']]]], // No total: completeness unknown.
            [['groups' => [], 'total' => 'bad']],
            [['groups' => [], 'total' => -1]],
            [['groups' => [['id' => 100, 'name' => 'new']], 'total' => 2], ['groups' => [], 'total' => 2]],
            [['groups' => [['id' => 100, 'name' => 'new']], 'total' => 2], ['groups' => [['id' => 200, 'name' => 'next']], 'total' => 3]],
            [['groups' => [['id' => 100, 'name' => 'new']], 'total' => 2], ['groups' => [['id' => 100, 'name' => 'duplicate']], 'total' => 2]],
            [['groups' => [['id' => 100, 'name' => 'new']], 'total' => 2], ['status' => false, 'message' => 'timeout']],
            [['groups' => [['id' => 100, 'name' => 'new'], ['id' => 200, 'name' => 'extra']], 'total' => 1]],
        ];
        $before = Inbounds::find(20)->getAttributes();
        Cache::put('pasarguard-token-7', 'token');
        foreach ($cases as $pages) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            $sequence = Http::sequence();
            foreach ($pages as $response) $sequence->push($response);
            Http::fake(['*/api/groups*' => $sequence]);
            try {
                app(PanelCatalogSyncService::class)->sync(Panels::find(7));
                $this->fail('Partial catalog must fail');
            } catch (\RuntimeException $e) {
                $this->assertMatchesRegularExpression('/حفظ شد|تغییر نکرد/u', $e->getMessage());
            }
            $this->assertSame($before, Inbounds::find(20)->getAttributes());
            $this->assertSame(1, Inbounds::count());
            $lock = Cache::lock('panel:catalog:sync:7', 300);
            $this->assertTrue($lock->get());
            $lock->release();
        }
    }

    public function test_empty_catalog_keeps_old_orders_and_country_references_but_stops_new_sales(): void
    {
        DB::table('orders')->insert(['id' => 50, 'user_id' => 1, 'panel_id' => 7, 'inbound_id' => 20, 'uid' => '88',
            'remark' => 'old customer', 'status' => 'active', 'detail' => json_encode(['country' => 'آلمان']), 'expire_at' => '2026-12-01 00:00:00']);
        $before = Orders::find(50)->getAttributes();
        Http::fake(['*/api/admin/token' => Http::response(['access_token' => 'token']), '*/api/groups*' => Http::response(['groups' => [], 'total' => 0])]);
        $this->assertSame(0, app(PanelCatalogSyncService::class)->sync(Panels::find(7)));
        $this->assertSame($before, Orders::find(50)->getAttributes());
        $this->assertSame(10, Inbounds::find(20)->country_id);
        $this->assertTrue(Inbounds::find(20)->isCatalogMissing());
        $this->assertFalse(app(BotCatalogService::class)->routes(5, 10)->exists());
        $this->assertCount(0, app(BotCatalogService::class)->allCountryPanels(5));
        $bot = $this->controller();
        foreach (['toggle|v=1', 'set|c=11', 'detach|ok=1'] as $action) {
            $bot->callback('type=adminN|id=20|a='.$action);
            $this->assertStringContainsString('آرشیوشده', $bot->last()['text']);
            $this->assertStringContainsString('تغییر کشور', $bot->last()['text']);
        }
        $this->assertSame(10, Inbounds::find(20)->country_id);
        $this->assertSame('-1', Inbounds::find(20)->status);
    }

    public function test_live_group_list_sorts_active_first_across_pages_and_separates_archive(): void
    {
        foreach (range(21, 44) as $id) {
            DB::table('inbounds')->insert(['id' => $id, 'panel_id' => 7, 'inbound_id' => $id * 10, 'country_id' => 10,
                'remark' => 'group '.$id, 'status' => $id <= 31 ? 1 : -1]);
        }
        DB::table('inbounds')->insert(['id' => 99, 'panel_id' => 7, 'inbound_id' => 999, 'country_id' => 10,
            'remark' => 'REMOVED', 'status' => -1, 'setting' => json_encode(['_bot_catalog_missing' => true])]);
        $bot = $this->controller();
        $ids = [];
        foreach (range(1, 3) as $page) {
            $bot->callback('type=adminN|p=7|page='.$page);
            foreach ($this->buttons($bot) as $button) {
                $this->assertStringNotContainsString('REMOVED', $button['text']);
                if (preg_match('/^type=adminN\|a=show\|id=(\d+)/', $button['callback_data'] ?? '', $match)) $ids[] = (int) $match[1];
            }
            $this->callbackLengths($bot);
        }
        $this->assertSame(array_merge(range(31, 20), range(44, 32)), $ids);
        $this->click($bot, 'آرشیو');
        $this->assertStringContainsString('REMOVED', json_encode($this->buttons($bot)));
        $this->click($bot, 'REMOVED');
        $this->assertStringNotContainsString('a=toggle', json_encode($this->buttons($bot)));
        $bot->callback('type=adminN|a=pick|c=10');
        $this->assertStringNotContainsString('REMOVED', json_encode($this->buttons($bot)));
        $this->assertStringNotContainsString('a=arch', json_encode($this->buttons($bot)));
    }

    public function test_returning_group_leaves_archive_without_automatically_starting_sales(): void
    {
        $route = Inbounds::find(20);
        $route->status = -1;
        $route->setting = json_encode(['_bot_catalog_missing' => true]);
        $route->save();
        Http::fake(['*/api/admin/token' => Http::response(['access_token' => 'token']),
            '*/api/groups*' => Http::response(['total' => 1, 'groups' => [['id' => 100, 'name' => 'Returned', 'inbound_tags' => ['vless']]]])]);
        app(PanelCatalogSyncService::class)->sync(Panels::find(7));
        $this->assertFalse($route->fresh()->isCatalogMissing());
        $this->assertSame(10, $route->fresh()->country_id);
        $this->assertSame('-1', $route->fresh()->status);
        $this->assertSame(1, Inbounds::count());
    }

    public function test_overlapping_sync_is_stopped_before_sending_panel_requests(): void
    {
        $lock = Cache::lock('panel:catalog:sync:7', 300);
        $lock->get();
        try {
            app(PanelCatalogSyncService::class)->sync(Panels::find(7));
            $this->fail('Overlapping sync must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('در حال انجام', $e->getMessage());
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
        $this->assertSame('1', Inbounds::find(20)->status);
    }

    public function test_receipt_details_offer_retry_for_stuck_delivery_and_no_approval_for_payment_drafts(): void
    {
        $pending = Payment::create(['user_id' => 1, 'type' => 4, 'price' => 100000, 'method' => 'cart-be-cart', 'status' => 0, 'detail' => []]);
        $bot = $this->controller();
        $bot->callback('type=adminReceipt|id='.$pending->id);
        $this->assertStringNotContainsString('adminConfirm', json_encode($this->buttons($bot)));
        $pending->detail = ['value' => 'Receipt reference', 'receipt_submission_state' => 'submitted'];
        $pending->save();
        $bot->callback('type=adminReceipt|id='.$pending->id);
        $this->assertStringContainsString('adminConfirm', json_encode($this->buttons($bot)));
        $stuck = Payment::create(['user_id' => 1, 'type' => 1, 'price' => 100000, 'method' => 'wallet', 'status' => 1, 'detail' => ['fulfillment' => ['status' => 'processing']]]);
        $bot->callback('type=adminReceipts|f=failed');
        $this->click($bot, '#'.$stuck->id);
        $this->assertStringContainsString('adminRetryPayment', json_encode($this->buttons($bot)));
        $stuck->detail = ['fulfillment' => ['status' => 'completed']];
        $stuck->save();
        $bot->callback('type=adminReceipt|id='.$stuck->id);
        $this->assertStringNotContainsString('adminRetryPayment', json_encode($this->buttons($bot)));
        $this->assertSame(1000000, (int) User::find(1)->balance);
    }

    public function test_manual_expiry_change_uses_selected_order_not_previous_volume_order_and_preserves_other_limits(): void
    {
        DB::table('orders')->insert(['id' => 1, 'user_id' => 1, 'panel_id' => 7, 'uid' => '99', 'remark' => 'chosen', 'expire_at' => '2026-12-01 00:00:00']);
        DB::table('orders')->insert(['id' => 2, 'user_id' => 1, 'panel_id' => 7, 'uid' => '100', 'remark' => 'other', 'expire_at' => '2026-12-01 00:00:00']);
        $user = User::find(1);
        $user->path = 'adminOrderChangeTimeSubmit';
        $user->tel_detail = ['order-time' => 1, 'order-bw' => 2];
        $user->save();
        Http::fake(['*/api/admin/token' => Http::response(['access_token' => 'token']), '*/api/user/by-id/99' => Http::response([
            'id' => 99, 'status' => 'active', 'data_limit' => 21474836480, 'used_traffic' => 1073741824, 'expire' => '2026-12-01T00:00:00Z'])]);
        $bot = $this->controller();
        $bot->input('10');
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/99') && array_keys($r->data()) === ['expire'] && Carbon::parse($r['expire'])->equalTo('2026-12-11T00:00:00Z'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/100'));
        $this->assertSame('start', User::find(1)->path);
        $this->assertTrue(Orders::find(1)->expire_at->equalTo('2026-12-11T00:00:00Z'));
        $count = count(Http::recorded());
        $bot->input('10');
        $this->assertCount($count, Http::recorded());
    }

    public function test_manual_adjustment_rejects_text_instead_of_coercing_to_zero(): void
    {
        $user = User::find(1);
        $user->path = 'adminOrderChangeTimeSubmit';
        $user->tel_detail = ['order-time' => 1];
        $user->save();
        $bot = $this->controller();
        $bot->input('wrong');
        $this->assertStringContainsString('عدد صحیح', $bot->last()['text']);
        Http::assertNothingSent();
    }

    public function test_manual_wallet_adjustment_records_once_and_leaves_entry_mode(): void
    {
        DB::table('users')->insert(['id' => 2, 'tel_id' => '2002', 'status' => 1, 'balance' => 100]);
        $bot = $this->controller();
        $bot->callback('type=adminUserBalanceAction|id=2|value=increment');
        $bot->input('500');
        $this->assertSame(600, (int) User::find(2)->balance);
        $this->assertSame(1, Payment::where('method', 'admin_credit')->count());
        $this->assertSame('start', User::find(1)->path);
        $bot->input('500');
        $this->assertSame(600, (int) User::find(2)->balance);
        $this->assertSame(1, Payment::count());
        $bot->callback('type=adminUserBalanceAction|id=2|value=decrement');
        $bot->input('1000');
        $this->assertSame(600, (int) User::find(2)->balance);
        $this->assertSame(1, Payment::count());
        $bot->input('200');
        $this->assertSame(400, (int) User::find(2)->balance);
        $this->assertSame(2, Payment::count());
        $bot->callback('type=adminUserBalanceAction|id=1|value=increment');
        $bot->input('500');
        $this->assertSame(1000500, (int) User::find(1)->balance);
        $this->assertSame('start', User::find(1)->path);
        Http::assertNothingSent();
    }

    public function test_invalid_roles_self_disabling_and_cancelled_edit_cannot_mutate_data(): void
    {
        DB::table('users')->insert(['id' => 2, 'tel_id' => '2002', 'status' => 1]);
        $bot = $this->controller();
        $bot->callback('type=adminUserIsAdmin|id=2|value=-1');
        $this->assertSame('0', User::find(2)->is_admin);
        $bot->callback('type=adminToggleUserStatus|id=1');
        $this->assertSame(1, (int) User::find(1)->status);
        $bot->callback('type=adminC|e=l|a=edit|id=1|k=price');
        $bot->callback('type=adminC|e=l|a=show|id=1');
        $bot->input('999');
        $this->assertSame(100000, (int) Plans::find(1)->price);
        Http::assertNothingSent();
    }

    public function test_legacy_seller_connections_do_not_require_missing_seller_record_and_preserve_page(): void
    {
        DB::table('users')->insert(['id' => 2, 'tel_id' => '2002', 'is_seller' => 1, 'status' => 1]);
        DB::table('seller_inbounds')->insert(['user_id' => 2, 'inbound_id' => 20, 'status' => -1]);
        $bot = $this->controller();
        $bot->callback('type=adminUserSellerInbounds|id=2');
        $this->assertStringContainsString('محدودیت فروش ایجاد نمی‌کند', $bot->last()['text']);
        $this->assertStringContainsString('❌', json_encode($this->buttons($bot), JSON_UNESCAPED_UNICODE));
        $this->click($bot, 'Germany');
        $this->assertSame(1, (int) DB::table('seller_inbounds')->value('status'));
        $bot->callback('type=adminSI|id=2|i=20|v=1|page=1');
        $this->assertSame(1, DB::table('seller_inbounds')->count());
        $this->click($bot, 'Germany');
        $this->assertSame(-1, (int) DB::table('seller_inbounds')->value('status'));
    }

    public function test_oversized_purchase_is_rejected_before_payment_is_created(): void
    {
        DB::table('plans')->where('id', 1)->update(['price' => 2000000000]);
        $bot = $this->controller(false);
        $bot->callback('type=CLN|s=5|c=0|p=7|l=1|n=2');
        $bot->callback('type=clientFinalStep|name=random');
        $this->assertSame(0, Payment::count());
        $this->assertStringContainsString('محدوده مجاز', $bot->last()['text']);
    }

    public function test_start_cancels_wallet_entry_and_services_command_opens_vip_purchase(): void
    {
        DB::table('users')->insert(['id' => 2, 'tel_id' => '2002', 'status' => 1, 'balance' => 100]);
        $bot = $this->controller();
        $bot->callback('type=adminUserBalanceAction|id=2|value=increment');
        Setting::create(['key' => 'channel-join', 'name' => 'عضویت', 'value' => 1]);
        $bot->input('/start');
        $this->assertStringContainsString('مدیریت ربات', $bot->last()['text']);
        $bot->input('500');
        $this->assertSame(100, (int) User::find(2)->balance);
        $this->assertSame(0, Payment::count());
        Setting::where('key', 'channel-join')->update(['value' => 0]);
        $bot->input('/services');
        $this->assertStringContainsString('انتخاب کشور', $bot->last()['text']);
    }

    public function test_sale_switch_enables_vip_without_a_second_service_type_setting(): void
    {
        DB::table('services')->where('id', 5)->update(['status' => -1]);
        $bot = $this->controller();
        $bot->callback('type=adminS|a=save|k=sell|v=1');
        $this->assertSame(1, (int) Service::find(5)->status);
        $bot->callback('type=clientService');
        $this->click($bot, 'همه کشورها');
        $this->assertStringContainsString('انتخاب تعرفه', $bot->last()['text']);
    }

    public function test_wallet_topup_handles_no_active_card_and_rejects_negative_or_oversized_amounts(): void
    {
        Setting::create(['key' => 'cart_be_cart', 'name' => 'پرداخت', 'value' => 1]);
        Setting::create(['key' => 'cart_be_cart_random', 'name' => 'تصادفی', 'value' => 1]);
        $bot = $this->controller(false);
        $bot->callback('type=addFundStepTwo|key=Cart|amount=100000');
        $this->assertStringContainsString('کارت فعالی', $bot->last()['text']);
        foreach (['-1000', '99999999999', 'bad'] as $amount) {
            $bot->callback('type=addFundStepTwo|key=Cart|amount='.$amount);
        }
        $this->assertSame(0, Payment::count());
        Http::assertNothingSent();
    }

    public function test_large_ids_still_fit_telegram_callback_limit(): void
    {
        DB::table('panels')->where('id', 7)->update(['id' => 1234567890]);
        DB::table('inbounds')->where('id', 20)->update(['panel_id' => 1234567890]);
        $bot = $this->controller();
        $bot->callback('type=adminC|e=p|a=show|id=1234567890');
        $this->callbackLengths($bot);
        $this->click($bot, 'سیستم پنل');
        $this->callbackLengths($bot);
        $bot->callback('type=clientService');
        $this->click($bot, 'همه کشورها');
        $this->callbackLengths($bot);
    }
}

class CatalogBotController extends TelegramBotController
{
    public array $messages = [];

    public array $answers = [];

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->isAdmin = (bool) $user->is_admin;
        $this->isSeller = false;
        $this->chatId = $user->tel_id;
        $this->actorId = $user->tel_id;
        $this->method = 'toUser';
        $this->messageId = 1;
        $this->callbackId = 'test';
        $this->telData = [];
        $sdk = \Mockery::mock(Telegram::class);
        foreach (['sendMessage', 'editMessage'] as $method) {
            $sdk->shouldReceive($method)->andReturnUsing(function ($data) {
                $this->messages[] = $data;

                return ['ok' => false];
            });
        }
        $sdk->shouldReceive('answerCallback')->andReturnUsing(function ($data) {
            $this->answers[] = $data;

            return ['ok' => true];
        });
        $sdk->shouldReceive('deleteMessage')->andReturn(['ok' => true]);
        $this->telegramSdk = $sdk;
    }

    public function callback(string $data)
    {
        $this->user->refresh();
        $this->isAdmin = (bool) $this->user->is_admin;
        $this->callbackData = $data;
        $this->type = 'callback_query';

        return $this->index();
    }

    public function input(string $text)
    {
        $this->user->refresh();
        $this->isAdmin = (bool) $this->user->is_admin;
        $this->text = PersianNumToEn($text);
        $this->type = 'text';

        return $this->index();
    }

    public function last(): array
    {
        return end($this->messages);
    }
}
