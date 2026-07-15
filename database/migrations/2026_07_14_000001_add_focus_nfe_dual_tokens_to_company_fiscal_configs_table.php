<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->text('token_producao')->nullable()->after('provider_token');
            $table->text('token_homologacao')->nullable()->after('token_producao');
            $table->string('focus_nfe_company_id')->nullable()->after('token_homologacao');
            $table->timestamp('focus_nfe_registered_at')->nullable()->after('focus_nfe_company_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->dropColumn([
                'token_producao',
                'token_homologacao',
                'focus_nfe_company_id',
                'focus_nfe_registered_at',
            ]);
        });
    }
};
