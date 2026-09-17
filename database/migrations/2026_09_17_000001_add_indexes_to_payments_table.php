<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index('vindi_transaction_token');
            $table->index(['payment_gateway', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['vindi_transaction_token']);
            $table->dropIndex(['payment_gateway', 'status', 'expires_at']);
        });
    }
};
