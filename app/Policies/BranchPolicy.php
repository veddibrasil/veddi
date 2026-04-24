<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('branches.view', $this->company());
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->hasPermission('branches.view', $this->company())
                    && $branch->company_id === $this->company()->id;

    }

    public function create(User $user, Branch $branch): bool
    {
        return $user->hasPermission('branches.create', $this->company());
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->hasPermission('branches.update', $this->company());
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->hasPermission('branches.delete', $this->company());
    }

    private function company(): Company
    {
        return app('current.company');
    }
}
