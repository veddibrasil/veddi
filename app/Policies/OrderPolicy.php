<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('orders.view', $this->company());
    }

    public function view(User $user, Order $order): bool
    {
        return $user->hasPermission('orders.view', $this->company())
            && $order->company_id === $this->company()->id;
    }

    public function update(User $user, Order $order): bool
    {
        return $user->hasPermission('orders.update', $this->company())
            && $order->company_id === $this->company()->id;
    }

    private function company(): Company
    {
        return app('current.company');
    }
}
