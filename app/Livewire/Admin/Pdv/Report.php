<?php

namespace App\Livewire\Admin\Pdv;

use App\Models\Branch;
use App\Models\Order;
use App\Models\PdvCashSession;
use App\Services\Pdv\CashClosingReportService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Report extends Component
{
    use WithPagination;

    public string $dateStart = '';

    public string $dateEnd = '';

    public string $branchFilter = '';

    /** Caixa só vê a própria filial e não vê os totais financeiros (PDV, dinheiro, PIX, cartão, descontos). */
    #[Locked]
    public bool $isCaixa = false;

    #[Locked]
    public ?int $ownBranchId = null;

    /** Caixa aberto cujo andamento o administrador está vendo (modal). Nunca para o caixa. */
    #[Locked]
    public ?int $liveSessionId = null;

    public bool $showLiveSession = false;

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

    public function viewLiveSession(int $sessionId): void
    {
        abort_if($this->isCaixa, 403);

        $this->liveSessionId = $sessionId;
        $this->showLiveSession = true;
        unset($this->liveSessionReport);
    }

    public function closeLiveSession(): void
    {
        $this->showLiveSession = false;
        $this->liveSessionId = null;
    }

    /**
     * O que um caixa ainda aberto já gerou (mesmos números do relatório de fechamento, até
     * agora). Só para quem não é caixa: o fechamento do caixa é cego. A sessão passa pelo
     * escopo global da empresa (BelongsToCompany).
     */
    #[Computed]
    public function liveSessionReport(): ?array
    {
        if ($this->isCaixa || ! $this->liveSessionId) {
            return null;
        }

        $session = PdvCashSession::with(['branch', 'user'])
            ->whereNull('closed_at')
            ->find($this->liveSessionId);

        return $session ? app(CashClosingReportService::class)->build($session) : null;
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
        return Order::with(['customer', 'branch', 'attendant'])
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
        // Consulta via DB::table não passa pelo global scope de Order, então o filtro
        // de company_id precisa ser explícito aqui pra não vazar dados de outra empresa.
        $paymentRows = DB::table('orders')
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->where('orders.company_id', app('current.company')->id)
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
            // Caixa aberto aparece sempre, qualquer que seja o período: o filtro é pela data de
            // abertura e um turno aberto há semanas sumia da tabela (era justamente o que o
            // administrador precisava ver). Abertos primeiro.
            ->when($this->dateStart || $this->dateEnd, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('closed_at')
                ->orWhere(fn ($period) => $period
                    ->when($this->dateStart, fn ($p) => $p->whereDate('created_at', '>=', $this->dateStart))
                    ->when($this->dateEnd, fn ($p) => $p->whereDate('created_at', '<=', $this->dateEnd)))))
            ->orderByRaw('CASE WHEN closed_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('created_at', 'desc');

        $sessions = $sessionsQuery->get();

        // Esperado parcial dos caixas ainda abertos (só administrador; o caixa fecha às cegas).
        $liveExpected = $this->isCaixa
            ? collect()
            : $sessions->filter(fn (PdvCashSession $session) => $session->isOpen())
                ->mapWithKeys(fn (PdvCashSession $session) => [
                    $session->id => app(CashClosingReportService::class)->breakdown($session)['expected'],
                ]);

        $company = app('current.company');
        $branches = Branch::where('company_id', $company->id)
            ->where('active', true)
            ->when($this->isCaixa, fn ($q) => $q->where('id', $this->ownBranchId))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('livewire.admin.pdv.report', compact(
            'orders', 'branches', 'sessions', 'liveExpected',
            'totalRevenue', 'cashTotal', 'pixTotal', 'cardTotal',
            'cancelledCount', 'totalDiscounts',
        ))->layout('layouts.app', ['title' => 'Relatório PDV']);
    }
}
