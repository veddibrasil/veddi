<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index('asaas_payment_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->index('asaas_customer_id');
            $table->index('asaas_subscription_id');
            $table->index('asaas_setup_charge_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['asaas_payment_id']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['asaas_customer_id']);
            $table->dropIndex(['asaas_subscription_id']);
            $table->dropIndex(['asaas_setup_charge_id']);
        });
    }
};
