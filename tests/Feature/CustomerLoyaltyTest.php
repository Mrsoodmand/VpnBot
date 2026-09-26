<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TelegramBotController;
use App\Models\Payment;
use App\Models\User;
use App\Services\CustomerLoyaltyService;
use App\Services\Telegram;
use App\Services\WpSyncService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerLoyaltyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.wp_sync.secret' => 'test-secret', 'loyalty.enabled' => true, 'loyalty.shared_service_ids' => [1]]);
        Carbon::setTestNow('2026-09-26 12:00:00');
        foreach (['0001_01_01_000000_create_users_table.php', '2026_05_21_173105_create_payments_table.php',
            '2026_05_17_112220_create_settings_table.php', '2026_05_20_145319_create_pre_orders_table.php',
            '2026_05_19_100650_create_services_table.php', '2026_05_17_112153_create_plans_table.php',
            '2026_05_18_155104_create_countries_table.php', '2026_05_17_114351_create_telegram_data_table.php', '2026_05_17_113131_create_panels_table.php',
            '2026_05_18_093434_create_extra_bandwidths_table.php', '2026_05_20_104904_add_price_column_to_services_table.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->integer('user_id'); $t->integer('panel_id')->nullable(); $t->integer('inbound_id')->default(1);
            $t->string('remark')->default('test'); $t->string('status')->default('active'); $t->json('detail')->nullable();
            $t->timestamp('expire_at')->nullable(); $t->timestamps();
        });
        DB::table('users')->insert(['id' => 1, 'tel_id' => '1001', 'status' => 1, 'balance' => 1000000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeProfile(float $percent = 10): void
    {
        Http::fake(['*/sync/customer-loyalty/*' => Http::response(['ok' => true, 'currency' => 'IRT', 'profile' => [
            'kind' => 'customer', 'automatic_percent' => $percent, 'percent' => $percent, 'source' => 'automatic', 'stars' => 3, 'total' => 5000000,
            'expires_at' => null, 'next_amount' => 5000000, 'manual_value' => '',
        ]])]);
    }

    private function payment(array $changes = []): Payment
    {
        return Payment::create(array_replace(['user_id' => 1, 'method' => 'cart-be-cart', 'type' => '4', 'price' => 1000000,
            'status' => 1, 'detail' => ['loyalty_approved_at' => now()->toIso8601String()]], $changes));
    }

    public function test_only_original_approved_events_are_exported_without_financial_writes(): void
    {
        foreach ([1,2,3,4] as $type) $this->payment(['type' => (string) $type]);
        foreach (['wallet','wordpress_wallet_receipt','ramzino','referral','admin_refund'] as $method) $this->payment(['method' => $method]);
        foreach ([0,-1,-2] as $status) $this->payment(['status' => $status]);
        $this->payment(['detail' => ['loyalty_approved_at' => now()->toIso8601String(), 'receipt_id' => 5]]);
        $this->payment(['detail' => []]); // Unknown historical approval must never use upload/update time.
        $this->payment(['detail' => ['loyalty_approved_at' => now()->subDays(61)->toIso8601String()]]);
        $this->payment(['user_id' => 2]);
        $count = Payment::count();
        $events = app(CustomerLoyaltyService::class)->events(User::find(1));
        $this->assertSame(['new','renewal','extra_volume','wallet'], array_column($events['rows'], 'type'));
        $this->assertFalse($events['first_purchase']);
        $this->assertSame(1000000, (int) User::find(1)->balance);
        $this->assertSame($count, Payment::count());
    }

    public function test_approval_timestamp_is_immutable_and_rejected_payment_cannot_be_approved(): void
    {
        $p = $this->payment(['status' => 0, 'detail' => ['customer_loyalty_quote' => ['amount' => 90000]]]);
        $sync = app(WpSyncService::class);
        $first = $sync->approvePayment($p->id, ['apply_wallet' => true]);
        $at = $p->fresh()->detail['loyalty_approved_at'];
        $this->travel(10)->days();
        $again = $sync->approvePayment($p->id, ['apply_wallet' => true]);
        $this->assertTrue($first['ok']); $this->assertTrue($again['already_approved']);
        $this->assertSame($at, $p->fresh()->detail['loyalty_approved_at']);
        $this->assertSame(2000000, (int) User::find(1)->balance);
        $this->assertSame(['amount' => 90000], $p->fresh()->detail['customer_loyalty_quote']);
        $bad = $this->payment(['status' => -1]);
        $this->assertFalse($sync->approvePayment($bad->id)['ok']);
    }

    public function test_admin_corrections_have_original_timestamp_but_wallet_spending_does_not(): void
    {
        $sync = app(WpSyncService::class); $user = User::find(1);
        $sync->adminWalletAdjust($user, 'credit', 500000);
        $sync->adminWalletAdjust($user, 'debit', 200000);
        $sync->debitWallet($user, 100000);
        $events = app(CustomerLoyaltyService::class)->events($user);
        $this->assertSame(['wallet_admin_credit','wallet_admin_debit'], array_column($events['rows'], 'type'));
        $this->assertSame([500000,200000], array_column($events['rows'], 'amount'));
    }

    public function test_quote_is_scoped_rounded_like_site_and_applied_after_product_discount(): void
    {
        $this->fakeProfile(15);
        $user = User::find(1); $service = app(CustomerLoyaltyService::class);
        $this->assertSame(76500, $service->quote($user, 1, 90000)['amount']);
        $this->assertSame(86, $service->quote($user, 1, 101)['amount']);
        $this->assertSame(90000, $service->quote($user, 2, 90000)['amount']);
        $user->is_seller = 1;
        $this->assertSame(90000, $service->quote($user, 1, 90000)['amount']);
        Http::assertSentCount(1);
        $this->assertSame(0, Payment::count());
    }

    public function test_unavailable_authority_blocks_new_discounted_quote(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->expectException(\RuntimeException::class);
        app(CustomerLoyaltyService::class)->quote(User::find(1), 1, 100000);
    }

    public function test_malformed_authority_profile_cannot_create_a_quote(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'currency' => 'IRT', 'profile' => ['kind' => 'customer', 'percent' => 10]])]);
        $this->expectException(\RuntimeException::class);
        app(CustomerLoyaltyService::class)->quote(User::find(1), 1, 100000);
    }

    private function setupCatalog(): void
    {
        DB::table('services')->insert(['id' => 1, 'name' => 'shared', 'price_per_gb' => 10000]);
        DB::table('plans')->insert(['id' => 1, 'name' => 'plan', 'price' => 100000, 'discount' => 10, 'type' => 1, 'status' => 1]);
        DB::table('countries')->insert(['id' => 1, 'name' => 'test']);
        DB::table('panels')->insert(['id' => 1, 'name' => 'test', 'panel_type' => 1]);
        DB::table('orders')->insert(['id' => 1, 'user_id' => 1, 'panel_id' => 1, 'expire_at' => now()->addMonth()]);
        DB::table('extra_bandwidths')->insert(['id' => 1, 'name' => '10', 'discount' => 10, 'type' => 1, 'status' => 1]);
    }

    public function test_all_three_checkout_paths_snapshot_final_price_and_wallet_replay_keeps_it(): void
    {
        $this->fakeProfile(); $this->setupCatalog();
        $user = User::find(1);
        $user->tel_detail = ['order-service-id' => 1, 'order-country-id' => 1, 'order-plan-id' => 1, 'order-count' => 1];
        $user->save();
        $controller = new LoyaltyTestController($user);
        $controller->call('clientFinalStep', ['name' => 'Example', 'price' => 1, 'discount' => 100]);
        $controller->call('clientSubmitRenew', ['o_id' => 1, 'pl_id' => 1, 'price' => 1]);
        $controller->call('clientSubmitExtra', ['o_id' => 1, 'ex_id' => 1, 'percent' => 100]);
        $this->assertSame([81000,81000,81000], Payment::orderBy('id')->pluck('price')->all());
        foreach (Payment::all() as $p) $this->assertSame(81000, $p->detail['customer_loyalty_quote']['amount']);
        foreach ($controller->messages as $message) $this->assertStringContainsString('81,000', $message['text']);
        $this->fakeProfile(20);
        $controller->call('paymentWallet', ['id' => 1]);
        $controller->call('paymentWallet', ['id' => 1]);
        $this->assertSame(81000, (int) Payment::find(1)->price);
        $this->assertSame(919000, (int) $user->fresh()->balance);
        $this->assertSame(1, $controller->fulfilled);
        $this->assertSame([], app(CustomerLoyaltyService::class)->events($user)['rows']);
    }

    public function test_quantity_uses_the_same_rounded_unit_price_as_the_catalog(): void
    {
        $this->fakeProfile(15); $this->setupCatalog();
        DB::table('plans')->where('id', 1)->update(['price' => 103, 'discount' => 0]);
        $user = User::find(1);
        $user->tel_detail = ['order-service-id' => 1, 'order-country-id' => 1, 'order-plan-id' => 1, 'order-count' => 3];
        $user->save();
        $controller = new LoyaltyTestController($user);
        $controller->call('clientFinalStep', ['name' => 'Example']);
        $p = Payment::first();
        $this->assertSame(264, (int) $p->price);
        $this->assertSame(88, $p->detail['customer_loyalty_quote']['unit_amount']);
        $this->assertSame(3, $p->detail['customer_loyalty_quote']['quantity']);
    }

    public function test_bot_admin_approval_is_idempotent_and_records_review_time(): void
    {
        $user = User::find(1); $user->is_admin = 1;
        $controller = new LoyaltyTestController($user);
        $p = $this->payment(['status' => 0, 'type' => 1, 'detail' => []]);
        $controller->call('adminConfirmCartReceipt', ['p_id' => $p->id]);
        $at = $p->fresh()->detail['loyalty_approved_at'];
        $this->travel(1)->days();
        $controller->call('adminConfirmCartReceipt', ['p_id' => $p->id]);
        $this->assertSame($at, $p->fresh()->detail['loyalty_approved_at']);
        $this->assertSame(1, $controller->fulfilled);
    }

    public function test_authenticated_event_endpoint_and_duplicate_reference(): void
    {
        config(['services.wp_sync.secret' => 'test-secret']);
        $this->payment(['ref_id' => 'bank-1']); $this->payment(['ref_id' => 'bank-1']);
        $this->postJson('/api/wp-sync/customer-loyalty/events', ['tel_id' => '1001'])->assertForbidden();
        $response = $this->withHeader('X-IPSABET-API-KEY', 'test-secret')->postJson('/api/wp-sync/customer-loyalty/events', ['tel_id' => '1001']);
        $response->assertOk()->assertJsonCount(1, 'events.rows')->assertJsonPath('events.currency', 'IRT');
    }

    public function test_admin_settings_validate_before_write_and_manual_clear_differs_from_zero(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $user = User::find(1); $user->is_admin = 1; $user->tel_detail = ['customer-discount-user-id' => 1];
        $user->save();
        $controller = new LoyaltyTestController($user);
        foreach (["0:0\n5000000:5\n2000000:10\n10000000:20", "0:0\n2000000:15\n5000000:10\n10000000:20", "0:0\n-1:5\n5000000:10\n10000000:20"] as $text) {
            $controller->input($text); $controller->call('adminCustomerLoyaltySave', []);
        }
        Http::assertNothingSent();
        $controller->input("0:0\n2000000:5\n5000000:10\n10000000:20");
        $controller->call('adminCustomerLoyaltySave', []);
        Http::assertSent(fn ($r) => $r['customer'][1]['min'] === 2000000 && $r['customer'][1]['strict'] === 0);
        $controller->input('0'); $controller->call('adminCustomerDiscountSave', []);
        $controller->call('adminCustomerDiscountSave', ['type' => 'adminCustomerDiscountClear', 'id' => 1]);
        Http::assertSent(fn ($r) => ($r['value'] ?? null) === '0');
        Http::assertSent(fn ($r) => ($r['value'] ?? null) === '');
    }

    public function test_verified_approval_import_is_dry_run_by_default_and_never_rewrites_money(): void
    {
        $p = $this->payment(['detail' => []]);
        $file = tempnam(sys_get_temp_dir(), 'loyalty-approval-');
        file_put_contents($file, "payment_id,user_id,amount,approved_at,evidence\n{$p->id},1,1000000,".now()->toIso8601String().",admin-audit-42\n");
        try {
            $this->artisan('loyalty:approval-times', ['file' => $file])->assertSuccessful();
            $this->assertSame([], $p->fresh()->detail);
            $this->artisan('loyalty:approval-times', ['file' => $file, '--apply' => true])->assertSuccessful();
            $this->assertSame(now()->toIso8601String(), $p->fresh()->detail['loyalty_approved_at']);
            $this->artisan('loyalty:approval-times', ['file' => $file, '--apply' => true])->assertSuccessful();
            $this->assertSame(1000000, (int) $p->fresh()->price);
            $this->assertSame(1000000, (int) User::find(1)->balance);
            $this->assertSame(1, Payment::count());
        } finally { unlink($file); }
    }

    public function test_bad_approval_import_rolls_back_entire_batch(): void
    {
        $p = $this->payment(['detail' => []]);
        $other = $this->payment(['detail' => []]);
        $file = tempnam(sys_get_temp_dir(), 'loyalty-approval-');
        $at = now()->toIso8601String();
        file_put_contents($file, "payment_id,user_id,amount,approved_at,evidence\n{$p->id},1,1000000,{$at},proof-1\n{$other->id},1,999,{$at},wrong-amount\n");
        try {
            $this->artisan('loyalty:approval-times', ['file' => $file, '--apply' => true])->assertFailed();
            $this->assertSame([], $p->fresh()->detail);
            $this->assertSame([], $other->fresh()->detail);
        } finally { unlink($file); }
    }

    public function test_admin_home_and_settings_expose_loyalty_even_when_disabled_and_open_the_editor(): void
    {
        config(['loyalty.enabled' => false]);
        $user = User::find(1); $user->is_admin = 1; $user->save();
        $controller = new LoyaltyTestController($user);
        foreach (['admin-home', 'adminSetting'] as $callback) {
            $controller->callback('type='.$callback);
            $message = end($controller->messages);
            $keyboard = json_decode($message['reply_markup'], true)['inline_keyboard'];
            $buttons = array_merge(...$keyboard);
            $matches = array_values(array_filter($buttons, fn ($button) => ($button['callback_data'] ?? '') === 'type=adminCustomerLoyalty'));
            $this->assertCount(1, $matches);
            $this->assertStringContainsString('تخفیف پلکانی کاربران عادی', $matches[0]['text']);
        }
        Http::assertNothingSent();
        Http::fake(['*/sync/customer-loyalty/settings' => Http::response(['ok' => true, 'currency' => 'IRT', 'customer' => [
            ['min' => 0, 'strict' => 0, 'percent' => 0],
            ['min' => 2000000, 'strict' => 0, 'percent' => 5],
            ['min' => 5000000, 'strict' => 0, 'percent' => 10],
            ['min' => 10000000, 'strict' => 0, 'percent' => 20],
        ]])]);
        $controller->callback('type=adminCustomerLoyalty');
        $message = end($controller->messages);
        $this->assertStringContainsString("0:0\n2000000:5\n5000000:10\n10000000:20", $message['text']);
        $this->assertSame('adminCustomerLoyaltySave', $user->fresh()->path);
        Http::assertSentCount(1);
    }

    public function test_sync_uses_cached_config_for_auth_and_outgoing_requests(): void
    {
        config(['services.wp_sync.secret' => 'cached-key', 'services.wp_sync.base_url' => 'https://site.test/']);
        $sync = app(WpSyncService::class);
        $this->assertTrue($sync->authorize('cached-key'));
        $this->assertFalse($sync->authorize('test-secret'));
        $this->assertSame('https://site.test', $sync->wpBaseUrl());
        Http::fake(['https://site.test/*' => Http::response(['ok' => true])]);
        app(CustomerLoyaltyService::class)->request('settings');
        Http::assertSent(fn ($r) => $r->url() === 'https://site.test/wp-json/ipsvp/v1/sync/customer-loyalty/settings'
            && $r->hasHeader('X-IPSABET-API-KEY', 'cached-key'));
    }

    public function test_both_admin_buttons_explain_missing_site_route_without_entering_save_mode(): void
    {
        Http::fake(['*' => Http::response(['code' => 'rest_no_route'], 404)]);
        $user = User::find(1); $user->is_admin = 1; $user->path = 'adminSetting'; $user->save();
        $controller = new LoyaltyTestController($user);
        foreach (['type=adminCustomerLoyalty', 'type=adminCustomerDiscount|id=1'] as $callback) {
            $controller->callback($callback);
            $this->assertStringContainsString('نسخهٔ جدید افزونهٔ سایت', end($controller->messages)['text']);
            $this->assertNotContains($user->fresh()->path, ['adminCustomerLoyaltySave', 'adminCustomerDiscountSave']);
        }
        $this->assertSame(0, Payment::count());
        $this->assertSame(1000000, (int) $user->fresh()->balance);
    }

    public function test_admin_fixed_discount_editor_opens_and_shows_explicit_zero(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'currency' => 'IRT', 'profile' => [
            'kind' => 'customer', 'percent' => 0, 'automatic_percent' => 10, 'source' => 'manual',
            'stars' => 1, 'total' => 5000000, 'manual_value' => '0',
        ]])]);
        $user = User::find(1); $user->is_admin = 1; $user->save();
        $controller = new LoyaltyTestController($user);
        $controller->callback('type=adminCustomerDiscount|id=1');
        $this->assertStringContainsString('تخفیف ثابت حساب: 0٪', end($controller->messages)['text']);
        $this->assertSame('adminCustomerDiscountSave', $user->fresh()->path);
    }

    public function test_account_screen_shows_level_and_percentage_even_before_pricing_is_enabled(): void
    {
        config(['loyalty.enabled' => false]); $this->fakeProfile();
        $controller = new LoyaltyTestController(User::find(1));
        $controller->call('profile', []);
        $text = end($controller->messages)['text'];
        foreach (['سطح حساب: 3 ستاره', 'تخفیف پلکانی بر اساس پرداخت‌ها: 10٪', 'تخفیف حساب: 10٪ (پلکانی)',
            '5,000,000 تومان', 'تا پله بعدی:', 'هنوز فعال نشده'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertSame(0, Payment::count());
    }

    public function test_manual_account_level_and_discount_are_distinct_from_automatic_tier(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'currency' => 'IRT', 'profile' => [
            'kind' => 'customer', 'percent' => 15, 'automatic_percent' => 5, 'source' => 'manual',
            'stars' => 3, 'total' => 2000000, 'expires_at' => null, 'manual_value' => '15',
        ]])]);
        $text = app(CustomerLoyaltyService::class)->profileText(User::find(1));
        $this->assertStringContainsString('سطح حساب: 3 ستاره', $text);
        $this->assertStringContainsString('پرداخت‌ها: 5٪', $text);
        $this->assertStringContainsString('تخفیف فعال: 15٪ (دستی)', $text);
        $this->assertStringNotContainsString('تا پله بعدی', $text);
        $this->assertStringNotContainsString('افت سطح', $text);
    }

    public function test_automatic_top_level_shows_expiry_without_next_tier(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'currency' => 'IRT', 'profile' => [
            'kind' => 'customer', 'percent' => 20, 'automatic_percent' => 20, 'source' => 'automatic',
            'stars' => 4, 'total' => 10000000, 'expires_at' => now()->addDay()->timestamp, 'next_amount' => null,
        ]])]);
        $text = app(CustomerLoyaltyService::class)->profileText(User::find(1));
        $this->assertStringContainsString('سطح حساب: 4 ستاره', $text);
        $this->assertStringContainsString('تخفیف فعال: 20٪', $text);
        $this->assertStringContainsString('افت سطح فعلی:', $text);
        $this->assertStringNotContainsString('تا پله بعدی', $text);
    }

    public function test_unavailable_profile_keeps_account_screen_usable_without_inventing_a_discount(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $controller = new LoyaltyTestController(User::find(1));
        $controller->call('profile', []);
        $text = end($controller->messages)['text'];
        $this->assertStringContainsString('حساب کاربری', $text);
        $this->assertStringContainsString('اطلاعات تخفیف موقتاً در دسترس نیست', $text);
        $this->assertStringNotContainsString('WP_BASE_URL', $text);
        $this->assertStringNotContainsString('تخفیف فعال:', $text);
        try { app(CustomerLoyaltyService::class)->quote(User::find(1), 1, 100000); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertStringNotContainsString('WP_BASE_URL', $e->getMessage()); }
    }

    public function test_missing_secret_is_reported_without_sending_a_request(): void
    {
        config(['services.wp_sync.secret' => '']);
        try { app(CustomerLoyaltyService::class)->request('settings'); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('WP_SYNC_SECRET', $e->getMessage()); }
        Http::assertNothingSent();
    }

    public function test_auth_bridge_and_network_errors_have_specific_admin_diagnostics(): void
    {
        foreach ([[403, [], 'کلید اتصال'], [503, ['message' => 'customer_loyalty_bridge_disabled'], 'IPSVP_CUSTOMER_LOYALTY_BOT']] as [$status, $body, $expected]) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response($body, $status)]);
            try { app(CustomerLoyaltyService::class)->request('settings'); $this->fail(); }
            catch (\RuntimeException $e) { $this->assertStringContainsString($expected, $e->getMessage()); }
        }
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('test timeout'));
        try { app(CustomerLoyaltyService::class)->request('settings'); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('ارتباط با سایت برقرار نشد', $e->getMessage()); }
    }

    public function test_non_admin_cannot_write_settings_or_override(): void
    {
        $controller = new LoyaltyTestController(User::find(1));
        $controller->call('adminCustomerLoyaltySave', []);
        $controller->call('adminCustomerDiscountSave', []);
        Http::assertNothingSent();
    }
}

class LoyaltyTestController extends TelegramBotController
{
    public array $messages = [];
    public int $fulfilled = 0;

    public function __construct(User $user)
    {
        $this->user = $user; $this->isAdmin = (bool) $user->is_admin; $this->chatId = $user->tel_id;
        $this->method = 'toUser'; $this->type = 'text'; $this->text = '10';
        $sdk = \Mockery::mock(Telegram::class);
        $sdk->shouldReceive('sendMessage')->andReturnUsing(function ($data) { $this->messages[] = $data; return ['ok' => false]; });
        $sdk->shouldReceive('editMessage')->andReturnUsing(function ($data) { $this->messages[] = $data; return ['ok' => false]; });
        $sdk->shouldReceive('answerCallback')->andReturn([]);
        $this->telegramSdk = $sdk;
    }
    public function callback(string $data) { $this->callbackData = $data; $this->type = 'callback_query'; return $this->index(); }
    public function input(string $text) { $this->text = $text; }
    public function call($method, $data) { return $this->$method($data); }
    protected function finalPaymentStep($payment) { $this->fulfilled++; return true; }
}
