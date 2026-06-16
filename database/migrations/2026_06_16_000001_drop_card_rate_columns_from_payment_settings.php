<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->dropColumn([
                'card_rate_1x',
                'anticipation_rate_d2',
                'anticipation_rate_d7',
                'anticipation_rate_d15',
                'anticipation_rate_d30',
                'system_fee_rate',
                'default_anticipation_days',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->decimal('card_rate_1x', 5, 4)->default(0.0299);
            $table->decimal('anticipation_rate_d2', 5, 4)->default(0.0299);
            $table->decimal('anticipation_rate_d7', 5, 4)->default(0.0249);
            $table->decimal('anticipation_rate_d15', 5, 4)->default(0.0199);
            $table->decimal('anticipation_rate_d30', 5, 4)->default(0.0000);
            $table->decimal('system_fee_rate', 5, 4)->default(0.0000);
            $table->unsignedTinyInteger('default_anticipation_days')->default(15);
        });
    }
};
