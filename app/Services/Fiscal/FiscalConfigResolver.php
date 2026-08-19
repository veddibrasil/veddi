<?php

namespace App\Services\Fiscal;

use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\FiscalNote;

class FiscalConfigResolver
{
    /**
     * Config fiscal da filial informada; sem config própria pra ela (ou sem filial
     * informada), cai na config marcada como padrão (matriz) da empresa. Manter
     * "no máximo 1 is_default por empresa" é responsabilidade de quem grava
     * CompanyFiscalConfig (Livewire\Admin\Fiscal\Config), não deste resolver.
     */
    public function forBranch(Company $company, ?int $branchId): ?CompanyFiscalConfig
    {
        if ($branchId) {
            $config = CompanyFiscalConfig::where('company_id', $company->id)
                ->where('branch_id', $branchId)
                ->first();

            if ($config) {
                return $config;
            }
        }

        return CompanyFiscalConfig::where('company_id', $company->id)
            ->where('is_default', true)
            ->first();
    }

    public function forNote(FiscalNote $note): ?CompanyFiscalConfig
    {
        return $this->forBranch($note->company, $note->branch_id);
    }
}
