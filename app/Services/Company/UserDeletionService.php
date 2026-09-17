<?php

namespace App\Services\Company;

use App\Models\Company;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Facades\DB;

class UserDeletionService
{
    /**
     * Exclui o usuário de toda a base: remove vínculo com todas as empresas,
     * limpa permissões e sessões ativas, e faz soft delete do registro.
     *
     * Uso restrito a super admin — um company_admin nunca deve poder apagar
     * um usuário de outra empresa da plataforma inteira (ver removeFromCompany).
     */
    public static function delete(User $user): void
    {
        $companyIds = $user->companies()->pluck('companies.id');

        $user->companies()->detach();

        UserPermission::where('user_id', $user->id)->delete();

        DB::table('sessions')->where('user_id', $user->id)->delete();

        foreach ($companyIds as $companyId) {
            User::clearPermissionCache($user->id, $companyId);
        }

        $user->delete();
    }

    /**
     * Remove o vínculo do usuário apenas com a empresa informada. Se essa era
     * a última empresa do usuário, aí sim finaliza a conta (mesmo efeito de
     * delete(), mas nunca afeta vínculos com outras empresas que o ator não
     * administra).
     */
    public static function removeFromCompany(User $user, Company $company): void
    {
        $user->companies()->detach($company->id);

        UserPermission::where('user_id', $user->id)
            ->where('company_id', $company->id)
            ->delete();

        User::clearPermissionCache($user->id, $company->id);

        if ($user->companies()->count() === 0) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();
        }
    }
}
