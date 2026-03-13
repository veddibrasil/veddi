<?php

namespace App\Livewire\Admin;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $company  = app()->bound('current.company') ? app('current.company') : null;
        $cacheKey = $company ? "dashboard:company:{$company->id}" : 'dashboard:global';

        [$todayOrders, $todayRevenue, $pendingOrders, $totalOrders] = Cache::remember(
            $cacheKey,
            now()->addMinute(),
            function () {
                $todayOrders = Order::whereDate('created_at', today())->get();
                return [
                    $todayOrders,
                    $todayOrders->whereIn('status', ['paid', 'preparing', 'ready', 'delivered'])->sum('total'),
                    Order::whereIn('status', ['paid', 'preparing'])->count(),
                    Order::count(),
                ];
            }
        );

        return view('livewire.admin.dashboard', compact('todayOrders', 'todayRevenue', 'pendingOrders', 'totalOrders'))
            ->layout('layouts.app', ['title' => 'Dashboard Admin']);
    }
}
