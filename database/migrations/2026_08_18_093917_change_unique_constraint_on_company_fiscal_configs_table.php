<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MySQL recusa dropar um unique index enquanto ele for o único índice sustentando
     * a FK da mesma coluna — sempre cria um índice substituto com company_id como
     * coluna mais à esquerda antes de dropar o unique antigo, e remove o substituto
     * assim que o unique novo (que também cobre company_id como leftmost) já existe.
     */
    public function up(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->index('company_id', 'company_fiscal_configs_company_id_fk_tmp');
        });

        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->dropUnique(['company_id']);
            $table->unique(['company_id', 'branch_id']);
        });

        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->dropIndex('company_fiscal_configs_company_id_fk_tmp');
        });
    }

    public function down(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->index('company_id', 'company_fiscal_configs_company_id_fk_tmp');
        });

        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'branch_id']);
            $table->unique(['company_id']);
        });

        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->dropIndex('company_fiscal_configs_company_id_fk_tmp');
        });
    }
};
