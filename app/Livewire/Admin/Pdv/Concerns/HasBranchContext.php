<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;

trait HasBranchContext
{
    /**
     * Filial fixa do usuário > última filial escolhida nesta sessão > primeira
     * filial ativa da empresa (fallback). Sem o passo de sessão, um operador sem
     * filial fixa (ex.: company_admin cobrindo mais de uma filial) sempre caía na
     * filial de menor id a cada recarga da página, mesmo tendo selecionado outra
     * manualmente antes — o que fazia a taxa de entrega ser calculada contra as
     * coordenadas da filial errada.
     */
    private function resolveInitialBranch(Company $company, User $user): ?Branch
    {
        $userBranchId = $user->branchIdForCompany($company);

        if ($userBranchId) {
            $branch = Branch::where('company_id', $company->id)->where('active', true)->find($userBranchId);
            if ($branch) {
                return $branch;
            }
        }

        $sessionBranchId = session("pdv.branch_id.{$company->id}");
        if ($sessionBranchId) {
            $branch = Branch::where('company_id', $company->id)->where('active', true)->find($sessionBranchId);
            if ($branch) {
                return $branch;
            }
        }

        return Branch::where('company_id', $company->id)
            ->where('active', true)
            ->orderBy('id')
            ->first();
    }

    private function rememberSelectedBranch(int $companyId): void
    {
        session(["pdv.branch_id.{$companyId}" => $this->selectedBranchId]);
    }
}
