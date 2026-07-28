<?php

namespace App\Services\Company;

use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Facades\DB;

class UserDeletionService
{
    /**
     * Exclui o usuário de toda a base: remove vínculo com todas as empresas,
     * limpa permissões e sessões ativas, e faz soft delete do registro.
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
}
