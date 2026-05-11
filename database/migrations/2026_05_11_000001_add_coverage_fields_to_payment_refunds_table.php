<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->string('coverage_status', 50)->nullable()->after('processed_at');
            $table->json('coverage_meta')->nullable()->after('coverage_status');
        });
    }

    public function down(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->dropColumn(['coverage_status', 'coverage_meta']);
        });
    }
};
