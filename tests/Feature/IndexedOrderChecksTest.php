<?php

namespace Tests\Feature;

use App\Models\Orders;
use App\Models\Setting;
use App\Services\OrderCheckSchedule;
use App\Services\OrderLifecycleNotificationService;
use App\Services\OrderLifecycleService;
use App\Services\OrderNotificationSettings;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IndexedOrderChecksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-27 12:00:00');
        Http::preventStrayRequests();
        (require database_path('migrations/2026_05_17_112220_create_settings_table.php'))->up();
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('tel_id')->nullable();
            $t->integer('status')->default(1);
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->default(1);
            $t->unsignedBigInteger('panel_id')->nullable();
            $t->string('uid')->nullable();
            $t->string('remark')->default('service');
            $t->string('status')->default('active');
            $t->json('detail')->nullable();
            $t->dateTime('expire_at')->nullable();
            $t->integer('reminded')->default(0);
            $t->timestamps();
        });
        Schema::create('panels', function (Blueprint $t) {
            $t->id();
            $t->string('system_type');
            $t->timestamps();
        });
        DB::table('users')->insert(['id' => 1, 'tel_id' => '1001', 'status' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_twenty_thousand_future_orders_are_not_loaded_when_only_two_notices_are_due(): void
    {
        $future = now()->addDays(29)->toDateTimeString();
        for ($start = 1; $start <= 20000; $start += 500) {
            $orders = [];
            $checks = [];
            for ($id = $start; $id < $start + 500; $id++) {
                $orders[] = ['id' => $id, 'user_id' => 1, 'status' => 'active', 'expire_at' => now()->addDays(30)->toDateTimeString()];
                $checks[] = ['order_id' => $id, 'notice_at' => $future, 'state_at' => now()->addDays(30)->toDateTimeString()];
            }
            DB::table('orders')->insert($orders);
            DB::table('order_checks')->insert($checks);
        }
        $this->markInitialized();
        $renew = Orders::find(19999);
        $renew->update(['expire_at' => now()->addHours(23)]);
        $volume = Orders::find(20000);
        $volume->update(['detail' => ['lifecycle' => ['total_gb' => 20, 'used_gb' => 19.2, 'left_gb' => 0.8, 'usage_checked_at' => now()->toDateTimeString()]]]);
        $service = new IndexedNoticeFake(app(OrderLifecycleService::class));
        DB::enableQueryLog();
        $result = $service->sendDueNotifications();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(2, $result['checked']);
        $this->assertSame(1, $result['renewal_sent']);
        $this->assertSame(1, $result['volume_sent']);
        $this->assertCount(2, $service->messages);
        $this->assertNull(Orders::find(1)->detail);
        // No unbounded order query or mass status update in steady state.
        foreach ($queries as $q) {
            if (str_contains($q['query'], 'from "orders"')) {
                $this->assertTrue(str_contains($q['query'], '"id" in') || str_contains($q['query'], '"id" =') || str_contains($q['query'], 'limit 200'), $q['query']);
            }
            if (str_starts_with($q['query'], 'update "orders"')) {
                $this->assertStringContainsString('where "id" =', $q['query']);
            }
        }
        $this->assertLessThan(40, count($queries));
        $this->assertSame(0, $service->sendDueNotifications()['checked']);
    }

    public function test_all_due_queries_use_indexes_instead_of_table_scans(): void
    {
        foreach (['notice_at' => 'notice', 'state_at' => 'state', 'cleanup_at' => 'cleanup', 'provider_at' => 'provider'] as $column => $name) {
            $extra = $column === 'provider_at' ? 'panel_id = 1 and ' : '';
            $plan = DB::select("EXPLAIN QUERY PLAN select order_id from order_checks where {$extra}{$column} <= ? order by {$column}, order_id limit 200", [now()->toDateTimeString()]);
            $description = implode(' ', array_map(fn ($row) => $row->detail, $plan));
            $this->assertStringContainsString('SEARCH', $description);
            $this->assertStringContainsString('order_checks_'.$name.'_due', $description);
            $this->assertStringNotContainsString('SCAN order_checks', $description);
        }
    }

    public function test_passage_of_time_activates_a_notice_without_any_order_edit(): void
    {
        $order = $this->order(now()->addHours(25));
        $service = new IndexedNoticeFake(app(OrderLifecycleService::class));
        $this->assertSame(0, $service->sendDueNotifications()['checked']);
        $this->assertSame(now()->addHour()->toDateTimeString(), DB::table('order_checks')->where('order_id', $order->id)->value('notice_at'));
        Carbon::setTestNow(now()->addHour());
        $this->assertSame(1, $service->sendDueNotifications()['renewal_sent']);
        $this->assertNull(DB::table('order_checks')->where('order_id', $order->id)->value('notice_at'));
    }

    public function test_bootstrap_and_settings_rebuild_are_bounded_and_resume_across_gaps(): void
    {
        for ($i = 1; $i <= 605; $i++) {
            DB::table('orders')->insert(['id' => $i, 'expire_at' => now()->addDays(3)]);
        }
        DB::table('orders')->whereIn('id', [4, 201, 500])->delete();
        $schedule = app(OrderCheckSchedule::class);
        $first = $schedule->prepare();
        $this->assertSame(200, $first['seeded']);
        $this->assertSame(200, DB::table('order_checks')->count());
        $this->assertSame(['seeded' => 0, 'rescheduled' => 0], $schedule->prepare());
        for ($i = 0; $i < 3; $i++) {
            Carbon::setTestNow(now()->addMinute());
            $schedule->prepare();
        }
        $this->assertSame(602, DB::table('order_checks')->count());
        Setting::create(['key' => 'notify_hours', 'name' => 'Notification hours', 'value' => '96']);
        $result = $schedule->prepare();
        $this->assertSame(0, $result['seeded']);
        $this->assertSame(200, $result['rescheduled']);
        $this->assertSame(200, DB::table('order_checks')->where('notice_at', '<=', now())->count());
        Carbon::setTestNow(now()->addMinute());
        $schedule->prepare();
        $this->assertSame(400, DB::table('order_checks')->where('notice_at', '<=', now())->count());
    }

    public function test_unreachable_orders_cannot_starve_due_orders_and_are_parked(): void
    {
        DB::table('users')->insert(['id' => 2, 'tel_id' => null, 'status' => 1]);
        for ($i = 0; $i < 205; $i++) {
            $this->order(now()->addHours(12))->update(['user_id' => 2]);
        }
        $valid = $this->order(now()->addHours(12));
        $service = new IndexedNoticeFake(app(OrderLifecycleService::class));
        $this->assertSame(200, $service->sendDueNotifications()['checked']);
        $second = $service->sendDueNotifications();
        $this->assertSame(6, $second['checked']);
        $this->assertSame(1, $second['renewal_sent']);
        $this->assertStringContainsString('#'.$valid->id, $service->messages[0]['text']);
        $this->assertSame(0, $service->sendDueNotifications()['checked']);
    }

    public function test_state_transitions_process_only_due_rows_and_stop_at_two_hundred(): void
    {
        for ($i = 0; $i < 205; $i++) {
            $this->order(now()->subMinute());
        }
        $future = $this->order(now()->addDays(30));
        $lifecycle = app(OrderLifecycleService::class);
        $this->assertSame(200, $lifecycle->reconcileScheduledTimeStatuses());
        $this->assertSame(200, Orders::where('status', 'suspended')->count());
        $this->assertSame(5, $lifecycle->reconcileScheduledTimeStatuses());
        $this->assertSame('active', $future->fresh()->status);
        $this->assertSame(0, $lifecycle->reconcileScheduledTimeStatuses());
        Carbon::setTestNow(now()->addDays(7));
        $this->assertSame(200, $lifecycle->reconcileScheduledTimeStatuses());
        $this->assertSame(200, Orders::where('status', 'inactive')->count());
    }

    public function test_failed_cleanup_is_deferred_and_completed_cleanup_is_not_revisited(): void
    {
        $order = $this->order(now()->subDays(8));
        $lifecycle = new class extends OrderLifecycleService
        {
            public int $calls = 0;

            protected function disableRemoteOrder(Orders $order): bool
            {
                return ++$this->calls > 1;
            }
        };
        $this->assertSame(1, $lifecycle->synchronizeAll()['failed']);
        $this->assertSame(1, $lifecycle->calls);
        $lifecycle->synchronizeAll();
        $this->assertSame(1, $lifecycle->calls);
        Carbon::setTestNow(now()->addHour());
        $this->assertSame(1, $lifecycle->synchronizeAll()['disabled']);
        $this->assertNull(DB::table('order_checks')->where('order_id', $order->id)->value('cleanup_at'));
        $lifecycle->synchronizeAll();
        $this->assertSame(2, $lifecycle->calls);
    }

    public function test_renewal_and_provider_refresh_change_only_their_own_schedule(): void
    {
        $one = $this->order(now()->addHours(12));
        $two = $this->order(now()->addDays(30));
        $secondBefore = DB::table('order_checks')->where('order_id', $two->id)->first();
        $one->update(['expire_at' => now()->addDays(60)]);
        $this->assertSame(now()->addDays(59)->toDateTimeString(), DB::table('order_checks')->where('order_id', $one->id)->value('notice_at'));
        $detail = ['status' => true, 'data' => ['remote_available' => true, 'provider_status' => 'active', 'totalGb' => 20, 'totalUsed' => 19.5, 'left' => 0.5]];
        app(OrderLifecycleService::class)->applyConfigDetail($one, $detail);
        $this->assertSame(now()->toDateTimeString(), DB::table('order_checks')->where('order_id', $one->id)->value('notice_at'));
        $this->assertEquals($secondBefore, DB::table('order_checks')->where('order_id', $two->id)->first());
    }

    public function test_migration_can_be_rolled_back_without_deleting_orders(): void
    {
        $order = $this->order(now()->addDays(30));
        $migration = require database_path('migrations/2026_10_27_000000_create_order_checks_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('order_checks'));
        $this->assertNotNull(Orders::find($order->id));
        $migration->up();
        $this->assertTrue(Schema::hasTable('order_checks'));
    }

    public function test_cron_refuses_to_run_before_installation_and_the_migration_is_repeatable(): void
    {
        Schema::drop('order_checks');
        $this->artisan('orders:sync-lifecycle')
            ->expectsOutput('Run the order_checks migration before enabling the lifecycle cron.')
            ->assertExitCode(1);
        $options = ['--path' => 'database/migrations/2026_10_27_000000_create_order_checks_table.php', '--force' => true];
        $this->artisan('migrate', $options)->assertSuccessful();
        $this->artisan('migrate', $options)->assertSuccessful();
        $this->assertTrue(Schema::hasTable('order_checks'));
        Http::assertNothingSent();
    }

    private function order(Carbon $expire): Orders
    {
        return Orders::create(['user_id' => 1, 'status' => 'active', 'expire_at' => $expire, 'remark' => 'service']);
    }

    private function markInitialized(): void
    {
        $max = (int) Orders::max('id');
        Cache::forever('orders:checks:seed-id', $max);
        Cache::forever('orders:checks:settings', ['hash' => hash('sha256', json_encode(OrderNotificationSettings::load())), 'cursor' => $max, 'ceiling' => $max, 'interval' => 10]);
    }
}

class IndexedNoticeFake extends OrderLifecycleNotificationService
{
    public array $messages = [];

    protected function paceTelegram(string $chatId): void {}

    protected function sendTelegramMessage(array $params): array
    {
        $this->messages[] = $params;

        return ['ok' => true];
    }
}
