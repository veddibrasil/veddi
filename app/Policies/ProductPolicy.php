<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('products.view', $this->company());
    }

    public function view(User $user, Product $product): bool
    {
        return $user->hasPermission('products.view', $this->company())
            && ($user->isSuperAdmin() || $product->company_id === $this->company()->id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('products.create', $this->company());
    }

    public function update(User $user, Product $product): bool
    {
        return $user->hasPermission('products.update', $this->company())
            && ($user->isSuperAdmin() || $product->company_id === $this->company()->id);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->hasPermission('products.delete', $this->company())
            && ($user->isSuperAdmin() || $product->company_id === $this->company()->id);
    }

    private function company(): Company
    {
        return app('current.company');
    }
}
