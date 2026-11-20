<?php

namespace App\Services\Company;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Vincula um usuário existente às empresas informadas com um papel de administração.
 * Operação do super admin (suporte): ignora a regra de /admin/users que recusa vincular quem já tem
 * outra empresa. Vínculos já existentes são mantidos como estão (papel e filial não mudam).
 */
final class CompanyAccessGranter
{
    public const ROLES = ['company_admin', 'branch_manager'];

    /** @param  Collection<int, Company>  $companies  @return Collection<int, Company> empresas que ganharam vínculo agora */
    public static function grant(User $user, Collection $companies, string $role = 'company_admin'): Collection
    {
        abort_unless(in_array($role, self::ROLES, true), 422, 'Papel inválido.');

        $linked = $user->companies()->pluck('companies.id')->all();
        $added = collect();

        foreach ($companies as $company) {
            if (in_array($company->id, $linked, true)) {
                continue;
            }

            $user->companies()->attach($company->id, ['role' => $role, 'branch_id' => null]);
            UserPermissionService::assignRolePermissions($user, $company, $role);
            $added->push($company);
        }

        return $added;
    }
}
