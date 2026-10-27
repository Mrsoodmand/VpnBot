<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Separate table: no rewrite or synchronous backfill of the orders table.
        Schema::create('order_checks', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->primary();
            $table->unsignedBigInteger('panel_id')->nullable();
            $table->dateTime('notice_at')->nullable();
            $table->dateTime('state_at')->nullable();
            $table->dateTime('provider_at')->nullable();
            $table->dateTime('cleanup_at')->nullable();
            $table->index(['notice_at', 'order_id'], 'order_checks_notice_due');
            $table->index(['state_at', 'order_id'], 'order_checks_state_due');
            $table->index(['cleanup_at', 'order_id'], 'order_checks_cleanup_due');
            $table->index(['panel_id', 'provider_at', 'order_id'], 'order_checks_provider_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_checks');
    }
};
