<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel')->default('chat')->after('order_type');
            $table->string('external_order_id')->nullable()->after('channel');
            $table->json('external_metadata')->nullable()->after('external_order_id');

            $table->index('external_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['external_order_id']);
            $table->dropColumn(['channel', 'external_order_id', 'external_metadata']);
        });
    }
};
