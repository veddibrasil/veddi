<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.view', $this->company());
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasPermission('users.view', $this->company());
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('users.manage', $this->company());
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasPermission('users.manage', $this->company());
    }

    public function delete(User $user, User $model): bool
    {
        return $user->hasPermission('users.manage', $this->company())
            && $user->id !== $model->id;
    }

    private function company(): Company
    {
        return app('current.company');
    }
}
