<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('cash_received', 10, 2)->nullable()->after('net_value');
            $table->decimal('cash_change', 10, 2)->nullable()->after('cash_received');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cash_received', 'cash_change']);
        });
    }
};
