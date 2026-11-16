<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Política de emissão por filial (FiscalEmissionPolicy):
 * - company_fiscal_configs.nfce_cnpj_enabled: libera NFC-e com CNPJ na filial, depois de a
 *   contabilidade aprovar a matriz e a homologação passar. Desligado mantém CNPJ = NF-e.
 * - orders.fiscal_document_preference: escolha explícita do operador ('nfce' | 'nfe');
 *   null = automático pela política.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->boolean('nfce_cnpj_enabled')->default(false)->after('nfe_serie');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('fiscal_document_preference', 10)->nullable()->after('fiscal_recipient');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('fiscal_document_preference');
        });

        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->dropColumn('nfce_cnpj_enabled');
        });
    }
};
