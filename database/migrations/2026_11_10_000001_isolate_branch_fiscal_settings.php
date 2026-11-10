<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->string('legal_name')->nullable();
            $table->string('issuer_cnpj', 14)->nullable();
            // Null preserves the automatic environment used before this migration.
            $table->string('emission_environment', 20)->nullable();
        });

        Schema::table('branch_product', function (Blueprint $table) {
            $table->json('fiscal_data')->nullable();
        });

        DB::table('company_fiscal_configs')->orderBy('id')->chunkById(200, function ($configs) {
            foreach ($configs as $config) {
                $company = DB::table('companies')->where('id', $config->company_id)->first();
                $branch = DB::table('branches')->where('company_id', $config->company_id)->where('id', $config->branch_id)->first();
                $cnpj = preg_replace('/\D/', '', $branch?->cnpj ?: ($company?->owner_cpf_cnpj ?? ''));
                DB::table('company_fiscal_configs')->where('id', $config->id)->update([
                    'legal_name' => $company?->name,
                    'issuer_cnpj' => strlen($cnpj) === 14 ? $cnpj : null,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('branch_product', fn (Blueprint $table) => $table->dropColumn('fiscal_data'));
        Schema::table('company_fiscal_configs', fn (Blueprint $table) => $table->dropColumn(['legal_name', 'issuer_cnpj', 'emission_environment']));
    }
};
