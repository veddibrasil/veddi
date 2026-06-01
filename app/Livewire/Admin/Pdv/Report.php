<?php

namespace App\Livewire\Admin\Pdv;

use App\Models\Branch;
use App\Models\Order;
use App\Models\PdvCashSession;
use Livewire\Component;
use Livewire\WithPagination;

class Report extends Component
{
    use WithPagination;

    public string $dateStart = '';

    public string $dateEnd = '';

    public string $branchFilter = '';

    public function mount(): void
    {
        $company = app()->bound('current.company') ? app('current.company') : null;
        $user = auth()->user();

        abort_unless($company, 403);
        abort_unless($company->plan?->hasPdv(), 403, 'Seu plano não inclui acesso ao PDV.');
        abort_unless($user?->hasPermission('pdv.operate', $company), 403);

        $this->dateStart = now()->startOfMonth()->format('Y-m-d');
        $this->dateEnd = now()->endOfMonth()->format('Y-m-d');
    }

    public function updatingDateStart(): void
    {
        $this->resetPage();
    }

    public function updatingDateEnd(): void
    {
        $this->resetPage();
    }

    public function updatingBranchFilter(): void
    {
        $this->resetPage();
    }

    private function buildOrderQuery()
    {
        return Order::with(['customer', 'branch'])
            ->where('order_type', 'pdv')
            ->when($this->dateStart, fn ($q) => $q->whereDate('created_at', '>=', $this->dateStart))
            ->when($this->dateEnd, fn ($q) => $q->whereDate('created_at', '<=', $this->dateEnd))
            ->when($this->branchFilter, fn ($q) => $q->where('branch_id', $this->branchFilter));
    }

    public function render()
    {
        $allOrders = (clone $this->buildOrderQuery())->get(['total', 'payment_method', 'status']);

        $totalRevenue = $allOrders->whereNotIn('status', ['cancelled', 'refunded'])->sum('total');
        $cashTotal = $allOrders->whereNotIn('status', ['cancelled', 'refunded'])->where('payment_method', 'cash')->sum('total');
        $pixTotal = $allOrders->whereNotIn('status', ['cancelled', 'refunded'])->where('payment_method', 'pix')->sum('total');
        $cardTotal = $allOrders->whereNotIn('status', ['cancelled', 'refunded'])->where('payment_method', 'credit_card')->sum('total');

        $orders = (clone $this->buildOrderQuery())->latest()->paginate(25);

        $sessionsQuery = PdvCashSession::with(['branch', 'user'])
            ->when($this->branchFilter, fn ($q) => $q->where('branch_id', $this->branchFilter))
            ->when($this->dateStart, fn ($q) => $q->whereDate('created_at', '>=', $this->dateStart))
            ->when($this->dateEnd, fn ($q) => $q->whereDate('created_at', '<=', $this->dateEnd))
            ->orderBy('created_at', 'desc');

        $sessions = $sessionsQuery->get();

        $company = app('current.company');
        $branches = Branch::where('company_id', $company->id)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('livewire.admin.pdv.report', compact(
            'orders', 'branches', 'sessions',
            'totalRevenue', 'cashTotal', 'pixTotal', 'cardTotal',
        ))->layout('layouts.app', ['title' => 'Relatório PDV']);
    }
}
