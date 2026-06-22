<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'favicon_path',
                'default_payout_type',
                'default_pix_key',
                'default_pix_key_type',
                'default_bank_code',
                'default_bank_agency',
                'default_bank_account',
                'default_bank_account_digit',
                'default_bank_account_type',
                'default_bank_owner_cpf_cnpj',
                'default_bank_owner_name',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('favicon_path')->nullable();
            $table->string('default_payout_type')->nullable();
            $table->string('default_pix_key')->nullable();
            $table->string('default_pix_key_type')->nullable();
            $table->string('default_bank_code')->nullable();
            $table->string('default_bank_agency')->nullable();
            $table->string('default_bank_account')->nullable();
            $table->string('default_bank_account_digit')->nullable();
            $table->string('default_bank_account_type')->nullable();
            $table->string('default_bank_owner_cpf_cnpj')->nullable();
            $table->string('default_bank_owner_name')->nullable();
        });
    }
};
