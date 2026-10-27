<?php

namespace Tests\Feature;

use App\Lib\PasarGuard;
use App\Models\Orders;
use App\Models\Panels;
use App\Models\Setting;
use App\Services\OrderLifecycleNotificationService;
use App\Services\OrderLifecycleService;
use App\Services\PasarGuardLifecycleSyncService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PasarGuardLifecycleSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-27 12:00:00');
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('panel_id');
            $t->unsignedBigInteger('user_id')->default(1);
            $t->string('uid');
            $t->string('status')->default('active');
            $t->json('detail')->nullable();
            $t->timestamp('expire_at')->nullable();
            $t->timestamps();
        });
        Schema::create('panels', function (Blueprint $t) {
            $t->id();
            $t->string('system_type');
            $t->string('url');
            $t->string('username');
            $t->string('password');
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key');
            $t->string('value')->nullable();
            $t->timestamps();
        });
        DB::table('panels')->insert(['id' => 1, 'system_type' => 'pasarguard', 'url' => 'https://panel.test', 'username' => 'admin', 'password' => 'test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_checks_1001_orders_in_six_group_requests_and_resumes_after_the_budget(): void
    {
        for ($i = 1; $i <= 1001; $i++) {
            $this->order((string) $i);
        }
        $sync = new FakeLifecycleBatch;
        $lifecycle = app(OrderLifecycleService::class);
        $first = $sync->synchronize($lifecycle);
        $this->assertSame(5, $first['requests']);
        $this->assertSame(1000, $first['checked']);
        $this->assertNull(Orders::find(1001)->detail);
        $second = $sync->synchronize($lifecycle);
        $this->assertSame(1, $second['requests']);
        $this->assertSame(1, $second['checked']);
        $this->assertSame(['1001'], $sync->calls[5]['ids']);
        $this->assertSame(0, $sync->synchronize($lifecycle)['requests']);
        $this->assertEquals(0.75, Orders::first()->detail['lifecycle']['left_gb']);
        Carbon::setTestNow(now()->addMinutes(10));
        $this->assertSame(5, $sync->synchronize($lifecycle)['requests']);
    }

    public function test_failure_keeps_cursor_and_data_and_backs_off_without_individual_requests(): void
    {
        $order = $this->order('42', ['lifecycle' => ['total_gb' => 20, 'used_gb' => 10, 'left_gb' => 10, 'last_checked_at' => now()->subHour()->toDateTimeString()]]);
        $before = $order->detail;
        $sync = new FakeLifecycleBatch;
        $sync->response = ['status' => false];
        $lifecycle = app(OrderLifecycleService::class);
        $this->assertSame(1, $sync->synchronize($lifecycle)['failed']);
        $this->assertSame($before, $order->fresh()->detail);
        $this->assertSame(0, $sync->synchronize($lifecycle)['requests']);
        Carbon::setTestNow(now()->addMinutes(2));
        $sync->response = null;
        $this->assertSame(1, $sync->synchronize($lifecycle)['checked']);
        $this->assertSame(['42'], $sync->calls[1]['ids']);
        Http::assertNothingSent();
    }

    public function test_incomplete_or_unfiltered_response_is_not_applied(): void
    {
        $order = $this->order('42');
        $sync = new FakeLifecycleBatch;
        $sync->response = ['users' => [], 'total' => 500];
        $this->assertSame(1, $sync->synchronize(app(OrderLifecycleService::class))['failed']);
        $this->assertNull($order->fresh()->detail);
        Carbon::setTestNow(now()->addMinutes(2));
        $sync->response = ['users' => [['id' => 999, 'status' => 'active', 'data_limit' => 20, 'used_traffic' => 10]], 'total' => 1];
        $this->assertSame(1, $sync->synchronize(app(OrderLifecycleService::class))['failed']);
        $this->assertNull($order->fresh()->detail);
    }

    public function test_panels_are_isolated_and_cancelled_and_dedicated_orders_are_skipped(): void
    {
        DB::table('panels')->insert(['id' => 2, 'system_type' => 'pasarguard', 'url' => 'https://other.test', 'username' => 'admin', 'password' => 'test']);
        $one = $this->order('42');
        $two = $this->order('42', [], 2);
        $this->order('43', ['lifecycle' => ['remote_disabled' => true]]);
        $this->order('44', ['service_type' => 'dedicated']);
        $sync = new FakeLifecycleBatch;
        $result = $sync->synchronize(app(OrderLifecycleService::class));
        $this->assertSame(2, $result['requests']);
        $this->assertSame(2, $result['checked']);
        $this->assertSame([['panel' => 1, 'ids' => ['42']], ['panel' => 2, 'ids' => ['42']]], $sync->calls);
        $this->assertNotNull($one->fresh()->detail['lifecycle']['last_checked_at']);
        $this->assertNotNull($two->fresh()->detail['lifecycle']['last_checked_at']);
    }

    public function test_snapshot_preserves_claims_and_tracks_reset_and_unlimited_expiry(): void
    {
        $order = $this->order('42', ['keep' => 'value', 'lifecycle' => ['used_gb' => 19, 'notifications' => ['low_volume' => ['state' => 'sent', 'fingerprint' => 'old']]]]);
        $order->update(['expire_at' => now()->addDay()]);
        $lifecycle = app(OrderLifecycleService::class);
        $snapshot = ['id' => 42, 'status' => 'active', 'data_limit' => 20 * 1024 ** 3, 'used_traffic' => 1024 ** 3, 'expire' => null];
        $lifecycle->applyPasarguardSnapshot($order, $snapshot);
        $fresh = $order->fresh();
        $this->assertNull($fresh->expire_at);
        $this->assertSame('value', $fresh->detail['keep']);
        $this->assertSame('sent', $fresh->detail['lifecycle']['notifications']['low_volume']['state']);
        $this->assertSame(1, $fresh->detail['lifecycle']['usage_cycle']);
        $lifecycle->applyPasarguardSnapshot($fresh, $snapshot);
        $this->assertSame(1, $fresh->fresh()->detail['lifecycle']['usage_cycle']);
    }

    public function test_scan_interval_comes_from_admin_and_does_not_poll_early(): void
    {
        Setting::create(['key' => 'notify_interval', 'value' => '30']);
        $this->order('42');
        $sync = new FakeLifecycleBatch;
        $lifecycle = app(OrderLifecycleService::class);
        $sync->synchronize($lifecycle);
        Carbon::setTestNow(now()->addMinutes(29));
        $this->assertSame(0, $sync->synchronize($lifecycle)['requests']);
        Carbon::setTestNow(now()->addMinute());
        $this->assertSame(1, $sync->synchronize($lifecycle)['requests']);
    }

    public function test_an_in_flight_batch_cannot_undo_a_completed_renewal(): void
    {
        $stale = $this->order('42');
        $renewed = $stale->fresh();
        $renewed->update(['expire_at' => now()->addDays(60), 'detail' => ['lifecycle' => ['total_gb' => 50]]]);
        app(OrderLifecycleService::class)->applyPasarguardSnapshot($stale, [
            'id' => 42, 'status' => 'active', 'data_limit' => 20 * 1024 ** 3,
            'used_traffic' => 19.5 * 1024 ** 3, 'expire' => now()->addDays(30)->toISOString(),
        ]);
        $this->assertEquals(now()->addDays(60), $renewed->fresh()->expire_at);
        $this->assertSame(50, $renewed->fresh()->detail['lifecycle']['total_gb']);
    }

    public function test_api_encodes_repeated_ids_and_never_downloads_configs(): void
    {
        Cache::put('pasarguard-token-1', 'test-token');
        Http::fake(['https://panel.test/api/users*' => Http::response(['users' => [], 'total' => 0])]);
        $api = new PasarGuard(['id' => 1, 'url' => 'https://panel.test', 'username' => 'admin', 'password' => 'test']);
        $api->getUsersByIds(['12', '13']);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), 'ids=12&ids=13') && str_contains($r->url(), 'load_sub=false') && str_contains($r->url(), 'limit=2'));
        Http::assertSentCount(1);
        $this->assertFalse($api->getUsersByIds([])['status']);
        $this->assertFalse($api->getUsersByIds(['invalid'])['status']);
        Http::assertSentCount(1);
    }

    public function test_scheduler_sync_uses_only_the_group_endpoint_for_pasarguard(): void
    {
        $this->order('42');
        Cache::put('pasarguard-token-1', 'test-token');
        Http::fake(['https://panel.test/api/users*' => Http::response([
            'users' => [['id' => 42, 'status' => 'active', 'data_limit' => 20 * 1024 ** 3, 'used_traffic' => 19.5 * 1024 ** 3]],
            'total' => 1,
        ])]);
        $result = app(OrderLifecycleService::class)->synchronizeAll();
        $this->assertSame(1, $result['requests']);
        $this->assertSame(1, $result['checked']);
        $this->assertEquals(0.5, Orders::first()->detail['lifecycle']['left_gb']);
        $this->assertSame(0, app(OrderLifecycleService::class)->synchronizeAll()['requests']);
        Http::assertSentCount(1);
    }

    public function test_console_worker_saves_health_report_and_skips_overlapping_invocations(): void
    {
        $this->mock(OrderLifecycleService::class)->shouldReceive('synchronizeAll')->once()->andReturn(['checked' => 200, 'failed' => 0, 'requests' => 1]);
        $this->mock(OrderLifecycleNotificationService::class)->shouldReceive('sendDueNotifications')->once()->andReturn(['renewal_sent' => 2, 'volume_sent' => 3, 'failed' => 0]);
        $lock = Cache::lock('orders:lifecycle:runner', 900);
        $lock->get();
        $this->artisan('orders:sync-lifecycle')->expectsOutput('Lifecycle worker is already running.')->assertSuccessful();
        $this->assertNull(Cache::get('orders:lifecycle:last-run'));
        $lock->release();
        $this->artisan('orders:sync-lifecycle')->assertSuccessful();
        $this->assertSame(200, Cache::get('orders:lifecycle:last-run')['synchronization']['checked']);
        $this->assertSame(3, Cache::get('orders:lifecycle:last-run')['notifications']['volume_sent']);
        $this->assertTrue(Cache::lock('orders:lifecycle:runner', 900)->get());
    }

    private function order(string $uid, ?array $detail = null, int $panelId = 1): Orders
    {
        return Orders::create(['panel_id' => $panelId, 'uid' => $uid, 'status' => 'active', 'detail' => $detail, 'expire_at' => now()->addDays(30)]);
    }
}

class FakeLifecycleBatch extends PasarGuardLifecycleSyncService
{
    public array $calls = [];

    public ?array $response = null;

    protected function fetch(Panels $panel, array $ids): mixed
    {
        $this->calls[] = ['panel' => $panel->id, 'ids' => $ids];

        return $this->response ?? ['users' => array_map(fn ($id) => [
            'id' => (int) $id, 'status' => 'active', 'data_limit' => 20 * 1024 ** 3,
            'used_traffic' => 19.25 * 1024 ** 3, 'expire' => now()->addDays(30)->toISOString(),
        ], $ids), 'total' => count($ids)];
    }
}
