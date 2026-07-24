<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['branch_id', 'is_open_tab']);
            $table->index(['pdv_cash_session_id', 'is_open_tab']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'is_open_tab']);
            $table->dropIndex(['pdv_cash_session_id', 'is_open_tab']);
        });
    }
};
