<?php

namespace Tests\Feature;

use App\Models\Orders;
use App\Models\Setting;
use App\Services\OrderLifecycleNotificationService;
use App\Services\OrderLifecycleService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderLifecycleNotificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('tel_id')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('name')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('remark');
            $table->string('status');
            $table->integer('reminded')->default(0);
            $table->json('detail')->nullable();
            $table->timestamp('expire_at')->nullable();
            $table->timestamps();
        });

        DB::table('users')->insert([
            'id' => 1,
            'tel_id' => '1001',
            'status' => 1,
        ]);
        DB::table('settings')->insert([
            ['key' => 'renew', 'name' => 'Renew', 'value' => '1'],
            ['key' => 'extra', 'name' => 'Extra', 'value' => '1'],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_sends_renewal_and_low_volume_notifications_once_per_order_cycle(): void
    {
        $renewalOrder = $this->createOrder(
            'office-service',
            now()->addHours(24),
            ['total_gb' => 20, 'used_gb' => 15, 'left_gb' => 5]
        );
        $volumeOrder = $this->createOrder(
            'mobile-service',
            now()->addDays(5),
            ['total_gb' => 10, 'used_gb' => 9, 'left_gb' => 1]
        );
        $safeOrder = $this->createOrder(
            'safe-service',
            now()->addHours(25),
            ['total_gb' => 30, 'used_gb' => 10, 'left_gb' => 20]
        );

        $notifications = new FakeOrderLifecycleNotificationService(app(OrderLifecycleService::class));
        $first = $notifications->sendDueNotifications();

        $this->assertSame(1, $first['renewal_sent']);
        $this->assertSame(1, $first['volume_sent']);
        $this->assertCount(2, $notifications->messages);
        $this->assertStringContainsString('زمان تمدید سرویس نزدیک است', $notifications->messages[0]['text']);
        $this->assertStringContainsString("type=clientRenewOrder|id={$renewalOrder->id}", $notifications->messages[0]['reply_markup']);
        $this->assertStringContainsString('حجم سرویس رو به اتمام است', $notifications->messages[1]['text']);
        $this->assertStringContainsString("type=clientBuyExtra|id={$volumeOrder->id}", $notifications->messages[1]['reply_markup']);
        $this->assertSame(1, (int) $renewalOrder->fresh()->reminded);
        $this->assertSame(0, (int) $safeOrder->fresh()->reminded);

        $second = $notifications->sendDueNotifications();

        $this->assertSame(0, $second['renewal_sent']);
        $this->assertSame(0, $second['volume_sent']);
        $this->assertCount(2, $notifications->messages);

        $renewalState = $renewalOrder->fresh()->detail['lifecycle']['notifications']['renewal_24h'];
        $volumeState = $volumeOrder->fresh()->detail['lifecycle']['notifications']['low_volume'];
        $this->assertSame('sent', $renewalState['state']);
        $this->assertSame('sent', $volumeState['state']);
    }

    public function test_waiting_services_do_not_receive_notices_from_old_scheduled_rows(): void
    {
        $order = $this->createOrder('waiting', now()->addHours(1), ['provider_status' => 'on_hold', 'total_gb' => 1, 'used_gb' => 0, 'left_gb' => 1]);
        app(\App\Services\OrderCheckSchedule::class)->prepare();
        DB::table('order_checks')->where('order_id', $order->id)->update(['notice_at' => now()]);
        $service = new FakeOrderLifecycleNotificationService(app(OrderLifecycleService::class));
        $result = $service->sendDueNotifications();
        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['renewal_sent']);
        $this->assertSame(0, $result['volume_sent']);
        $this->assertSame([], $service->messages);
        $this->assertNull(DB::table('order_checks')->where('order_id', $order->id)->value('notice_at'));
    }

    public function test_a_failed_delivery_is_released_and_retried(): void
    {
        $order = $this->createOrder(
            'retry-service',
            now()->addHours(12),
            ['total_gb' => 20, 'used_gb' => 10, 'left_gb' => 10]
        );

        $notifications = new FakeOrderLifecycleNotificationService(app(OrderLifecycleService::class));
        $notifications->failNext = true;
        $failed = $notifications->sendDueNotifications();

        $this->assertSame(1, $failed['failed']);
        $this->assertSame('failed', $order->fresh()->detail['lifecycle']['notifications']['renewal_24h']['state']);
        $this->assertSame(0, $notifications->sendDueNotifications()['renewal_sent']);
        Carbon::setTestNow(now()->addMinutes(2));

        $retried = $notifications->sendDueNotifications();

        $this->assertSame(1, $retried['renewal_sent']);
        $this->assertSame('sent', $order->fresh()->detail['lifecycle']['notifications']['renewal_24h']['state']);
    }

    public function test_admin_thresholds_apply_and_messages_are_short_and_escaped(): void
    {
        Setting::create(['key' => 'notify_hours', 'value' => '48']);
        Setting::create(['key' => 'notify_gb', 'value' => '2.5']);
        $this->createOrder('<office>', now()->addHours(36), ['total_gb'=>20, 'used_gb'=>17.5, 'left_gb'=>2.5]);
        $notifications = new FakeOrderLifecycleNotificationService(app(OrderLifecycleService::class));
        $result = $notifications->sendDueNotifications();
        $this->assertSame(1, $result['renewal_sent']);
        $this->assertSame(1, $result['volume_sent']);
        $this->assertStringContainsString('36 ساعت', $notifications->messages[0]['text']);
        $this->assertStringContainsString('2.5 گیگ', $notifications->messages[1]['text']);
        foreach ($notifications->messages as $message) {
            $this->assertLessThan(300, mb_strlen($message['text']));
            $this->assertStringContainsString('&lt;office&gt;', $message['text']);
        }
        Setting::where('key', 'notify_gb')->update(['value'=>'3']);
        $this->assertSame(0, $notifications->sendDueNotifications()['volume_sent']);
    }

    public function test_disabled_notices_or_disabled_sales_prevent_delivery(): void
    {
        $this->createOrder('service', now()->addHours(12), ['total_gb'=>20, 'used_gb'=>19, 'left_gb'=>1]);
        Setting::create(['key'=>'notify_renew', 'value'=>'0']);
        Setting::create(['key'=>'notify_volume', 'value'=>'0']);
        $notifications = new FakeOrderLifecycleNotificationService(app(OrderLifecycleService::class));
        $notifications->sendDueNotifications();
        $this->assertCount(0, $notifications->messages);
        Setting::whereIn('key', ['notify_renew', 'notify_volume'])->update(['value'=>'1']);
        Setting::whereIn('key', ['renew', 'extra'])->update(['value'=>'0']);
        $notifications->sendDueNotifications();
        $this->assertCount(0, $notifications->messages);
    }

    public function test_stale_unlimited_dedicated_cancelled_and_blocked_orders_are_not_notified(): void
    {
        $stale = $this->createOrder('stale', now()->addDays(2), ['total_gb'=>20, 'used_gb'=>19, 'left_gb'=>1]);
        $detail = $stale->detail;
        $detail['lifecycle']['last_checked_at'] = now()->subMinutes(16)->toDateTimeString();
        $stale->update(['detail'=>$detail]);
        $this->createOrder('unlimited', now()->addDays(2), ['total_gb'=>0, 'used_gb'=>20, 'left_gb'=>0]);
        $dedicated = $this->createOrder('website', now()->addHours(12), ['total_gb'=>20, 'used_gb'=>19, 'left_gb'=>1]);
        $dedicated->update(['detail'=>array_merge($dedicated->detail, ['service_type'=>'dedicated'])]);
        $cancelled = $this->createOrder('cancelled', now()->addHours(12), ['total_gb'=>20, 'used_gb'=>19, 'left_gb'=>1, 'remote_disabled'=>true]);
        $notifications = new FakeOrderLifecycleNotificationService(app(OrderLifecycleService::class));
        $notifications->sendDueNotifications();
        $this->assertCount(0, $notifications->messages);
        DB::table('users')->where('id', 1)->update(['status'=>0]);
        $this->createOrder('blocked', now()->addHours(12), ['total_gb'=>20, 'used_gb'=>19, 'left_gb'=>1]);
        $notifications->sendDueNotifications();
        $this->assertCount(0, $notifications->messages);
    }

    public function test_renewal_and_top_up_rearm_only_the_next_cycle(): void
    {
        $order = $this->createOrder('cycle', now()->addHours(12), ['total_gb'=>20, 'used_gb'=>19, 'left_gb'=>1]);
        $notifications = new FakeOrderLifecycleNotificationService(app(OrderLifecycleService::class));
        $notifications->sendDueNotifications();
        $order->refresh();
        $detail = $order->detail;
        $detail['lifecycle'] = array_merge($detail['lifecycle'], ['total_gb'=>25, 'left_gb'=>6]);
        $order->update(['expire_at'=>now()->addDays(30), 'detail'=>$detail]);
        $notifications->sendDueNotifications();
        $this->assertCount(2, $notifications->messages);
        $detail['lifecycle'] = array_merge($detail['lifecycle'], ['used_gb'=>24.5, 'left_gb'=>0.5]);
        $order->update(['detail'=>$detail]);
        $this->assertSame(1, $notifications->sendDueNotifications()['volume_sent']);
        Carbon::setTestNow(now()->addDays(29));
        $this->assertSame(1, $notifications->sendDueNotifications()['renewal_sent']);
        $this->assertCount(4, $notifications->messages);
    }

    public function test_telegram_retry_after_pauses_the_whole_batch_and_resumes_later(): void
    {
        $this->createOrder('first', now()->addHours(12), ['total_gb'=>20, 'used_gb'=>19, 'left_gb'=>1]);
        $this->createOrder('second', now()->addHours(12), ['total_gb'=>20, 'used_gb'=>19, 'left_gb'=>1]);
        $notifications = new FakeOrderLifecycleNotificationService(app(OrderLifecycleService::class));
        $notifications->nextResponse = ['ok'=>false, 'error_code'=>429, 'parameters'=>['retry_after'=>180]];
        $this->assertSame(1, $notifications->sendDueNotifications()['failed']);
        $this->assertCount(1, $notifications->messages);
        Carbon::setTestNow(now()->addMinutes(2));
        $notifications->sendDueNotifications();
        $this->assertCount(1, $notifications->messages);
        Carbon::setTestNow(now()->addMinute());
        $this->assertSame(2, $notifications->sendDueNotifications()['renewal_sent']);
    }

    public function test_notification_limit_continues_with_unsent_orders_on_next_run(): void
    {
        for ($i=0; $i<105; $i++) $this->createOrder('bulk-'.$i, now()->addHours(12), ['total_gb'=>20, 'used_gb'=>0, 'left_gb'=>20]);
        $notifications = new FakeOrderLifecycleNotificationService(app(OrderLifecycleService::class));
        $this->assertSame(100, $notifications->sendDueNotifications()['renewal_sent']);
        $this->assertSame(5, $notifications->sendDueNotifications()['renewal_sent']);
        $this->assertCount(105, $notifications->messages);
    }

    private function createOrder(string $remark, Carbon $expireAt, array $lifecycle): Orders
    {
        $lifecycle['last_checked_at'] = now()->toDateTimeString();
        $id = DB::table('orders')->insertGetId([
            'user_id' => 1,
            'remark' => $remark,
            'status' => Orders::STATUS_ACTIVE,
            'reminded' => 0,
            'detail' => json_encode(['lifecycle' => $lifecycle]),
            'expire_at' => $expireAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Orders::findOrFail($id);
    }
}

class FakeOrderLifecycleNotificationService extends OrderLifecycleNotificationService
{
    public array $messages = [];

    public bool $failNext = false;

    public ?array $nextResponse = null;

    protected function paceTelegram(string $chatId): void {}

    protected function sendTelegramMessage(array $params): array
    {
        $this->messages[] = $params;
        if ($this->nextResponse !== null) {
            $response = $this->nextResponse;
            $this->nextResponse = null;
            return $response;
        }

        if ($this->failNext) {
            $this->failNext = false;

            return ['ok' => false, 'error' => 'temporary_failure'];
        }

        return ['ok' => true];
    }
}
