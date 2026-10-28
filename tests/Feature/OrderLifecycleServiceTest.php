<?php

namespace Tests\Feature;

use App\Models\Orders;
use App\Models\Panels;
use App\Services\OrderLifecycleService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderLifecycleServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_05_17_112220_create_settings_table.php'))->up();

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('status');
            $table->unsignedBigInteger('panel_id')->nullable();
            $table->string('uid')->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('expire_at')->nullable();
            $table->timestamps();
        });

        Schema::create('panels', function (Blueprint $table) {
            $table->id();
            $table->string('system_type');
            $table->string('url')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        Carbon::setTestNow(Carbon::parse('2026-08-18 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_expired_orders_are_suspended_for_seven_days_then_become_inactive(): void
    {
        $renewable = $this->createOrder(1, '1', now()->subDays(6)->subHours(23));
        $graceEnded = $this->createOrder(1, '1', now()->subDays(7));
        $future = $this->createOrder(1, '1', now()->addDay());
        $otherUser = $this->createOrder(2, '1', now()->subDays(8));

        $lifecycle = app(OrderLifecycleService::class);
        $lifecycle->reconcileTimeStatuses(1);

        $this->assertSame(Orders::STATUS_SUSPENDED, $renewable->fresh()->status);
        $this->assertTrue($lifecycle->canRenew($renewable->fresh()));
        $this->assertSame(Orders::STATUS_INACTIVE, $graceEnded->fresh()->status);
        $this->assertFalse($lifecycle->canRenew($graceEnded->fresh()));
        $this->assertSame(Orders::STATUS_ACTIVE, $lifecycle->statusMeta($future->fresh())['key']);
        $this->assertSame('1', $otherUser->fresh()->status);
    }

    public function test_orders_are_sorted_by_business_status_then_newest_id(): void
    {
        $inactive = $this->createOrder(1, Orders::STATUS_INACTIVE, now()->addDay());
        $suspended = $this->createOrder(1, Orders::STATUS_SUSPENDED, now()->subDay());
        $exhausted = $this->createOrder(1, Orders::STATUS_DATA_EXHAUSTED, now()->addDay());
        $activeOlder = $this->createOrder(1, Orders::STATUS_ACTIVE, now()->addDay());
        $activeNewer = $this->createOrder(1, Orders::STATUS_ACTIVE, now()->addDay());

        $lifecycle = app(OrderLifecycleService::class);
        $query = $lifecycle->orderByStatus(Orders::query())->orderByDesc('id');

        $this->assertSame('🟢', $lifecycle->statusMeta($activeOlder)['icon']);
        $this->assertSame('🟠', $lifecycle->statusMeta($exhausted)['icon']);
        $this->assertSame('🔵', $lifecycle->statusMeta($suspended)['icon']);
        $this->assertSame('🔴', $lifecycle->statusMeta($inactive)['icon']);
        $this->assertTrue($lifecycle->canBuyExtra($exhausted));
        $this->assertFalse($lifecycle->canBuyExtra($suspended));

        $this->assertSame([
            $activeNewer->id,
            $activeOlder->id,
            $exhausted->id,
            $suspended->id,
            $inactive->id,
        ], $query->pluck('id')->all());
    }

    public function test_opening_an_expired_legacy_inactive_order_restores_renewal_status(): void
    {
        $order = $this->createOrder(1, Orders::STATUS_INACTIVE, now()->subDay());
        $lifecycle = app(OrderLifecycleService::class);

        $lifecycle->applyConfigDetail($order, [
            'status' => true,
            'data' => [
                'remote_available' => true,
                'provider_status' => 'expired',
                'expire' => now()->subDay()->toISOString(),
                'totalGb' => 40,
                'totalUsed' => 36.37,
                'left' => 3.63,
            ],
        ]);

        $this->assertSame(Orders::STATUS_SUSPENDED, $order->fresh()->status);
        $this->assertTrue($lifecycle->canRenew($order->fresh()));
    }

    public function test_order_list_refreshes_pasarguard_once_and_skips_cancelled_orders(): void
    {
        $panelId = DB::table('panels')->insertGetId([
            'system_type' => 'pasarguard',
            'url' => 'https://panel.test',
            'username' => 'admin',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $panel = Panels::findOrFail($panelId);

        $cancelledPanelId = DB::table('panels')->insertGetId([
            'system_type' => 'pasarguard',
            'url' => 'https://cancelled-panel.test',
            'username' => 'admin',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $active = $this->createOrder(1, Orders::STATUS_ACTIVE, now()->addDay(), $panel->id, 'pg-active');
        $exhausted = $this->createOrder(1, Orders::STATUS_ACTIVE, now()->addDay(), $panel->id, 'pg-exhausted');
        $disabled = $this->createOrder(1, Orders::STATUS_ACTIVE, now()->addDay(), $panel->id, 'pg-disabled');
        $expired = $this->createOrder(1, Orders::STATUS_INACTIVE, now()->subDay(), $panel->id, 'pg-expired');
        $cancelled = $this->createOrder(1, Orders::STATUS_INACTIVE, now()->addDay(), $panel->id, 'pg-cancelled');
        $cancelledOnItsOwnPanel = $this->createOrder(
            1,
            Orders::STATUS_INACTIVE,
            now()->addDay(),
            $cancelledPanelId,
            'pg-cancelled-only'
        );
        foreach ([$cancelled, $cancelledOnItsOwnPanel] as $cancelledOrder) {
            $cancelledOrder->detail = [
                'lifecycle' => [
                    'remote_disabled' => true,
                    'cancelled_at' => now()->subHour()->toDateTimeString(),
                    'provider_status' => 'disabled',
                ],
            ];
            $cancelledOrder->save();
        }

        $lifecycle = new class extends OrderLifecycleService {
            public int $requests = 0;

            protected function fetchPasarguardUsers(Panels $panel): mixed
            {
                $this->requests++;

                return ['users' => [
                    [
                        'id' => 'pg-active',
                        'status' => 'active',
                        'data_limit' => 10_000,
                        'used_traffic' => 1_000,
                    ],
                    [
                        'id' => 'pg-exhausted',
                        'status' => 'active',
                        'data_limit' => 10_000,
                        'used_traffic' => 10_000,
                    ],
                    [
                        'id' => 'pg-disabled',
                        'status' => 'disabled',
                        'data_limit' => 10_000,
                        'used_traffic' => 1_000,
                    ],
                    [
                        'id' => 'pg-expired',
                        'status' => 'expired',
                        'expire' => now()->subDay()->toISOString(),
                        'data_limit' => 10_000,
                        'used_traffic' => 9_000,
                    ],
                    // If cancelled orders were not filtered, this would reactivate it.
                    [
                        'id' => 'pg-cancelled',
                        'status' => 'active',
                        'data_limit' => 10_000,
                        'used_traffic' => 0,
                    ],
                ]];
            }
        };

        $result = $lifecycle->refreshPasarguardListStatuses(1);

        $this->assertSame(1, $lifecycle->requests);
        $this->assertSame(1, $result['requests']);
        $this->assertSame(4, $result['checked']);
        $this->assertSame(3, $result['updated']);
        $this->assertSame(Orders::STATUS_ACTIVE, $active->fresh()->status);
        $this->assertSame(Orders::STATUS_DATA_EXHAUSTED, $exhausted->fresh()->status);
        $this->assertSame(Orders::STATUS_INACTIVE, $disabled->fresh()->status);
        $this->assertSame(Orders::STATUS_SUSPENDED, $expired->fresh()->status);
        $this->assertTrue($lifecycle->canRenew($expired->fresh()));
        $this->assertSame(Orders::STATUS_INACTIVE, $cancelled->fresh()->status);
        $this->assertSame(Orders::STATUS_INACTIVE, $cancelledOnItsOwnPanel->fresh()->status);
        $this->assertEquals(10000 / (1024 ** 3), $active->fresh()->detail['lifecycle']['total_gb']);
    }

    public function test_on_hold_snapshots_clear_estimated_expiry_and_switch_to_real_expiry_after_connection(): void
    {
        $lifecycle = app(OrderLifecycleService::class);
        foreach ([null, 0, '0', now()->subDays(30)->toISOString()] as $expiry) {
            $order = $this->createOrder(1, Orders::STATUS_INACTIVE, now()->subDays(30));
            $lifecycle->applyPasarguardSnapshot($order, ['status' => 'on_hold', 'expire' => $expiry, 'data_limit' => 70 * 1024 ** 3, 'used_traffic' => 0]);
            $order->refresh();
            $this->assertSame(Orders::STATUS_ON_HOLD, $order->status);
            $this->assertNull($order->expire_at);
            $this->assertSame('در انتظار اتصال', $lifecycle->statusMeta($order)['label']);
            $this->assertSame('بعد از اولین اتصال نمایش داده می‌شود', $lifecycle->expiryLabel($order));
            $this->assertTrue($lifecycle->canBuyExtra($order));
            $realExpiry = now()->addDays(30);
            $lifecycle->applyPasarguardSnapshot($order, ['status' => 'active', 'expire' => $realExpiry->timestamp, 'data_limit' => 70 * 1024 ** 3, 'used_traffic' => 100]);
            $order->refresh();
            $this->assertSame(Orders::STATUS_ACTIVE, $lifecycle->statusMeta($order)['key']);
            $this->assertTrue($order->expire_at->equalTo($realExpiry));
            $this->assertStringNotContainsString('اولین اتصال', $lifecycle->expiryLabel($order));
        }
    }

    public function test_local_time_reconciliation_never_expires_known_waiting_orders_or_revives_cancellations(): void
    {
        $lifecycle = app(OrderLifecycleService::class);
        $waiting = $this->createOrder(1, 'on_hold', now()->subDays(40));
        $legacy = $this->createOrder(1, 'suspended', now()->subDays(40));
        $legacy->update(['detail' => ['lifecycle' => ['provider_status' => 'on_hold']]]);
        $cancelled = $this->createOrder(1, 'inactive', now()->subDays(40));
        $cancelled->update(['detail' => ['lifecycle' => ['provider_status' => 'on_hold', 'remote_disabled' => true]]]);
        $lifecycle->reconcileTimeStatuses(1);
        $this->assertSame('on_hold', $waiting->fresh()->status);
        $this->assertSame('suspended', $legacy->fresh()->status);
        foreach ([$waiting, $legacy] as $order) {
            $this->assertSame('on_hold', $lifecycle->statusMeta($order->fresh())['key']);
            $this->assertNull($order->fresh()->expire_at);
            $row = DB::table('order_checks')->where('order_id', $order->id)->first();
            $this->assertNull($row->state_at);
            $this->assertNull($row->cleanup_at);
            $this->assertNull($row->notice_at);
        }
        $this->assertSame('inactive', $lifecycle->statusMeta($cancelled->fresh())['key']);
        $lifecycle->applyPasarguardSnapshot($cancelled->fresh(), ['status' => 'on_hold', 'expire' => null]);
        $this->assertSame('inactive', $cancelled->fresh()->status);
    }

    public function test_detail_refresh_distinguishes_waiting_unlimited_limited_expired_and_disabled(): void
    {
        $lifecycle = app(OrderLifecycleService::class);
        foreach ([
            ['on_hold', 70, 0, 70, null, 'on_hold'],
            ['active', 0, 12, 0, null, 'active'],
            ['limited', 70, 70, 0, now()->addDay()->toISOString(), 'data_exhausted'],
            ['active', 70, 70, 0, now()->addDay()->toISOString(), 'data_exhausted'],
            ['expired', 70, 0, 70, now()->subDay()->toISOString(), 'suspended'],
            ['expired', 70, 0, 70, now()->addDay()->toISOString(), 'suspended'],
            ['disabled', 70, 0, 70, null, 'inactive'],
        ] as [$status, $total, $used, $left, $expiry, $expected]) {
            $order = $this->createOrder(1, 'active', now()->subDays(30));
            $lifecycle->applyConfigDetail($order, ['status' => true, 'data' => [
                'remote_available' => true, 'provider_status' => $status, 'expire' => $expiry,
                'totalGb' => $total, 'totalUsed' => $used, 'left' => $left,
            ]]);
            $this->assertSame($expected, $lifecycle->statusMeta($order->fresh())['key'], $status);
        }
    }

    public function test_absent_or_invalid_expiry_does_not_invent_a_date(): void
    {
        $lifecycle = app(OrderLifecycleService::class);
        $date = now()->addDays(30);
        foreach ([[], ['expire' => 'invalid date'], ['expire' => ''], ['expire' => []]] as $remote) {
            $this->assertEquals($date, $lifecycle->providerExpiry($remote, $date));
        }
        foreach ([null, 0, '0'] as $value) $this->assertNull($lifecycle->providerExpiry(['expire' => $value], $date));
        $order = $this->createOrder(1, 'active', $date);
        $order->update(['expire_at' => null]);
        $this->assertSame('تاریخ پایان تعیین نشده', $lifecycle->expiryLabel($order));
    }

    public function test_expiry_migration_preserves_existing_dates_and_allows_waiting_orders(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->timestamp('expire_at')->nullable(false)->change());
        $order = $this->createOrder(1, 'active', now()->addDays(30));
        $before = $order->getAttributes();
        $migration = require database_path('migrations/2026_10_28_000000_allow_pending_order_expiry.php');
        $migration->up();
        $this->assertSame($before, $order->fresh()->getAttributes());
        $order->update(['expire_at' => null, 'status' => 'on_hold']);
        $migration->up();
        $this->assertNull($order->fresh()->expire_at);
        $this->assertSame('on_hold', $order->fresh()->status);
    }

    private function createOrder(
        int $userId,
        string $status,
        Carbon $expireAt,
        ?int $panelId = null,
        ?string $uid = null
    ): Orders
    {
        $id = DB::table('orders')->insertGetId([
            'user_id' => $userId,
            'status' => $status,
            'panel_id' => $panelId,
            'uid' => $uid,
            'detail' => '{}',
            'expire_at' => $expireAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Orders::findOrFail($id);
    }
}
