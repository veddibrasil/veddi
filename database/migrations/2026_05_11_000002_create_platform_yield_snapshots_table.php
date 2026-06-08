<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_yield_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date')->unique();
            $table->decimal('vindi_balance', 14, 2);
            $table->decimal('asaas_balance', 14, 2);
            $table->decimal('total_float', 14, 2);
            $table->decimal('cdi_rate_annual', 8, 4);
            $table->decimal('cdi_rate_daily', 10, 8);
            $table->decimal('yield_amount', 10, 2);
            $table->decimal('cumulative_yield_month', 10, 2);
            $table->decimal('cumulative_yield_total', 12, 2);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_yield_snapshots');
    }
};
