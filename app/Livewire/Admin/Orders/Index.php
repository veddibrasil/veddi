<?php

namespace App\Livewire\Admin\Orders;

use App\Models\Order;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $statusFilter = '';
    public string $search       = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $orders = Order::with(['customer', 'branch'])
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search, fn ($q) => $q
                ->where('order_number', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$this->search}%"))
            )
            ->latest()
            ->paginate(20);

        return view('livewire.admin.orders.index', compact('orders'))
            ->layout('layouts.app', ['title' => 'Pedidos']);
    }
}
