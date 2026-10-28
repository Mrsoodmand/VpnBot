<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TelegramBotController;
use App\Models\Orders;
use App\Models\Payment;
use App\Services\Telegram;
use App\Services\PaymentReceiptFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PasarGuardReceiptFulfillmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-23 12:00:00');
        $_ENV['TELEGRAM_BOT_TOKEN'] = $_SERVER['TELEGRAM_BOT_TOKEN'] = 'test-token';
        foreach (['0001_01_01_000000_create_users_table.php', '2026_05_21_173105_create_payments_table.php',
            '2026_05_17_112220_create_settings_table.php', '2026_05_20_145319_create_pre_orders_table.php',
            '2026_05_17_112153_create_plans_table.php', '2026_05_18_155104_create_countries_table.php',
            '2026_05_17_114351_create_telegram_data_table.php', '2026_05_17_113131_create_panels_table.php',
            '2026_05_18_093434_create_extra_bandwidths_table.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::table('panels', fn (Blueprint $t) => $t->string('sub_address')->nullable());
        Schema::create('inbounds', function (Blueprint $t) {
            $t->id(); $t->integer('panel_id'); $t->integer('inbound_id'); $t->integer('country_id'); $t->integer('status');
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->integer('user_id'); $t->integer('panel_id'); $t->integer('inbound_id')->default(0);
            $t->string('remark'); $t->string('uid'); $t->string('sub_id')->default(''); $t->integer('plan')->default(1);
            $t->string('system_type')->default('pasarguard'); $t->string('status'); $t->json('detail')->nullable();
            $t->integer('reminded')->default(0); $t->timestamp('expire_at'); $t->timestamps();
        });
        (require database_path('migrations/2026_10_28_000000_allow_pending_order_expiry.php'))->up();
        DB::table('users')->insert([
            ['id' => 1, 'tel_id' => '1001', 'is_admin' => '1', 'status' => 1, 'balance' => 0],
            ['id' => 2, 'tel_id' => '2002', 'is_admin' => '0', 'status' => 1, 'balance' => 1000000],
        ]);
        DB::table('settings')->insert(['key' => 'cart_be_cart_id', 'name' => 'Transaction channel', 'value' => '-1001234']);
        DB::table('panels')->insert(['id' => 7, 'url' => 'https://panel.test', 'username' => 'admin', 'password' => 'secret',
            'system_type' => 'pasarguard', 'status' => 1, 'country_id' => 0, 'sub_address' => 'https://sub.test']);
        DB::table('plans')->insert(['id' => 1, 'bandwidth' => '10', 'days' => '30', 'type' => 'shared']);
        DB::table('extra_bandwidths')->insert(['id' => 1, 'name' => '5']);
        DB::table('inbounds')->insert(['panel_id' => 7, 'inbound_id' => 2, 'country_id' => 1, 'status' => 1]);
        Cache::put('pasarguard-token-7', 'stale-token');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payment(int $type = 1, int $count = 1): Payment
    {
        if ($type === 1) {
            $orderId = DB::table('pre_orders')->insertGetId(['user_id' => 2, 'count' => $count, 'count_left' => $count,
                'status' => 0, 'data' => json_encode(['plan-id' => 1, 'country-id' => 0, 'pasarguard-id' => 7, 'name' => 'random'])]);
        } else {
            $orderId = Orders::create(['user_id' => 2, 'panel_id' => 7, 'remark' => 'existing-user', 'uid' => '42',
                'status' => 'active', 'expire_at' => now()->addDays(5), 'detail' => []])->id;
        }
        return Payment::create(['user_id' => 2, 'order_id' => $orderId, 'type' => (string) $type, 'status' => 0,
            'method' => 'cart-be-cart', 'price' => 100000, 'detail' => ['plan-id' => 1, 'extra-id' => 1]]);
    }

    private function approve(Payment $payment, bool $photoFailure = false, string $callback = 'adminConfirmCartReceipt', bool $legacy = false, ?int $actor = null, ?int $chatId = null, bool $photo = true): ReceiptTelegramFake
    {
        $wallet = $callback === 'paymentWallet';
        $controller = new TelegramBotController(Request::create('/api/telegram-webhook', 'POST', [
            'callback_query' => ['id' => 'cb-'.$payment->id, 'from' => ['id' => $actor ?? ($wallet ? 2002 : 1001)],
                'data' => 'type='.$callback.($wallet ? '|id=' : '|p_id=').$payment->id.($legacy ? '|legacy=1' : ''),
                'message' => ['message_id' => $chatId ? 777 : 77, 'chat' => ['id' => $chatId ?? ($wallet ? 2002 : -1001234)],
                    ...($photo ? ['photo' => [['file_id' => 'receipt']], 'caption' => 'رسید'] : ['text' => 'رسید'])]],
        ]));
        $telegram = new ReceiptTelegramFake($photoFailure);
        (new \ReflectionProperty($controller, 'telegramSdk'))->setValue($controller, $telegram);
        $controller->index();
        return $telegram;
    }

    public function test_new_purchase_refreshes_token_delivers_correct_link_and_cannot_be_approved_twice(): void
    {
        $payment = $this->payment();
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fresh-token']),
            '*/api/user' => Http::sequence()->push(['detail' => 'Expired'], 401)
                ->push(['id' => 42, 'status' => 'on_hold', 'subscription_url' => 'https://sub.test/sub/42']),
            '*/subscription/links' => Http::response('vless://config'),
        ]);
        $telegram = $this->approve($payment);
        $this->assertSame(1, Orders::count());
        $this->assertSame(Orders::STATUS_ON_HOLD, Orders::first()->status);
        $this->assertNull(Orders::first()->expire_at);
        $this->assertSame(0, (int) DB::table('pre_orders')->value('count_left'));
        $this->assertStringContainsString('https://sub.test/sub/42', $telegram->text());
        $this->assertStringNotContainsString('https://sub.testhttps://', $telegram->text());
        $this->assertSame(1, (int) $payment->fresh()->status);
        $this->approve($payment);
        $this->assertSame(1, Orders::count());
        Http::assertSentCount(4);
    }

    public function test_failed_creation_replaces_waiting_caption_and_records_error_without_refund_or_blind_retry(): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::response(['detail' => [['loc' => ['body', 'group_ids'], 'msg' => 'Invalid group']]], 422)]);
        $telegram = $this->approve($payment);
        $this->assertSame(0, Orders::count());
        $this->assertSame('needs_review', $payment->fresh()->detail['fulfillment']['status']);
        $this->assertStringContainsString('Invalid group', $telegram->text());
        $this->assertStringContainsString('لطفاً دوباره پرداخت نکنید', $telegram->text());
        $this->assertSame(1000000, (int) DB::table('users')->where('id', 2)->value('balance'));
        $this->approve($payment);
        Http::assertSentCount(2); // Second attempt only reads; a failed lookup cannot create another user.
        Http::assertSent(fn ($r) => $r->method() === 'GET');
    }

    public function test_partial_bulk_purchase_preserves_and_delivers_completed_items(): void
    {
        $payment = $this->payment(1, 2);
        Http::fake([
            '*/api/user' => Http::sequence()->push(['id' => 42, 'status' => 'on_hold', 'subscription_url' => '/sub/42'])
                ->push(['detail' => 'Panel unavailable'], 503),
            '*/subscription/links' => Http::response('vless://config'),
        ]);
        $telegram = $this->approve($payment);
        $this->assertSame(1, Orders::count());
        $this->assertSame(1, (int) DB::table('pre_orders')->value('count_left'));
        $this->assertSame('needs_review', $payment->fresh()->detail['fulfillment']['status']);
        $this->assertStringContainsString('https://sub.test/sub/42', $telegram->text());
    }

    public function test_subscription_and_text_fallbacks_deliver_without_creating_another_user(): void
    {
        $payment = $this->payment();
        Http::fake([
            '*/api/user' => Http::response(['id' => 42, 'status' => 'on_hold', 'subscription_url' => '/sub/42']),
            '*/subscription/links' => Http::response(['detail' => 'Unavailable'], 503),
        ]);
        $telegram = $this->approve($payment, true);
        $this->assertSame(1, Orders::count());
        $this->assertStringContainsString('https://sub.test/sub/42', $telegram->text());
        $this->assertSame('completed', $payment->fresh()->detail['fulfillment']['status']);
        $this->assertNotEmpty(array_filter($telegram->calls, fn ($call) => $call[0] === 'sendMessage'
            && str_contains($call[1]['text'] ?? '', 'https://sub.test/sub/42')));
        Http::assertSentCount(2);
    }

    public function test_renewal_refreshes_token_and_sends_utc_expiry_with_existing_traffic(): void
    {
        $payment = $this->payment(2);
        $expiry = now()->addDays(5);
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fresh-token']),
            '*/by-id/42' => Http::sequence()->push(['detail' => 'Expired'], 401)
                ->push(['id' => 42, 'status' => 'active', 'expire' => $expiry->copy()->utc()->toISOString(), 'data_limit' => 20 * 1073741824])
                ->push(['id' => 42, 'status' => 'active']),
        ]);
        $telegram = $this->approve($payment);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['expire'] === $expiry->copy()->addDays(30)->utc()->toISOString()
            && $r['data_limit'] === 30 * 1073741824);
        $this->assertTrue(Orders::first()->expire_at->equalTo($expiry->copy()->addDays(30)));
        $this->assertStringContainsString('تمدید سرویس با موفقیت', $telegram->text());
        $this->approve($payment);
        Http::assertSentCount(4);
    }

    public function test_extra_volume_keeps_on_hold_expiration_and_adds_to_existing_limit(): void
    {
        $payment = $this->payment(3);
        Orders::first()->update(['status' => Orders::STATUS_ON_HOLD, 'expire_at' => null,
            'detail' => ['lifecycle' => ['provider_status' => Orders::STATUS_ON_HOLD]]]);
        Http::fake(['*' => Http::sequence()
            ->push(['id' => 42, 'status' => 'on_hold', 'expire' => null, 'data_limit' => 20 * 1073741824])
            ->push(['id' => 42, 'status' => 'on_hold'])]);
        $telegram = $this->approve($payment);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['data_limit'] === 25 * 1073741824
            && !array_key_exists('expire', $r->data()) && !array_key_exists('status', $r->data()));
        $this->assertNull(Orders::first()->expire_at);
        $this->assertSame(Orders::STATUS_ON_HOLD, Orders::first()->status);
        $this->assertStringContainsString('خرید حجم سرویس با موفقیت', $telegram->text());
        $this->approve($payment);
        Http::assertSentCount(2);
    }

    public function test_failed_panel_read_never_overwrites_existing_traffic_for_renewal_or_extra(): void
    {
        Http::fake(['*' => Http::response(['detail' => 'Permission denied'], 403)]);
        foreach ([2, 3] as $type) {
            $payment = $this->payment($type);
            $this->approve($payment);
            $this->assertSame('needs_review', $payment->fresh()->detail['fulfillment']['status']);
        }
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
        $this->assertSame(1000000, (int) DB::table('users')->where('id', 2)->value('balance'));
    }

    public function test_ambiguous_update_failure_is_reported_without_refund_or_second_update(): void
    {
        $payment = $this->payment(3);
        Http::fake(['*' => Http::sequence()->push(['id' => 42, 'status' => 'active', 'data_limit' => 100])
            ->push(['detail' => 'Gateway timeout'], 504)]);
        $telegram = $this->approve($payment);
        $this->assertSame('needs_review', $payment->fresh()->detail['fulfillment']['status']);
        $this->assertStringContainsString('Gateway timeout', $telegram->text());
        $this->assertSame(1000000, (int) DB::table('users')->where('id', 2)->value('balance'));
        $this->approve($payment);
        Http::assertSentCount(2);
    }

    public function test_wallet_failure_is_reported_without_charging_twice(): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::response(['detail' => 'Unavailable'], 503)]);
        $this->approve($payment, false, 'paymentWallet');
        $this->assertSame('needs_review', $payment->fresh()->detail['fulfillment']['status']);
        $this->approve($payment, false, 'paymentWallet');
        $this->assertSame(900000, (int) DB::table('users')->where('id', 2)->value('balance'));
        Http::assertSentCount(1);
    }
    public function test_retry_recovers_an_account_created_before_a_lost_response_without_another_post(): void
    {
        $payment = $this->payment();
        $created = null;
        $posts = 0;
        Http::fake(function ($request) use (&$created, &$posts) {
            if ($request->method() === 'POST') {
                $posts++;
                $created = ['id' => 42, 'status' => 'on_hold', 'username' => $request['username'],
                    'note' => $request['note'], 'subscription_url' => '/sub/42'];
                return Http::response(['detail' => 'Gateway timeout'], 504);
            }
            if (str_contains($request->url(), '/subscription/')) return Http::response('vless://config');
            return Http::response($created);
        });
        $first = $this->approve($payment);
        $this->assertSame('needs_review', $payment->fresh()->detail['fulfillment']['status']);
        $this->assertStringContainsString('adminRetryPayment', json_encode($first->calls));
        $this->approve($payment, false, 'adminRetryPayment');
        $this->assertSame(1, $posts);
        $this->assertSame(1, Orders::count());
        $this->assertSame($created['username'], Orders::first()->remark);
        $this->assertSame('completed', $payment->fresh()->detail['fulfillment']['status']);
    }

    public function test_retry_after_missing_remote_account_reuses_the_exact_saved_username(): void
    {
        $payment = $this->payment();
        $names = [];
        Http::fake(function ($request) use (&$names) {
            if ($request->method() === 'POST') {
                $names[] = $request['username'];
                return count($names) === 1 ? Http::response(['detail' => 'Unavailable'], 503)
                    : Http::response(['id' => 42, 'status' => 'on_hold', 'username' => $request['username'], 'subscription_url' => '/sub/42']);
            }
            return str_contains($request->url(), '/subscription/') ? Http::response('vless://config')
                : Http::response(['detail' => 'Not found'], 404);
        });
        $this->approve($payment);
        $this->approve($payment, false, 'adminRetryPayment');
        $this->assertCount(2, $names);
        $this->assertSame($names[0], $names[1]);
        $this->assertSame(1, Orders::count());
    }

    public static function updateTypes(): array
    {
        return [[2], [3]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('updateTypes')]
    public function test_retry_reconciles_applied_renewal_and_volume_without_applying_twice(int $type): void
    {
            $payment = $this->payment($type);
            $remote = ['id' => 42, 'status' => 'active', 'data_limit' => 20 * 1073741824,
                'expire' => now()->addDays(5)->utc()->toISOString()];
            $puts = 0;
            Http::fake(function ($request) use (&$remote, &$puts) {
                if ($request->method() === 'PUT') {
                    $puts++;
                    $remote = array_merge($remote, $request->data());
                    return Http::response(['detail' => 'Gateway timeout'], 504);
                }
                return Http::response($remote);
            });
            $this->approve($payment);
            $this->assertSame('needs_review', $payment->fresh()->detail['fulfillment']['status']);
            $this->approve($payment, false, 'adminRetryPayment');
            $this->assertSame(1, $puts);
            $this->assertSame('completed', $payment->fresh()->detail['fulfillment']['status']);
            $this->assertSame(($type === 2 ? 30 : 25) * 1073741824, $remote['data_limit']);
    }

    public function test_retry_uses_saved_renewal_target_even_when_plan_or_time_changes(): void
    {
        $payment = $this->payment(2);
        $before = ['id' => 42, 'status' => 'active', 'data_limit' => 20 * 1073741824,
            'expire' => now()->addDays(5)->utc()->toISOString()];
        $writes = [];
        Http::fake(function ($request) use ($before, &$writes) {
            if ($request->method() !== 'PUT') return Http::response($before);
            $writes[] = $request->data();
            return count($writes) === 1 ? Http::response(['detail' => 'Gateway timeout'], 504)
                : Http::response(array_merge($before, $request->data()));
        });
        $this->approve($payment);
        $this->travel(1)->days();
        DB::table('plans')->where('id', 1)->update(['bandwidth' => 100, 'days' => 90]);
        $this->approve($payment, false, 'adminRetryPayment');
        $this->assertCount(2, $writes);
        $this->assertSame($writes[0], $writes[1]);
        $this->assertTrue(Orders::first()->expire_at->equalTo(Carbon::parse($writes[0]['expire'])));
    }

    public function test_conflicting_remote_change_stops_retry(): void
    {
        $payment = $this->payment(3);
        $reads = 0;
        $puts = 0;
        Http::fake(function ($request) use (&$reads, &$puts) {
            if ($request->method() === 'PUT') {
                $puts++;
                return Http::response(['detail' => 'Gateway timeout'], 504);
            }
            $reads++;
            return Http::response(['id' => 42, 'status' => 'active', 'data_limit' => ($reads === 1 ? 20 : 99) * 1073741824]);
        });
        $this->approve($payment);
        $this->approve($payment, false, 'adminRetryPayment');
        $this->assertSame(1, $puts);
        $this->assertSame('needs_review', $payment->fresh()->detail['fulfillment']['status']);
        $this->assertStringContainsString('Service changed', $payment->fresh()->detail['fulfillment']['message']);
    }

    public function test_legacy_waiting_receipt_requires_review_then_can_be_retried_without_payment(): void
    {
        $payment = $this->payment();
        $payment->update(['status' => 1]);
        Http::fake([
            '*/api/user' => Http::response(['id' => 42, 'status' => 'on_hold', 'subscription_url' => '/sub/42']),
            '*/subscription/links' => Http::response('vless://config'),
        ]);
        $notice = $this->approve($payment); // Original confirm button on the old receipt.
        Http::assertNothingSent();
        $this->assertStringContainsString('legacy=1', json_encode($notice->calls));
        $this->approve($payment, false, 'adminRetryPayment', true);
        $this->assertSame(1, Orders::count());
        $this->assertSame(1000000, (int) DB::table('users')->where('id', 2)->value('balance'));
    }

    public function test_explicit_rejected_update_refunds_once_and_cannot_be_retried(): void
    {
        $payment = $this->payment(3);
        Http::fake(['*' => Http::sequence()->push(['id' => 42, 'status' => 'active', 'data_limit' => 100])
            ->push(['detail' => 'Invalid limit'], 422)]);
        $this->approve($payment);
        $this->assertSame(-2, (int) $payment->fresh()->status);
        $this->assertSame('refunded', $payment->fresh()->detail['fulfillment']['status']);
        $this->approve($payment, false, 'adminRetryPayment');
        $this->assertSame(1100000, (int) DB::table('users')->where('id', 2)->value('balance'));
        Http::assertSentCount(2);
    }

    public function test_non_admin_and_overlapping_attempt_cannot_retry(): void
    {
        $payment = $this->payment();
        $payment->update(['status' => 1, 'detail' => ['fulfillment' => ['version' => 1, 'status' => 'needs_review']]]);
        Http::fake();
        $denied = $this->approve($payment, false, 'adminRetryPayment', false, 2002);
        $this->assertStringContainsString('دسترسی غیرمجاز', $denied->text());
        $lock = Cache::lock('bot-payment-fulfillment-'.$payment->id, 600);
        $this->assertTrue($lock->get());
        try {
            $busy = $this->approve($payment, false, 'adminRetryPayment');
            $this->assertStringContainsString('در حال پردازش', $busy->text());
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_admin_transaction_list_exposes_retry_for_old_purchase_without_local_order(): void
    {
        $payment = $this->payment();
        $payment->update(['status' => 1]);
        $controller = new TelegramBotController(Request::create('/api/telegram-webhook', 'POST', [
            'callback_query' => ['id' => 'list-cb', 'from' => ['id' => 1001], 'data' => 'type=adminUserPayments|id=2',
                'message' => ['message_id' => 777, 'chat' => ['id' => 1001], 'text' => 'Transactions']],
        ]));
        $telegram = new ReceiptTelegramFake();
        (new \ReflectionProperty($controller, 'telegramSdk'))->setValue($controller, $telegram);
        $controller->index();
        $this->assertStringContainsString('adminRetryPayment|p_id='.$payment->id, json_encode($telegram->calls));
        Http::assertNothingSent();
    }

    public function test_retry_from_private_list_edits_the_original_receipt_message(): void
    {
        $payment = $this->payment();
        $posts = 0;
        Http::fake(function ($request) use (&$posts) {
            if ($request->method() === 'POST') {
                return ++$posts === 1 ? Http::response(['detail' => 'Unavailable'], 503)
                    : Http::response(['id' => 42, 'status' => 'on_hold', 'subscription_url' => '/sub/42']);
            }
            return str_contains($request->url(), '/subscription/') ? Http::response('vless://config')
                : Http::response(['detail' => 'Not found'], 404);
        });
        $this->approve($payment);
        $telegram = $this->approve($payment, false, 'adminRetryPayment', false, null, 1001);
        $edits = array_values(array_filter($telegram->calls, fn ($call) => $call[0] === 'editMessageCaption'));
        $this->assertNotEmpty($edits, json_encode($telegram->calls, JSON_UNESCAPED_UNICODE));
        foreach ($edits as $edit) {
            $this->assertSame(77, $edit[1]['message_id']);
            $this->assertSame('-1001234', (string) $edit[1]['chat_id']);
        }
        $this->assertSame(['inline_keyboard' => []], json_decode(end($edits)[1]['reply_markup'], true));
    }

    public function test_retry_of_partial_bulk_order_only_creates_the_remaining_item(): void
    {
        $payment = $this->payment(1, 2);
        $posts = 0;
        Http::fake(function ($request) use (&$posts) {
            if ($request->method() === 'POST') {
                $posts++;
                return $posts === 2 ? Http::response(['detail' => 'Unavailable'], 503)
                    : Http::response(['id' => 40 + $posts, 'username' => $request['username'], 'status' => 'on_hold', 'subscription_url' => '/sub/'.(40 + $posts)]);
            }
            return str_contains($request->url(), '/subscription/') ? Http::response('vless://config')
                : Http::response(['detail' => 'Not found'], 404);
        });
        $this->approve($payment);
        $firstId = Orders::first()->id;
        $this->approve($payment, false, 'adminRetryPayment');
        $this->assertSame(3, $posts);
        $this->assertSame(2, Orders::count());
        $this->assertSame($firstId, Orders::orderBy('id')->first()->id);
        $this->assertSame(0, (int) DB::table('pre_orders')->value('count_left'));
        $this->assertSame('completed', $payment->fresh()->detail['fulfillment']['status']);
    }

    public function test_legacy_purchase_with_saved_config_is_resent_without_new_remote_creation(): void
    {
        $payment = $this->payment();
        $payment->update(['status' => 1]);
        Orders::create(['user_id' => 2, 'panel_id' => 7, 'remark' => 'existing', 'uid' => '42', 'sub_id' => '/sub/42',
            'status' => 'active', 'expire_at' => now()->addDays(30), 'detail' => ['preOrderId' => $payment->order_id, 'code' => 'vless://config']]);
        $telegram = $this->approve($payment, false, 'adminRetryPayment', true);
        Http::assertNothingSent();
        $this->assertSame(1, Orders::count());
        $this->assertStringContainsString('https://sub.test/sub/42', $telegram->text());
        $this->assertSame('completed', $payment->fresh()->detail['fulfillment']['status']);
    }


    public static function receiptTypes(): array
    {
        $cases = [];
        foreach ([1 => 'خرید جدید ربات', 2 => 'تمدید سرویس ربات', 3 => 'خرید حجم ربات', 4 => 'شارژ کیف پول ربات'] as $type => $label) {
            foreach ([true, false] as $photo) $cases[$type . ($photo ? '-photo' : '-text')] = [$type, $label, $photo];
        }
        return $cases;
    }

    private function receiptPayment(int $type): Payment
    {
        (require database_path('migrations/2026_05_19_100650_create_services_table.php'))->up();
        (require database_path('migrations/2026_06_20_000000_create_wp_sync_links_table.php'))->up();
        DB::table('services')->insert(['id' => 1, 'name' => 'آی پی ثابت اشتراکی']);
        DB::table('plans')->where('id', 1)->update(['name' => 'پلن ده گیگ', 'type' => '1']);
        DB::table('panels')->where('id', 7)->update(['panel_type' => 1, 'country_id' => 1]);
        DB::table('countries')->insert(['id' => 1, 'name' => 'آلمان 🇩🇪']);
        DB::table('users')->where('id', 2)->update(['username' => 'customer']);
        DB::table('users')->where('id', 1)->update(['username' => 'admin']);
        DB::table('wp_sync_links')->insert(['user_id' => 2, 'tel_id' => '2002', 'phone' => '+989120000000']);
        $payment = $this->payment($type);
        if ($type === 1) {
            DB::table('pre_orders')->where('id', $payment->order_id)->update(['data' => json_encode([
                'service-id' => 1, 'country-id' => 1, 'plan-id' => 1, 'pasarguard-id' => 0, 'name' => 'random',
            ])]);
        } elseif ($type !== 4) {
            Orders::whereKey($payment->order_id)->update(['inbound_id' => 1]);
        } else {
            $payment->update(['order_id' => 0]);
        }
        $payment->update(['detail' => array_merge($payment->detail, [
            'cart-number' => '1111222233334444', 'cart-name' => 'نام <کارت> &',
        ])]);
        DB::table('users')->where('id', 2)->update(['path' => 'sendCartBeCartReceipt',
            'tel_detail' => json_encode(['payment-id' => $payment->id])]);
        return $payment;
    }

    private function submitReceipt(Payment $payment, bool $photo): ReceiptTelegramFake
    {
        $controller = new TelegramBotController(Request::create('/api/telegram-webhook', 'POST', [
            'message' => ['message_id' => 10, 'from' => ['id' => 2002], 'chat' => ['id' => 2002],
                ...($photo ? ['photo' => [['file_id' => 'receipt']]] : ['text' => 'کد بانکی <123> & 456'])],
        ]));
        $telegram = new ReceiptTelegramFake();
        (new \ReflectionProperty($controller, 'telegramSdk'))->setValue($controller, $telegram);
        $controller->index();
        return $telegram;
    }

    private function adminReceiptText(ReceiptTelegramFake $telegram): string
    {
        return implode("\n", array_map(fn ($call) => $call[1]['caption'] ?? $call[1]['text'] ?? '',
            array_filter($telegram->calls, fn ($call) => ($call[1]['chat_id'] ?? null) == -1001234)));
    }

    #[DataProvider('receiptTypes')]
    public function test_receipt_details_survive_initial_processing_and_approval(int $type, string $label, bool $photo): void
    {
        $payment = $this->receiptPayment($type);
        $initial = $this->submitReceipt($payment, $photo);
        $text = $this->adminReceiptText($initial);
        $this->assertStringContainsString('وضعیت: ⏳ در انتظار بررسی', $text);
        $this->assertStringContainsString('نوع: ' . $label, $text);
        $this->assertStringContainsString('موبایل: +989120000000', $text);
        $this->assertStringContainsString('نام &lt;کارت&gt; &amp;', $text);
        if (!$photo) $this->assertStringContainsString('کد بانکی &lt;123&gt; &amp; 456', $text);
        $this->assertNotEmpty($payment->fresh()->detail['receipt_details']);
        $this->assertStringContainsString('adminRejectCartReceipt', json_encode($initial->calls));
        if ($type !== 4) {
            $this->assertStringNotContainsString('نوع سرویس:', $text);
            $this->assertStringContainsString('پلن ده گیگ', $text);
            $this->assertStringContainsString('آلمان 🇩🇪', $text);
        }
        // Later metadata edits must not rewrite the customer's original receipt.
        DB::table('plans')->update(['name' => 'پلن تغییر یافته']);
        DB::table('countries')->update(['name' => 'کشور تغییر یافته']);
        DB::table('services')->update(['name' => 'سرویس تغییر یافته']);
        Http::fake([
            '*/api/user' => Http::response(['id' => 99, 'username' => 'delivered-user', 'status' => 'on_hold', 'subscription_url' => '/sub/99']),
            '*/subscription/links' => Http::response('vless://config'),
            '*/by-id/42' => Http::sequence()
                ->push(['id' => 42, 'status' => 'active', 'expire' => now()->addDays(5)->toISOString(), 'data_limit' => 10 * 1073741824])
                ->push(['id' => 42, 'status' => 'active']),
        ]);
        $approved = $this->approve($payment, photo: $photo);
        $text = $this->adminReceiptText($approved);
        $this->assertStringContainsString('وضعیت: ✅ تایید شده', $text);
        $this->assertStringContainsString('نوع: ' . $label, $text);
        $this->assertStringContainsString('مبلغ: 100,000 تومان', $text);
        $this->assertStringContainsString('شناسه رسید: <code>#' . $payment->id, $text);
        $this->assertStringContainsString('تایید شده توسط: @admin', $text);
        if ($type !== 4) {
            $this->assertStringContainsString('در حال تحویل سفارش', $text);
            $this->assertStringNotContainsString('نوع سرویس:', $text);
            $this->assertStringContainsString('پلن ده گیگ', $text);
            $this->assertStringContainsString('آلمان 🇩🇪', $text);
            $this->assertStringNotContainsString('تغییر یافته', $text);
            $this->assertStringContainsString($type === 1 ? 'delivered-user' : 'existing-user', $text);
        } else {
            $this->assertStringContainsString('موجودی پس از شارژ: 1,100,000 تومان', $text);
            $this->assertSame(1100000, (int) DB::table('users')->where('id', 2)->value('balance'));
        }
        if ($type === 3) $this->assertStringContainsString('حجم خریداری شده: 5 گیگ', $text);
        $edits = array_values(array_filter($approved->calls, fn ($call) => in_array($call[0], ['editMessageCaption', 'editMessageText'])));
        $last = end($edits);
        $this->assertSame($photo ? 'editMessageCaption' : 'editMessageText', $last[0]);
        $this->assertSame(['inline_keyboard' => []], json_decode($last[1]['reply_markup'], true));
        $this->assertSame('completed', $payment->fresh()->detail['fulfillment']['status']);
    }

    #[DataProvider('receiptTypes')]
    public function test_rejected_receipt_keeps_purchased_selection_and_never_contacts_panel(int $type, string $label, bool $photo): void
    {
        $payment = $this->receiptPayment($type);
        $this->submitReceipt($payment, $photo);
        DB::table('settings')->where('key', 'cart_be_cart_id')->update(['value' => '-1009999']);
        $telegram = $this->approve($payment, callback: 'adminRejectCartReceipt', photo: $photo);
        $text = $this->adminReceiptText($telegram);
        $this->assertStringContainsString('وضعیت: ❌ رد شده', $text);
        $this->assertStringContainsString('نوع: ' . $label, $text);
        $this->assertStringContainsString('رد شده توسط: @admin', $text);
        $this->assertStringContainsString('100,000 تومان', $text);
        if ($type !== 4) {
            $this->assertStringContainsString('پلن ده گیگ', $text);
            $this->assertStringContainsString('آلمان 🇩🇪', $text);
        }
        if ($type === 3) $this->assertStringContainsString('حجم خریداری شده: 5 گیگ', $text);
        if (!$photo) $this->assertStringContainsString('کد بانکی &lt;123&gt; &amp; 456', $text);
        $this->assertSame(-1, (int) $payment->fresh()->status);
        $this->assertSame(1000000, (int) DB::table('users')->where('id', 2)->value('balance'));
        Http::assertNothingSent();
    }

    public function test_long_receipts_and_batch_remarks_are_split_without_invalid_html_or_lost_details(): void
    {
        $payment = $this->receiptPayment(1);
        $this->submitReceipt($payment, true);
        for ($i = 0; $i < 45; $i++) {
            Orders::create(['user_id' => 2, 'panel_id' => 7, 'remark' => 'customer-service-' . $i, 'uid' => (string) $i,
                'status' => 'active', 'expire_at' => now()->addDays(30), 'detail' => ['preOrderId' => $payment->order_id]]);
        }
        $formatter = app(PaymentReceiptFormatter::class);
        $parts = $formatter->parts($payment->fresh(), 'approved');
        $this->assertGreaterThan(1, count($parts));
        $this->assertStringContainsString('customer-service-44', implode("\n", $parts));
        foreach ($parts as $part) {
            $plain = html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertLessThanOrEqual(1024, strlen(mb_convert_encoding($plain, 'UTF-16LE', 'UTF-8')) / 2);
        }
        $payment->refresh();
        $payment->detail = array_merge($payment->detail, ['receipt_is_photo' => false,
            'value' => str_repeat('🧾<>&', 1800) . 'END-RECEIPT']);
        $parts = array_merge($parts, $formatter->parts($payment, 'rejected', 4096));
        $this->assertStringContainsString('END-RECEIPT', implode("\n", $parts));
        foreach ($parts as $part) {
            $plain = html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertLessThanOrEqual(4000, strlen(mb_convert_encoding($plain, 'UTF-16LE', 'UTF-8')) / 2);
            $this->assertSame(substr_count($part, '<code>'), substr_count($part, '</code>'));
            $this->assertStringNotContainsString('<>&', $part);
        }
    }

    public function test_failed_receipt_and_retry_keep_details_in_original_channel_after_settings_change(): void
    {
        $payment = $this->receiptPayment(3);
        $this->submitReceipt($payment, true);
        Http::fake(['*/by-id/42' => Http::sequence()
            ->push(['id' => 42, 'status' => 'active', 'data_limit' => 10 * 1073741824])
            ->push(['detail' => 'Gateway timeout'], 504)
            ->push(['id' => 42, 'status' => 'active', 'data_limit' => 15 * 1073741824])]);
        $failed = $this->approve($payment);
        $text = $this->adminReceiptText($failed);
        $this->assertStringContainsString('تحویل نیاز به بررسی دارد', $text);
        $this->assertStringContainsString('حجم خریداری شده: 5 گیگ', $text);
        $this->assertStringContainsString('آلمان 🇩🇪', $text);
        $this->assertStringContainsString('existing-user', $text);
        $this->assertStringContainsString('Gateway timeout', $text);
        $this->assertStringContainsString('adminRetryPayment', json_encode($failed->calls));
        DB::table('settings')->where('key', 'cart_be_cart_id')->update(['value' => '-1009999']);
        $retry = $this->approve($payment, callback: 'adminRetryPayment', chatId: 1001);
        $text = $this->adminReceiptText($retry);
        $this->assertStringContainsString('وضعیت: ✅ تایید شده', $text);
        $this->assertStringContainsString('حجم خریداری شده: 5 گیگ', $text);
        foreach ($retry->calls as [$method, $params]) {
            if ($method === 'editMessageCaption') {
                $this->assertSame(-1001234, (int) $params['chat_id']);
                $this->assertSame(77, $params['message_id']);
            }
        }
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'PUT'));
        $this->assertSame('completed', $payment->fresh()->detail['fulfillment']['status']);
    }

    public function test_wallet_purchase_sends_same_detailed_admin_receipt_without_editing_customer_photo(): void
    {
        $payment = $this->receiptPayment(2);
        Http::fake(['*/by-id/42' => Http::sequence()
            ->push(['id' => 42, 'status' => 'active', 'expire' => now()->addDays(5)->toISOString(), 'data_limit' => 10 * 1073741824])
            ->push(['id' => 42, 'status' => 'active'])]);
        $telegram = $this->approve($payment, callback: 'paymentWallet');
        $text = $this->adminReceiptText($telegram);
        $this->assertStringContainsString('نوع: تمدید سرویس ربات', $text);
        $this->assertStringContainsString('روش پرداخت: کیف پول', $text);
        $this->assertStringContainsString('پلن ده گیگ', $text);
        $this->assertStringContainsString('آلمان 🇩🇪', $text);
        $this->assertStringContainsString('وضعیت: ✅ تایید شده', $text);
        $this->assertEmpty(array_filter($telegram->calls, fn ($call) => $call[0] === 'editMessageCaption'));
        $this->assertSame(900000, (int) DB::table('users')->where('id', 2)->value('balance'));
    }

}

class ReceiptTelegramFake extends Telegram
{
    public array $calls = [];

    public function __construct(private bool $photoFailure = false) {}

    protected function request(string $method, array $params = [])
    {
        $this->calls[] = [$method, $params];
        return ['ok' => !($this->photoFailure && $method === 'sendPhoto')
            && !str_contains($params['text'] ?? '', 'قبلاً پردازش')
            && !str_contains($params['text'] ?? '', 'قابل اجرای مجدد'), 'result' => ['message_id' => 77]];
    }

    public function downloadFileById(string $fileId, string $savePath): array
    {
        return ['ok' => true];
    }

    public function text(): string
    {
        return implode("\n", array_map(fn ($call) => $call[1]['text'] ?? $call[1]['caption'] ?? '', $this->calls));
    }
}
