<?php

namespace App\Livewire\Admin\Pdv;

use App\Models\Branch;
use App\Models\Order;
use App\Models\PdvCashSession;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class Report extends Component
{
    use WithPagination;

    public string $dateStart = '';

    public string $dateEnd = '';

    public string $branchFilter = '';

    /** Caixa só vê a própria filial e não vê os totais financeiros (PDV, dinheiro, PIX, cartão, descontos). */
    public bool $isCaixa = false;

    public ?int $ownBranchId = null;

    public function mount(): void
    {
        $company = app()->bound('current.company') ? app('current.company') : null;
        $user = auth()->user();

        abort_unless($company, 403);
        abort_unless($company->pdv_module_enabled, 403, 'Módulo PDV não está habilitado para esta empresa.');
        abort_unless($user?->hasPermission('pdv.operate', $company), 403);

        $this->isCaixa = $user->roleForCompany($company) === 'caixa';
        $this->ownBranchId = $this->isCaixa ? $user->branchIdForCompany($company) : null;

        $this->dateStart = now()->startOfMonth()->format('Y-m-d');
        $this->dateEnd = now()->endOfMonth()->format('Y-m-d');
        $this->branchFilter = $this->isCaixa ? (string) $this->ownBranchId : '';
    }

    public function updatedBranchFilter(): void
    {
        if ($this->isCaixa) {
            $this->branchFilter = (string) $this->ownBranchId;
        }
    }

    private function effectiveBranchFilter(): string
    {
        return $this->isCaixa ? (string) $this->ownBranchId : $this->branchFilter;
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
            ->when($this->effectiveBranchFilter(), fn ($q) => $q->where('branch_id', $this->effectiveBranchFilter()))
            ->when($this->isCaixa, fn ($q) => $q->whereHas('pdvCashSession', fn ($sq) => $sq->where('user_id', auth()->id())));
    }

    public function render()
    {
        $allOrders = (clone $this->buildOrderQuery())->get(['total', 'payment_method', 'status', 'discount', 'manual_discount']);

        $activeOrders = $allOrders->whereNotIn('status', ['cancelled', 'refunded']);
        $totalRevenue = $activeOrders->sum('total');
        $cancelledCount = $allOrders->whereIn('status', ['cancelled', 'refunded'])->count();
        $totalDiscounts = $activeOrders->sum(fn ($o) => ($o->discount ?? 0) + ($o->manual_discount ?? 0));

        // Agrega por payments.payment_gateway (não orders.payment_method) — pedidos com pagamento
        // dividido (payment_method='split') têm cada parte contada no bucket certo.
        $paymentRows = DB::table('orders')
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->where('orders.order_type', 'pdv')
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->where('payments.status', 'paid')
            ->when($this->dateStart, fn ($q) => $q->whereDate('orders.created_at', '>=', $this->dateStart))
            ->when($this->dateEnd, fn ($q) => $q->whereDate('orders.created_at', '<=', $this->dateEnd))
            ->when($this->effectiveBranchFilter(), fn ($q) => $q->where('orders.branch_id', $this->effectiveBranchFilter()))
            ->when($this->isCaixa, fn ($q) => $q->join('pdv_cash_sessions', 'pdv_cash_sessions.id', '=', 'orders.pdv_cash_session_id')
                ->where('pdv_cash_sessions.user_id', auth()->id()))
            ->selectRaw('payments.payment_gateway, COALESCE(SUM(payments.amount), 0) as total')
            ->groupBy('payments.payment_gateway')
            ->get();

        $gatewayMap = ['cash' => 'cash', 'card_machine' => 'credit_card', 'pix_manual' => 'pix'];
        $paymentTotals = ['cash' => 0.0, 'pix' => 0.0, 'credit_card' => 0.0];

        foreach ($paymentRows as $row) {
            $key = $gatewayMap[$row->payment_gateway] ?? null;
            if ($key) {
                $paymentTotals[$key] += (float) $row->total;
            }
        }

        $cashTotal = $paymentTotals['cash'];
        $pixTotal = $paymentTotals['pix'];
        $cardTotal = $paymentTotals['credit_card'];

        $orders = (clone $this->buildOrderQuery())->latest()->paginate(25);

        $sessionsQuery = PdvCashSession::with(['branch', 'user'])
            ->when($this->effectiveBranchFilter(), fn ($q) => $q->where('branch_id', $this->effectiveBranchFilter()))
            ->when($this->isCaixa, fn ($q) => $q->where('user_id', auth()->id()))
            ->when($this->dateStart, fn ($q) => $q->whereDate('created_at', '>=', $this->dateStart))
            ->when($this->dateEnd, fn ($q) => $q->whereDate('created_at', '<=', $this->dateEnd))
            ->orderBy('created_at', 'desc');

        $sessions = $sessionsQuery->get();

        $company = app('current.company');
        $branches = Branch::where('company_id', $company->id)
            ->where('active', true)
            ->when($this->isCaixa, fn ($q) => $q->where('id', $this->ownBranchId))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('livewire.admin.pdv.report', compact(
            'orders', 'branches', 'sessions',
            'totalRevenue', 'cashTotal', 'pixTotal', 'cardTotal',
            'cancelledCount', 'totalDiscounts',
        ))->layout('layouts.app', ['title' => 'Relatório PDV']);
    }
}
