<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('owner_cpf_cnpj', 20)->nullable()->after('asaas_customer_id');
            $table->text('asaas_setup_pix_qr_code')->nullable()->after('asaas_setup_invoice_url');
            $table->text('asaas_setup_pix_copy_paste')->nullable()->after('asaas_setup_pix_qr_code');
            $table->text('asaas_setup_bank_slip_url')->nullable()->after('asaas_setup_pix_copy_paste');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'owner_cpf_cnpj',
                'asaas_setup_pix_qr_code',
                'asaas_setup_pix_copy_paste',
                'asaas_setup_bank_slip_url',
            ]);
        });
    }
};
