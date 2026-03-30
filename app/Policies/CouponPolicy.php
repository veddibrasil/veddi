<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CouponPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('coupons.view', $this->company());
    }

    public function view(User $user, Coupon $coupon): bool
    {
        return $user->hasPermission('coupons.view', $this->company())
                    && $coupon->company_id === $this->company()->id;

    }

    public function create(User $user, Coupon $coupon): bool
    {
        return $user->hasPermission('coupons.create', $this->company());
    }

    public function update(User $user, Coupon $coupon): bool
    {
        return $user->hasPermission('coupons.update', $this->company());
    }

    public function delete(User $user, Coupon $coupon): bool
    {
        return $user->hasPermission('coupons.delete', $this->company());
    }

    private function company(): Company
    {
        return app('current.company');
    }
}
