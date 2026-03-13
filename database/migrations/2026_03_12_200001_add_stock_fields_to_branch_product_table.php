<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_product', function (Blueprint $table) {
            $table->integer('quantity')->default(0)->after('available');
            $table->integer('min_quantity')->default(0)->after('quantity');
            $table->boolean('track_stock')->default(false)->after('min_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('branch_product', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'min_quantity', 'track_stock']);
        });
    }
};
