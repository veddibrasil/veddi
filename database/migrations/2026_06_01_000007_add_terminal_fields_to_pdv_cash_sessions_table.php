<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdv_cash_sessions', function (Blueprint $table) {
            $table->string('terminal_name')->nullable()->after('user_id');
            $table->text('reconciliation_notes')->nullable()->after('expected_amount');
        });
    }

    public function down(): void
    {
        Schema::table('pdv_cash_sessions', function (Blueprint $table) {
            $table->dropColumn(['terminal_name', 'reconciliation_notes']);
        });
    }
};
