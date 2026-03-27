<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('subscription_payment_method', 20)->default('PIX')->after('asaas_setup_charge_id');
            $table->text('asaas_setup_invoice_url')->nullable()->after('subscription_payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['subscription_payment_method', 'asaas_setup_invoice_url']);
        });
    }
};
