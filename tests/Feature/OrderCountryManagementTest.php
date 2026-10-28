<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Concerns\ManagesOrderCountry;
use App\Lib\PasarGuard;
use App\Models\Orders;
use App\Models\Panels;
use App\Services\OrderCountryResolver;
use App\Services\OrderCountryService;
use App\Services\OrderLifecycleService;
use App\Services\OrderPricingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderCountryManagementTest extends TestCase
{
    private CountryHarness $countries;

    private CountryPanel $panel;

    private array $actor = ['source' => 'telegram', 'id' => '1', 'name' => 'admin'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        foreach (['0001_01_01_000000_create_users_table.php', '2026_05_17_112220_create_settings_table.php', '2026_05_17_113131_create_panels_table.php',
            '2026_05_18_155104_create_countries_table.php', '2026_05_17_112208_create_inbounds_table.php',
            '2026_05_31_164514_add_column_to_inbounds_table.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        Schema::create('pre_orders', function (Blueprint $t) {
            $t->id();
            $t->json('data')->nullable();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id')->default(1);
            $t->integer('panel_id');
            $t->integer('inbound_id')->default(10);
            $t->string('system_type')->default('pasarguard');
            $t->string('uid');
            $t->string('plan')->default('20GB');
            $t->string('status')->default('active');
            $t->json('detail');
            $t->dateTime('expire_at');
            $t->timestamps();
        });
        DB::table('panels')->insert(['id' => 1, 'name' => 'panel', 'system_type' => 'pasarguard', 'detail' => json_encode(['status' => 1])]);
        DB::table('countries')->insert([['id' => 1, 'name' => '🇩🇪 آلمان', 'status' => 1], ['id' => 2, 'name' => '🇬🇧 انگلیس', 'status' => 1]]);
        DB::table('inbounds')->insert([
            ['id' => 10, 'panel_id' => 1, 'inbound_id' => 100, 'country_id' => 1, 'status' => 1],
            ['id' => 11, 'panel_id' => 1, 'inbound_id' => 200, 'country_id' => 2, 'status' => 1],
        ]);
        $this->panel = new CountryPanel;
        $this->countries = new CountryHarness($this->panel);
        $this->app->instance(OrderCountryService::class, $this->countries);
    }

    private function order(array $detail = []): Orders
    {
        return Orders::create(['panel_id' => 1, 'uid' => '7', 'expire_at' => now()->addDays(5),
            'detail' => array_replace(['country' => '🇩🇪 آلمان', 'price' => 190000, 'code' => 'vless://old#Germany'], $detail)])->fresh();
    }

    public function test_existing_provider_snapshot_repairs_country_without_network_or_per_order_catalog_queries(): void
    {
        $a = $this->order();
        $b = $this->order();
        $before = $a->expire_at->timestamp;
        DB::enableQueryLog();
        foreach ([$a, $b] as $order) {
            app(OrderLifecycleService::class)->applyPasarguardSnapshot($order, ['id' => 7, 'status' => 'active', 'group_ids' => [200]]);
        }
        $reads = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], '"inbounds" as "i"'));
        DB::disableQueryLog();
        $a->refresh();
        $this->assertCount(1, $reads);
        Http::assertNothingSent();
        $this->assertSame('🇬🇧 انگلیس', $a->detail['current_country']['label']);
        $this->assertSame('🇩🇪 آلمان', $a->detail['country']); // Original purchase remains available.
        $this->assertSame(190000, $a->detail['price']);
        $this->assertSame($before, $a->expire_at->timestamp);
        $this->assertArrayNotHasKey('code', $a->detail);
        $this->assertSame('🇬🇧 انگلیس', app(OrderCountryResolver::class)->resolve([$a])[$a->id]);
    }

    public function test_admin_changes_groups_only_and_repeated_confirmation_does_not_write_again(): void
    {
        $o = $this->order();
        $before = $o->getAttributes();
        $preview = $this->countries->preview($o, 2, $this->actor);
        $saved = $this->countries->confirm($preview['token'], $this->actor);
        $this->countries->confirm($preview['token'], $this->actor);
        $this->assertSame([['group_ids' => [200]]], $this->panel->updates);
        $this->assertSame($before['expire_at'], $saved->getAttributes()['expire_at']);
        $this->assertSame($before['plan'], $saved->plan);
        $this->assertSame('7', $saved->uid);
        $this->assertSame($this->actor, $saved->detail['country_history'][0]['actor']);
        $this->assertCount(1, $saved->fresh()->detail['country_history']);
    }

    public function test_timed_out_write_is_reconciled_by_reading_back_instead_of_replaying(): void
    {
        $o = $this->order();
        $this->panel->mode = 'timeout_applied';
        $preview = $this->countries->preview($o, 2, $this->actor);
        $this->assertSame('🇬🇧 انگلیس', $this->countries->confirm($preview['token'], $this->actor)->detail['current_country']['label']);
        $this->assertCount(1, $this->panel->updates);
        $this->assertSame(3, $this->panel->reads);
    }

    public function test_admin_can_reassign_an_order_left_without_groups_after_deletion(): void
    {
        $o = $this->order();
        $before = $o->getAttributes();
        DB::table('inbounds')->where('id', 10)->update(['status' => -1]);
        $this->panel->groups = [];
        $preview = $this->countries->preview($o, 2, $this->actor);
        $this->assertSame('بدون گروه در پاسارگارد', $preview['from']);
        $saved = $this->countries->confirm($preview['token'], $this->actor);
        $this->countries->confirm($preview['token'], $this->actor);
        $this->assertSame([['group_ids' => [200]]], $this->panel->updates);
        $this->assertSame('🇬🇧 انگلیس', $saved->detail['current_country']['label']);
        $this->assertSame($before['expire_at'], $saved->getAttributes()['expire_at']);
        $this->assertSame($before['plan'], $saved->plan);
        $this->assertSame($before['uid'], $saved->uid);
        $this->assertSame('🇩🇪 آلمان', $saved->detail['country']);
        $this->assertSame(10, $saved->inbound_id);
    }

    public function test_invalid_group_snapshot_cannot_be_treated_as_an_empty_group(): void
    {
        $o = $this->order();
        $this->panel->groups = ['invalid'];
        try {
            $this->countries->preview($o, 2, $this->actor);
            $this->fail('Malformed groups must not authorize a change');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('دریافت نشد', $e->getMessage());
        }
        $this->assertSame([], $this->panel->updates);
    }

    public function test_order_details_still_open_when_provider_has_removed_its_only_group(): void
    {
        $o = $this->order();
        DB::table('inbounds')->where('id', 10)->update(['status' => -1]);
        DB::table('panels')->where('id', 1)->update(['url' => 'https://panel.test', 'username' => 'admin', 'password' => 'test']);
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'token']),
            '*/api/user/by-id/7' => Http::response(['id' => 7, 'status' => 'active', 'group_ids' => [],
                'data_limit' => 20 * 1073741824, 'used_traffic' => 1073741824, 'expire' => $o->expire_at->toIso8601String()]),
            '*/subscription/links' => Http::response(''),
        ]);
        $details = getConfigDetail($o);
        $this->assertTrue($details['status']);
        $this->assertSame([], $details['data']['group_ids']);
        $this->assertEquals(19, $details['data']['left']);
        $this->assertSame('🇩🇪 آلمان', app(OrderCountryResolver::class)->resolve([$o])[$o->id]);
        $this->assertSame('7', $o->fresh()->uid);
    }

    public function test_failed_remote_change_never_changes_the_local_country(): void
    {
        $o = $this->order();
        $this->panel->mode = 'reject';
        $preview = $this->countries->preview($o, 2, $this->actor);
        try {
            $this->countries->confirm($preview['token'], $this->actor);
            $this->fail('Expected rejection');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('تأیید نشد', $e->getMessage());
        }
        $this->assertArrayNotHasKey('current_country', $o->fresh()->detail);
        $this->assertCount(1, $this->panel->updates);
    }

    public function test_confirmation_is_bound_to_admin_and_detects_concurrent_group_changes(): void
    {
        $o = $this->order();
        $preview = $this->countries->preview($o, 2, $this->actor);
        try {
            $this->countries->confirm($preview['token'], ['source' => 'telegram', 'id' => '2', 'name' => 'other']);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('تأیید منقضی', $e->getMessage());
        }
        $this->panel->groups = [100, 200];
        try {
            $this->countries->confirm($preview['token'], $this->actor);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('هم‌زمان', $e->getMessage());
        }
        $this->assertSame([], $this->panel->updates);
    }

    public function test_unknown_empty_or_foreign_panel_groups_do_not_guess_or_overwrite_country(): void
    {
        $o = $this->order();
        foreach ([[], [999], ['bad'], null, [100, 999]] as $groups) {
            $this->assertFalse($this->countries->observe($o, ['group_ids' => $groups]));
        }
        DB::table('inbounds')->insert(['id' => 99, 'panel_id' => 2, 'inbound_id' => 999, 'country_id' => 2, 'status' => 1]);
        $this->assertFalse($this->countries->observe($o, ['group_ids' => [999]]));
        $this->assertArrayNotHasKey('current_country', $o->detail);
        Http::assertNothingSent();
    }

    public function test_delayed_usage_and_detail_responses_cannot_undo_a_country_change(): void
    {
        $o = $this->order();
        $stale = $o->fresh();
        $preview = $this->countries->preview($o, 2, $this->actor);
        $this->countries->confirm($preview['token'], $this->actor);
        $life = app(OrderLifecycleService::class);
        $this->assertFalse($life->applyPasarguardSnapshot($stale, ['group_ids' => [100], 'status' => 'active']));
        $life->applyConfigDetail($stale, ['status' => true, 'data' => ['remote_available' => true, 'group_ids' => [100], 'provider_status' => 'active']]);
        $this->assertSame('🇬🇧 انگلیس', $o->fresh()->detail['current_country']['label']);
    }

    public function test_current_location_overrides_legacy_all_country_flags_for_display_and_future_quotes(): void
    {
        $o = $this->order(['pasarguard-id' => 1, 'is_all_countries' => true]);
        $o->inbound_id = 0;
        $o->save();
        $this->countries->observe($o, ['group_ids' => [200]]);
        $o->save();
        $this->assertSame('🇬🇧 انگلیس', app(OrderCountryResolver::class)->resolve([$o])[$o->id]);
        $this->assertFalse(app(OrderPricingService::class)->isAllCountries($o, Panels::find(1)));
        $this->countries->observe($o, ['group_ids' => [200, 100]]);
        $o->save();
        $this->assertTrue(app(OrderPricingService::class)->isAllCountries($o, Panels::find(1)));
        $this->assertSame('🌍 همه کشورها', app(OrderCountryResolver::class)->resolve([$o])[$o->id]);
    }

    public function test_bridge_preview_confirm_and_sync_validate_identity_and_publish_current_country(): void
    {
        config(['services.wp_sync.secret' => 'country-test-secret']);
        $o = $this->order();
        $this->withHeader('X-IPSABET-API-KEY', 'country-test-secret');
        $preview = $this->postJson('/api/wp-sync/orders/country/preview', ['order_id' => $o->id, 'country_id' => 2, 'admin_id' => 3, 'admin_name' => 'Web admin'])->assertOk()->json();
        $this->postJson('/api/wp-sync/orders/country/confirm', ['token' => $preview['token'], 'admin_id' => 4, 'admin_name' => 'Other'])->assertStatus(409);
        $this->postJson('/api/wp-sync/orders/country/confirm', ['token' => $preview['token'], 'admin_id' => 3, 'admin_name' => 'Web admin'])
            ->assertOk()->assertJsonPath('order.detail.country', '🇬🇧 انگلیس')->assertJsonPath('order.detail.country_flag', '🇬🇧');
        Schema::create('wp_sync_site_orders', function (Blueprint $t) {
            $t->id();
            $t->integer('site_order_id');
            $t->integer('bot_order_id');
        });
        DB::table('wp_sync_site_orders')->insert(['site_order_id' => 90, 'bot_order_id' => $o->id]);
        $payload = ['site_order_id' => 90, 'uid' => 'wrong', 'group_ids' => [100], 'admin_id' => 3];
        $this->postJson('/api/wp-sync/orders/country/sync', $payload)->assertStatus(409);
        $payload['uid'] = '7';
        $this->postJson('/api/wp-sync/orders/country/sync', $payload)->assertOk()->assertJsonPath('country.label', '🇩🇪 آلمان');
        $this->assertCount(1, $this->panel->updates); // Mirror performs no provider write/read.
        $this->assertSame(2, $this->panel->reads);
        Http::assertNothingSent();
    }

    public function test_disabled_target_and_locked_order_are_rejected_before_provider_write(): void
    {
        $o = $this->order();
        $preview = $this->countries->preview($o, 2, $this->actor);
        $lock = Cache::lock('order:country:1:7', 180);
        $lock->get();
        try {
            $this->countries->confirm($preview['token'], $this->actor);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('در حال انجام', $e->getMessage());
        }
        $lock->release();
        DB::table('countries')->where('id', 2)->update(['status' => 0]);
        try {
            $this->countries->confirm($preview['token'], $this->actor);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('تنظیمات کشور', $e->getMessage());
        }
        $this->assertSame([], $this->panel->updates);
    }

    public function test_multiple_groups_for_one_country_are_not_all_countries(): void
    {
        DB::table('inbounds')->where('id', 11)->update(['country_id' => 1]);
        $location = $this->countries->location(1, [100, 200]);
        $this->assertSame('🇩🇪 آلمان', $location['label']);
        $this->assertFalse($location['is_all_countries']);
        $this->assertCount(1, $this->countries->options($this->order()));
    }

    public function test_admin_keyboard_and_history_are_escaped_compact_and_restricted(): void
    {
        $o = $this->order();
        $bot = new CountryBotHarness;
        $this->assertSame('forbidden', $bot->run(['id' => $o->id]));
        $bot->isAdmin = true;
        $message = $bot->run(['id' => $o->id]);
        $rows = json_decode($message['reply_markup'], true)['inline_keyboard'];
        $this->assertCount(2, $rows[0]);
        $this->assertCount(1, $rows[1]);
        $this->assertStringContainsString('همه کشورها', $rows[1][0]['text']);
        $message = $bot->run(['id' => $o->id, 'a' => 'select', 'c' => 2]);
        foreach (json_decode($message['reply_markup'], true)['inline_keyboard'] as $row) {
            foreach ($row as $button) {
                $this->assertLessThanOrEqual(64, strlen($button['callback_data']));
            }
        }
        $this->countries->observe($o, ['group_ids' => [200]], ['name' => '<admin>']);
        $o->save();
        $history = $bot->run(['id' => $o->id, 'a' => 'history']);
        $this->assertStringContainsString('&lt;admin&gt;', $history['text']);
        Http::assertNothingSent();
    }

    public function test_country_bridge_rejects_missing_secret(): void
    {
        config(['services.wp_sync.secret' => 'a-test-secret']);
        $this->postJson('/api/wp-sync/orders/country/confirm', ['token' => str_repeat('a', 24), 'admin_id' => 1])->assertForbidden();
        $this->assertSame([], $this->panel->updates);
    }
}

