<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emissão automática de NFC-e nasce ligada em filial nova. Filiais já existentes mantêm o valor
 * gravado (não há UPDATE aqui): a contabilidade decide ligar pela tela de Fiscal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->boolean('auto_issue_enabled')->default(true)->change();
        });
    }

    public function down(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->boolean('auto_issue_enabled')->default(false)->change();
        });
    }
};
