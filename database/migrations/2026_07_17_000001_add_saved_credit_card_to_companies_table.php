<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('asaas_credit_card_token')->nullable()->after('asaas_setup_bank_slip_url');
            $table->string('asaas_credit_card_last_four', 4)->nullable()->after('asaas_credit_card_token');
            $table->string('asaas_credit_card_brand')->nullable()->after('asaas_credit_card_last_four');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['asaas_credit_card_token', 'asaas_credit_card_last_four', 'asaas_credit_card_brand']);
        });
    }
};
