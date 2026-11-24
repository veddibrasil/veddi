<?php

namespace App\Services\Company;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Facades\DB;

class UserPermissionService
{
    /**
     * Assign all permissions of a system role to a user within a company.
     * Creates explicit UserPermission records so the user doesn't depend
     * solely on role-based fallback lookup.
     */
    public static function assignRolePermissions(User $user, Company $company, string $roleSlug): void
    {
        $role = Role::where('slug', $roleSlug)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))
            ->with('permissions')
            ->first();

        if (! $role) {
            return;
        }

        $role->permissions->each(function ($permission) use ($user, $company) {
            UserPermission::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'company_id' => $company->id,
                    'permission_id' => $permission->id,
                ],
                ['granted' => true]
            );
        });

        User::clearPermissionCache($user->id, $company->id);
    }

    /**
     * Grava as permissões do papel e leva a mudança a quem já está nele.
     *
     * assignRolePermissions copia as permissões do papel para user_permissions (granted=true),
     * e em User::hasPermission a cópia vence o papel. Sem isto, tirar uma permissão do papel não
     * tirava de quem já estava nele, e o cache de 5 min atrasava até a que foi dada.
     *
     * Só as permissões que mudaram são tocadas, e só nos usuários deste papel:
     * - tirada do papel: sai a cópia (granted=true) de cada membro;
     * - dada ao papel: chega pelo próprio papel; um "negar" individual (granted=false) fica.
     *
     * @param  array<int, int>  $permissionIds
     * @return int quantos vínculos usuário-empresa têm este papel
     */
    public static function syncRolePermissions(Role $role, array $permissionIds): int
    {
        $memberships = DB::transaction(function () use ($role, $permissionIds) {
            $before = $role->permissions()->pluck('permissions.id')->all();
            $role->permissions()->sync($permissionIds);
            $removed = array_values(array_diff($before, $permissionIds));

            $memberships = $role->memberships();

            if ($removed !== []) {
                foreach ($memberships->groupBy('company_id') as $companyId => $members) {
                    UserPermission::where('company_id', $companyId)
                        ->whereIn('user_id', $members->pluck('user_id'))
                        ->whereIn('permission_id', $removed)
                        ->where('granted', true)
                        ->delete();
                }
            }

            return $memberships;
        });

        // Depois do commit: limpar antes deixava outra request regravar o cache com o papel antigo.
        foreach ($memberships as $membership) {
            User::clearPermissionCache((int) $membership->user_id, (int) $membership->company_id);
        }

        return $memberships->count();
    }

    /**
     * Explicitly grant pdv.operate to every company_admin of the given company.
     * Safe to call multiple times (updateOrCreate is idempotent).
     */
    public static function grantPdvPermissions(Company $company): void
    {
        $permission = Permission::where('name', 'pdv.operate')->first();

        if (! $permission) {
            return;
        }

        $company->users()
            ->wherePivot('role', 'company_admin')
            ->get()
            ->each(function (User $user) use ($company, $permission) {
                UserPermission::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'company_id' => $company->id,
                        'permission_id' => $permission->id,
                    ],
                    ['granted' => true]
                );

                User::clearPermissionCache($user->id, $company->id);
            });
    }

    /**
     * Revoke pdv.operate from every company_admin of the given company.
     * Safe to call multiple times (updateOrCreate is idempotent).
     */
    public static function revokePdvPermissions(Company $company): void
    {
        $permission = Permission::where('name', 'pdv.operate')->first();

        if (! $permission) {
            return;
        }

        $company->users()
            ->wherePivot('role', 'company_admin')
            ->get()
            ->each(function (User $user) use ($company, $permission) {
                UserPermission::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'company_id' => $company->id,
                        'permission_id' => $permission->id,
                    ],
                    ['granted' => false]
                );

                User::clearPermissionCache($user->id, $company->id);
            });
    }
}
