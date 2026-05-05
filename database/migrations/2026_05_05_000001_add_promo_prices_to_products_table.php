<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('promo_price_enabled')->default(false)->after('price');
            $table->enum('promo_price_type', ['fixed', 'percentage'])->default('fixed')->after('promo_price_enabled');
            $table->decimal('promo_price_value', 8, 2)->nullable()->after('promo_price_type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['promo_price_enabled', 'promo_price_type', 'promo_price_value']);
        });
    }
};
