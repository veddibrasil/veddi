<?php

use App\Models\Branch;
use App\Models\CompanyFiscalConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Toda config existente até aqui é a única da empresa (unique(company_id) ainda
     * vigente) — vira a config default da empresa, associada à primeira filial.
     * Empresa sem filial nenhuma não deveria existir (onboarding sempre cria uma);
     * se acontecer, a config fica órfã (branch_id null) e é ignorada pelo resolver.
     */
    public function up(): void
    {
        CompanyFiscalConfig::withoutGlobalScopes()
            ->whereNull('branch_id')
            ->each(function (CompanyFiscalConfig $config) {
                $branch = Branch::withoutGlobalScopes()
                    ->where('company_id', $config->company_id)
                    ->orderBy('id')
                    ->first();

                if (! $branch) {
                    Log::channel('fiscal')->warning('Backfill fiscal config: empresa sem filial', [
                        'company_id' => $config->company_id,
                        'company_fiscal_config_id' => $config->id,
                    ]);

                    return;
                }

                $config->forceFill([
                    'branch_id' => $branch->id,
                    'is_default' => true,
                ])->save();
            });
    }

    /**
     * Data migration — não reversível com segurança (não há como distinguir
     * branch_id/is_default preenchidos por este backfill dos preenchidos depois
     * pela aplicação).
     */
    public function down(): void {}
};