class CountryHarness extends OrderCountryService
{
    public function __construct(private CountryPanel $fake) {}

    protected function client(Panels $panel): PasarGuard
    {
        return $this->fake;
    }
}
class CountryPanel extends PasarGuard
{
    public array $groups = [100];

    public array $updates = [];

    public string $mode = 'success';

    public int $reads = 0;

    public function __construct() {}

    public function getUserById($id)
    {
        $this->reads++;

        return ['id' => 7, 'group_ids' => $this->groups, 'status' => 'active'];
    }

    public function updateUserById($id, array $params)
    {
        $this->updates[] = $params;
        if ($this->mode !== 'reject') {
            $this->groups = $params['group_ids'];
        }

        return $this->mode === 'success' ? ['id' => 7, 'group_ids' => $this->groups, 'status' => 'active'] : ['status' => false];
    }
}

class CountryBotHarness
{
    use ManagesOrderCountry;

    public bool $isAdmin = false;

    public object $user;

    private int $chatId = 1;

    private int $messageId = 1;

    public function __construct()
    {
        $this->user = (object) ['id' => 1, 'username' => 'admin'];
    }

    public function run(array $data)
    {
        return $this->adminOrderCountry($data);
    }

    private function denyAdminAccess()
    {
        return 'forbidden';
    }

    private function sendMessage($data,$method)
    {
        return $data;
    }
}
