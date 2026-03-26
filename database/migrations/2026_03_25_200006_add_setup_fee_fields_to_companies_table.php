<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('setup_fee_paid_at')->nullable()->after('asaas_subscription_id');
            $table->string('asaas_setup_charge_id')->nullable()->after('setup_fee_paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['setup_fee_paid_at', 'asaas_setup_charge_id']);
        });
    }
};
