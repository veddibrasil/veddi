<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('plan', ['free', 'pro'])->default('free')->after('active');
            $table->enum('status', ['PENDING_PAYMENT', 'ACTIVE', 'BLOCKED'])->default('ACTIVE')->after('plan');
            $table->string('asaas_customer_id')->nullable()->after('status');
            $table->string('asaas_subscription_id')->nullable()->after('asaas_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['plan', 'status', 'asaas_customer_id', 'asaas_subscription_id']);
        });
    }
};
