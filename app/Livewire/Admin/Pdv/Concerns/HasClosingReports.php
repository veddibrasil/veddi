<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\PdvCashSession;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

trait HasClosingReports
{
    public function showSessionHistory(): void
    {
        abort_unless(! $this->isWaiter, 403);

        $this->showSessionHistory = true;
        $this->confirmingCancelSessionOrderId = null;
    }

    public function backFromSessionHistory(): void
    {
        $this->showSessionHistory = false;
        $this->confirmingCancelSessionOrderId = null;
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
            'duration' => $session->closed_at ? $session->created_at->diffForHumans($session->closed_at, true) : '',
            'orders' => $orderStats['orders'],
            'revenue' => $orderStats['revenue'],
            'terminal' => $session->terminal_name ?? '',
            'operator' => $session->user?->name ?? '',
        ];
    }

    #[Computed]
    public function closedSessions(): Collection
    {
        if (! $this->selectedBranchId) {
            return collect();
        }

        return PdvCashSession::with('user')
            ->where('branch_id', $this->selectedBranchId)
            ->whereNotNull('closed_at')
            ->latest('closed_at')
            ->limit(30)
            ->get();
    }

    #[Computed]
    public function viewingClosedSession(): ?PdvCashSession
    {
        if (! $this->viewingClosedSessionId) {
            return null;
        }

        return PdvCashSession::with('user')->find($this->viewingClosedSessionId);
    }
}
