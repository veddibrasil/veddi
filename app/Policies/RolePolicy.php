<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('roles.manage', $this->company());
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.manage', $this->company());
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('roles.manage', $this->company());
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.manage', $this->company());
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.manage', $this->company())
            && ! $role->is_system;
    }

    private function company(): Company
    {
        return app('current.company');
    }
}
