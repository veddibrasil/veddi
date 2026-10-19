<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NF-e deixou de ser opcional por filial: destinatário com CNPJ sempre emite NF-e (modelo 55).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->boolean('nfe_enabled')->default(true)->change();
        });

        DB::table('company_fiscal_configs')->update(['nfe_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->boolean('nfe_enabled')->default(false)->change();
        });
    }
};
