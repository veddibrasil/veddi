<?php

namespace App\Livewire\Admin\Orders;

use App\Models\Company;
use App\Models\Order;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $statusFilter  = '';
    public string $search        = '';
    public string $companyFilter = '';

    public bool $isSuperAdmin = false;

    public function mount(): void
    {
        $this->isSuperAdmin = auth()->user()->isSuperAdmin();
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatusFilter(): void { $this->resetPage(); }
    public function updatingCompanyFilter(): void { $this->resetPage(); }

    public function render()
    {
        $query = $this->isSuperAdmin
            ? Order::withoutGlobalScope(CompanyScope::class)->with(['customer', 'branch', 'company'])
            : Order::with(['customer', 'branch']);

        $orders = $query
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search, fn ($q) => $q
                ->where('order_number', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$this->search}%"))
            )
            ->when($this->isSuperAdmin && $this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter))
            ->latest()
            ->paginate(20);

        $companies = $this->isSuperAdmin
            ? Cache::remember('companies:active', now()->addHours(24), fn () =>
                Company::withoutGlobalScope(CompanyScope::class)
                    ->where('active', true)
                    ->orderBy('name')
                    ->get()
              )
            : collect();

        return view('livewire.admin.orders.index', compact('orders', 'companies'))
            ->layout('layouts.app', ['title' => 'Pedidos']);
    }
}
