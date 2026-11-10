<?php

namespace App\Services\Fiscal;

use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\FiscalNote;

class FiscalConfigResolver
{
    /** Novas emissões exigem configuração da própria filial. */
    public function forBranch(Company $company, ?int $branchId): ?CompanyFiscalConfig
    {
        if ($branchId) {
            return CompanyFiscalConfig::where('company_id', $company->id)
                ->where('branch_id', $branchId)
                ->whereHas('branch', fn ($query) => $query->withoutGlobalScopes()->where('company_id', $company->id))
                ->first();
        }

        return null;
    }

    public function forNote(FiscalNote $note): ?CompanyFiscalConfig
    {
        $issuerCnpj = $note->issuer_cnpj;
        if (! $issuerCnpj && preg_match('/^(?:NFe)?([0-9]{44})$/', (string) $note->access_key, $matches)) {
            $keyCnpj = substr($matches[1], 6, 14);
            if (FiscalRecipient::isValidDocument($keyCnpj)) {
                $issuerCnpj = $keyCnpj;
            }
        }
        if ($note->fiscal_config_id) {
            $config = CompanyFiscalConfig::where('company_id', $note->company_id)->find($note->fiscal_config_id);
        } else {
            $config = $this->forBranch($note->company, $note->branch_id ?: $note->order?->branch_id);
        }

        // Notas antigas podem ter sido emitidas pelo padrão de outra filial. O CNPJ
        // persistido identifica o emissor original, mesmo depois de trocar o padrão.
        if ($issuerCnpj && ! $note->fiscal_config_id) {
            $config = CompanyFiscalConfig::where('company_id', $note->company_id)
                ->where('issuer_cnpj', $issuerCnpj)->first() ?? $config;
        }

        if ($config && $issuerCnpj) {
            $cnpj = $config->issuer_cnpj ?: preg_replace('/\D/', '', $config->branch?->cnpj ?: ($note->company->owner_cpf_cnpj ?? ''));
            if ($cnpj !== $issuerCnpj) {
                return null;
            }
        }

        return $config;
    }
}
