<?php

namespace App\Livewire\Admin;

use App\Models\Order;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $todayOrders   = Order::whereDate('created_at', today())->get();
        $todayRevenue  = $todayOrders->whereIn('status', ['paid', 'preparing', 'ready', 'delivered'])->sum('total');
        $pendingOrders = Order::whereIn('status', ['paid', 'preparing'])->count();
        $totalOrders   = Order::count();

        return view('livewire.admin.dashboard', compact('todayOrders', 'todayRevenue', 'pendingOrders', 'totalOrders'))
            ->layout('layouts.app', ['title' => 'Dashboard Admin']);
    }
}
