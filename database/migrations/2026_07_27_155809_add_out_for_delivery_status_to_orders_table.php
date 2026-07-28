<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', [
                'pending',
                'awaiting_payment',
                'paid',
                'preparing',
                'ready',
                'out_for_delivery',
                'delivered',
                'cancelled',
                'refunded',
                'scheduled',
            ])->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('orders')->where('status', 'out_for_delivery')->update(['status' => 'ready']);

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', [
                'pending',
                'awaiting_payment',
                'paid',
                'preparing',
                'ready',
                'delivered',
                'cancelled',
                'refunded',
                'scheduled',
            ])->default('pending')->change();
        });
    }
};
