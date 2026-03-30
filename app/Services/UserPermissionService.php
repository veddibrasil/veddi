<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;

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
                    'user_id'       => $user->id,
                    'company_id'    => $company->id,
                    'permission_id' => $permission->id,
                ],
                ['granted' => true]
            );
        });

        User::clearPermissionCache($user->id, $company->id);
    }
}
