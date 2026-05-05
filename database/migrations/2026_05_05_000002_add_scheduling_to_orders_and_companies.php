<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('notes');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('schedule_min_advance_minutes')->nullable()->after('card_fee_absorbed_by_company');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('scheduled_at');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('schedule_min_advance_minutes');
        });
    }
};
