<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\ProductCategory;
use App\Models\User;

class ProductCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('categories.view', $this->company());
    }

    public function view(User $user, ProductCategory $category): bool
    {
        return $user->hasPermission('categories.view', $this->company())
            && ($user->isSuperAdmin() || $category->company_id === $this->company()->id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('categories.create', $this->company());
    }

    public function update(User $user, ProductCategory $category): bool
    {
        return $user->hasPermission('categories.update', $this->company())
            && ($user->isSuperAdmin() || $category->company_id === $this->company()->id);
    }

    public function delete(User $user, ProductCategory $category): bool
    {
        return $user->hasPermission('categories.delete', $this->company())
            && ($user->isSuperAdmin() || $category->company_id === $this->company()->id);
    }

    private function company(): Company
    {
        return app('current.company');
    }
}
