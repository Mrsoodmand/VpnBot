<?php

namespace Tests\Feature;

use App\Models\Orders;
use App\Services\OrderLifecycleService;
use App\Services\WpSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DedicatedOrderBotPolicyTest extends TestCase
{
    public function test_existing_dedicated_imports_are_hidden_and_cannot_be_managed(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->json('detail')->nullable();
            $table->timestamps();
        });
        $native = Orders::create(['detail' => null]);
        $shared = Orders::create(['detail' => ['source' => 'wordpress', 'raw' => ['service_type' => 'shared']]]);
        $legacyShared = Orders::create(['detail' => ['source' => 'wordpress', 'raw' => []]]);
        $dedicated = Orders::create(['detail' => ['source' => 'wordpress', 'raw' => ['service_type' => 'dedicated']]]);
        $direct = Orders::create(['detail' => ['service_type' => 'dedicated']]);

        $this->assertSame([$native->id, $shared->id, $legacyShared->id], Orders::botManaged()->orderBy('id')->pluck('id')->all());
        $this->assertNull(Orders::botManaged()->find($dedicated->id));
        $this->assertNull(Orders::botManaged()->find($direct->id));
        $lifecycle = app(OrderLifecycleService::class);
        $this->assertFalse($lifecycle->canRenew($dedicated));
        $this->assertFalse($lifecycle->canBuyExtra($dedicated));
        $this->assertFalse($shared->isSiteManagedDedicated());
        $this->assertFalse($native->isSiteManagedDedicated());
        $this->assertSame(5, Orders::count()); // No customer data is deleted.
    }

    public function test_dedicated_payloads_are_rejected_before_database_or_panel_access(): void
    {
        $this->assertNull(app(WpSyncService::class)->importSiteOrder(['site_order_id' => 42, 'service_type' => 'dedicated']));
    }
}
