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
