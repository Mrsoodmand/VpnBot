<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('expire_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Never invent expiry dates just to satisfy the old NOT NULL constraint.
        if (DB::table('orders')->whereNull('expire_at')->exists()) {
            throw new RuntimeException('Orders without an expiry exist; keep expire_at nullable when rolling back the source.');
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('expire_at')->nullable(false)->change();
        });
    }
};
