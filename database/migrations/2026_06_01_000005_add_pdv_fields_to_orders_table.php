<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('manual_discount', 10, 2)->default(0)->after('discount');
            $table->foreignId('pdv_cash_session_id')
                ->nullable()
                ->constrained('pdv_cash_sessions')
                ->nullOnDelete()
                ->after('order_type');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pdv_cash_session_id');
            $table->dropColumn('manual_discount');
        });
    }
};
