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
        abort_unless(! $this->isWaiter, 403);
        $this->abortUnlessCanManageClosing();

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
        $this->viewingClosedSessionId = $sessionId;
    }

    public function backToClosingReportsList(): void
    {
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
