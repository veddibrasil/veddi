<?php

namespace App\Livewire\Admin;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $company      = app()->bound('current.company') ? app('current.company') : null;
        $user         = auth()->user();
        $isSuperAdmin = $user?->isSuperAdmin();

        $can = fn(string $perm) => $isSuperAdmin || ($company && $user?->hasPermission($perm, $company));

        $canViewOrders   = $can('orders.view');
        $canViewBranches = $can('branches.view') && !($company && $user?->isBranchManager($company));
        $canViewProducts = $can('products.view');
        $canSettings     = $can('company.settings');

        $todayOrders   = collect();
        $todayRevenue  = 0;
        $pendingOrders = 0;
        $totalOrders   = 0;

        if ($canViewOrders) {
            $branchId = $company && $user?->isBranchManager($company)
                ? $user->branchIdForCompany($company)
                : null;

            $cacheKey = match (true) {
                $branchId !== null          => "dashboard:branch:{$branchId}",
                $company !== null           => "dashboard:company:{$company->id}",
                default                     => 'dashboard:global',
            };

            [$todayOrders, $todayRevenue, $pendingOrders, $totalOrders] = Cache::remember(
                $cacheKey,
                now()->addMinute(),
                function () use ($company, $branchId) {
                    $query = Order::query();

                    if ($branchId) {
                        $query->where('branch_id', $branchId);
                    } elseif ($company) {
                        $query->where('company_id', $company->id);
                    }

                    $todayOrders = (clone $query)->whereDate('created_at', today())->get();

                    return [
                        $todayOrders,
                        $todayOrders->whereIn('status', ['paid', 'preparing', 'ready', 'delivered'])->sum('total'),
                        (clone $query)->whereIn('status', ['paid', 'preparing'])->count(),
                        $query->count(),
                    ];
                }
            );
        }

        return view('livewire.admin.dashboard', compact(
            'todayOrders', 'todayRevenue', 'pendingOrders', 'totalOrders',
            'canViewOrders', 'canViewBranches', 'canViewProducts', 'canSettings'
        ))->layout('layouts.app', ['title' => 'Dashboard Admin']);
    }
}
