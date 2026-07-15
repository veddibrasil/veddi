<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->string('inscricao_municipal')->nullable()->after('inscricao_estadual');
            $table->text('csc_nfce_producao')->nullable()->after('focus_nfe_registered_at');
            $table->string('id_token_nfce_producao')->nullable()->after('csc_nfce_producao');
        });
    }

    public function down(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->dropColumn([
                'inscricao_municipal',
                'csc_nfce_producao',
                'id_token_nfce_producao',
            ]);
        });
    }
};
