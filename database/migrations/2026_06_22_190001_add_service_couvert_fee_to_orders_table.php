<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('service_fee', 8, 2)->default(0)->after('manual_discount');
            $table->decimal('couvert_fee', 8, 2)->default(0)->after('service_fee');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['service_fee', 'couvert_fee']);
        });
    }
};
