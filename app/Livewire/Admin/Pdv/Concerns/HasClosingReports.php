<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\PdvCashSession;
use App\Services\Payment\PaymentOrchestrator;
use App\Services\Pdv\CashClosingReportService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

trait HasClosingReports
{
    public function showSessionHistory(): void
    {
        abort_unless(! $this->isWaiter, 403);

        $this->showSessionHistory = true;
        $this->closeCancelOrderModal();
    }

    public function backFromSessionHistory(): void
    {
        $this->showSessionHistory = false;
        $this->closeCancelOrderModal();
    }

    /**
     * Confirma na hora, dentro do próprio Terminal, o pagamento de um pedido "aguardando
     * pagamento" (receber na entrega) da sessão atual — mesma regra usada em
     * Orders/Show::confirmPayment(): só pedido do PDV, só se ainda não existe Payment. Sem
     * isso o operador via "Pedidos da sessão" (que hoje mostra esses pedidos como "Pago" por
     * engano) nunca sabia que faltava confirmar, e o pedido ficava contando no TOTAL VENDAS
     * do fechamento sem nenhum pagamento registrado.
     */
    public function confirmSessionOrderPayment(int $orderId): void
    {
        abort_unless(! $this->isWaiter, 403);

        $order = Order::withoutGlobalScopes()
            ->where('pdv_cash_session_id', $this->cashSessionId)
            ->find($orderId);

        if (! $order || ! $order->needsPdvPaymentConfirmation()) {
            return;
        }

        app(PaymentOrchestrator::class)->confirmDeliveryPayment($order);

        OrderStatusUpdated::dispatch($order->fresh());
    }

    public function openClosingReports(): void
    {
        abort_unless(! $this->isWaiter && ! $this->isCaixa, 403);

        $this->showClosingReports = true;
        $this->viewingClosedSessionId = null;
    }

    public function backFromClosingReports(): void
    {
        $this->showClosingReports = false;
        $this->viewingClosedSessionId = null;
    }

    public function viewClosedSession(int $sessionId): void
    {
        // Caixa só vê o detalhe da própria sessão, aberto direto por closeCashSession() —
        // nunca navega pra cá escolhendo um id de outro operador.
        abort_unless(! $this->isCaixa, 403);

        $this->viewingClosedSessionId = $sessionId;
    }

    public function backToClosingReportsList(): void
    {
        // Caixa não tem acesso à listagem de fechamentos de todos os operadores da filial —
        // o "voltar" da tela de detalhe (aberta só logo após ele fechar o próprio caixa) sai
        // do overlay inteiro em vez de cair na lista.
        if ($this->isCaixa) {
            $this->backFromClosingReports();

            return;
        }

        $this->viewingClosedSessionId = null;
    }

    public function closedSessionStats(PdvCashSession $session): array
    {
        $orderStats = $this->sessionOrderStats($session->id);

        return [
            'duration' => $session->created_at->diffForHumans($session->closed_at ?? now(), true),
            'orders' => $orderStats['orders'],
            'revenue' => $orderStats['revenue'],
            'terminal' => $session->terminal_name ?? '',
            'operator' => $session->user?->name ?? '',
        ];
    }

    #[Computed]
    public function closedSessions(): Collection
    {
        // Caixa não tem a listagem de fechamentos dos outros operadores (ver openClosingReports()).
        if (! $this->selectedBranchId || $this->isCaixa) {
            return collect();
        }

        return PdvCashSession::with('user')
            ->where('branch_id', $this->selectedBranchId)
            ->whereNotNull('closed_at')
            ->latest('closed_at')
            ->limit(30)
            ->get();
    }

    /**
     * Caixas ainda abertos em todas as filiais que o usuário opera (de qualquer operador,
     * inclusive o próprio), sem limite de data: um turno esquecido aberto há semanas é
     * justamente o que o administrador precisa ver. Caixa nunca vê: o fechamento dele é cego
     * (ver _cash-close).
     */
    #[Computed]
    public function openSessions(): Collection
    {
        if ($this->isCaixa) {
            return collect();
        }

        $branchIds = $this->branches->pluck('id');

        if ($branchIds->isEmpty()) {
            return collect();
        }

        return PdvCashSession::with(['user', 'branch'])
            ->whereIn('branch_id', $branchIds)
            ->whereNull('closed_at')
            ->latest()
            ->get();
    }

    #[Computed]
    public function viewingClosedSession(): ?PdvCashSession
    {
        if (! $this->viewingClosedSessionId || ! $this->selectedBranchId) {
            return null;
        }

        // Caixa: só a própria sessão da filial e só depois de fechada — o andamento de caixa
        // aberto é só do administrador. Administrador: fechamento da filial selecionada (mesmo
        // escopo da listagem) ou caixa aberto de qualquer filial que ele opera (openSessions).
        if ($this->isCaixa) {
            return PdvCashSession::with(['user', 'branch'])
                ->where('branch_id', $this->selectedBranchId)
                ->where('user_id', auth()->id())
                ->whereNotNull('closed_at')
                ->find($this->viewingClosedSessionId);
        }

        $branchIds = $this->branches->pluck('id');

        return PdvCashSession::with(['user', 'branch'])
            ->where(fn ($q) => $q->where('branch_id', $this->selectedBranchId)
                ->orWhere(fn ($open) => $open->whereNull('closed_at')->whereIn('branch_id', $branchIds)))
            ->find($this->viewingClosedSessionId);
    }

    /** Andamento de um caixa aberto (mesmos números do relatório de fechamento, até agora). */
    public function liveSessionReport(PdvCashSession $session): array
    {
        abort_if($this->isCaixa || $session->closed_at, 403);

        return app(CashClosingReportService::class)->build($session);
    }
}
