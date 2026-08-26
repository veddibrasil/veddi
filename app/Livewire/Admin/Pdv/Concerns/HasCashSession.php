<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\Order;
use App\Models\PdvCashSession;
use App\Services\Pdv\CashClosingReportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

trait HasCashSession
{
    /** Fechar caixa é restrito a administrador da empresa e gerente de filial. */
    private function abortUnlessCanManageClosing(): void
    {
        $company = app()->bound('current.company') ? app('current.company') : null;

        abort_unless($company && auth()->user()->canManageClosing($company), 403);
    }

    private function syncCashSession(): void
    {
        if (! $this->selectedBranchId) {
            return;
        }

        $company = app()->bound('current.company') ? app('current.company') : null;

        if (! $company) {
            return;
        }

        // Each user has their own session per branch (multi-terminal support)
        $session = PdvCashSession::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('branch_id', $this->selectedBranchId)
            ->where('user_id', auth()->id())
            ->whereNull('closed_at')
            ->latest()
            ->first();

        if ($session) {
            $this->cashSessionId = $session->id;
            if ($this->step === 'open_cash') {
                $this->step = 'catalog';
            }
        } else {
            $this->cashSessionId = null;
            $this->step = 'open_cash';
        }
    }

    public function openCashSession(): void
    {
        abort_unless(! $this->isWaiter, 403);

        $amount = (float) str_replace(',', '.', $this->openingAmountInput ?: '0');
        $company = app('current.company');
        $terminalName = trim($this->terminalName) ?: null;

        $session = PdvCashSession::create([
            'company_id' => $company->id,
            'branch_id' => $this->selectedBranchId,
            'user_id' => auth()->id(),
            'terminal_name' => $terminalName,
            'opening_amount' => $amount,
        ]);

        $this->cashSessionId = $session->id;
        $this->openingAmountInput = '';
        $this->step = 'catalog';

        $this->audit('cash_opened', [
            'amount' => $amount,
            'reason' => $terminalName,
        ]);
    }

    public function proceedToCloseCash(): void
    {
        abort_unless(! $this->isWaiter, 403);
        $this->abortUnlessCanManageClosing();

        $this->closingAmountInput = '';
        $this->reconciliationNotes = '';
        $this->showCashMovementForm = false;
        $this->step = 'close_cash';
    }

    public function toggleCashMovementForm(string $type = 'supply'): void
    {
        abort_unless(! $this->isWaiter, 403);

        $this->showCashMovementForm = ! $this->showCashMovementForm || $this->cashMovementType !== $type;
        $this->cashMovementType = in_array($type, ['supply', 'withdrawal'], true) ? $type : 'supply';
        $this->cashMovementAmountInput = '';
        $this->cashMovementReason = '';
        $this->resetValidation(['cash_movement_amount', 'cash_movement_reason']);
    }

    public function registerCashMovement(): void
    {
        abort_unless(! $this->isWaiter, 403);

        if (! $this->cashSessionId || ! $this->selectedBranchId) {
            return;
        }

        $this->resetValidation(['cash_movement_amount', 'cash_movement_reason']);

        $amount = (float) str_replace(',', '.', $this->cashMovementAmountInput ?: '0');
        $reason = trim($this->cashMovementReason);

        if ($amount <= 0) {
            $this->addError('cash_movement_amount', 'Informe um valor maior que zero.');

            return;
        }

        if (blank($reason)) {
            $this->addError('cash_movement_reason', 'Informe o motivo da movimentação.');

            return;
        }

        $this->audit($this->cashMovementType === 'withdrawal' ? 'cash_withdrawal' : 'cash_supply', [
            'amount' => $amount,
            'reason' => $reason,
        ]);

        $this->cashMovementAmountInput = '';
        $this->cashMovementReason = '';
        $this->showCashMovementForm = false;
    }

    public function closeCashSession(): void
    {
        abort_unless(! $this->isWaiter, 403);
        $this->abortUnlessCanManageClosing();

        if (! $this->cashSessionId) {
            $this->step = 'catalog';

            return;
        }

        $session = PdvCashSession::find($this->cashSessionId);

        if (! $session || $session->closed_at) {
            $this->cashSessionId = null;
            $this->step = 'open_cash';

            return;
        }

        if (blank($this->closingAmountInput)) {
            $this->addError('closingAmountInput', 'Informe o valor contado no caixa.');

            return;
        }

        $expected = $this->cashSessionExpected($session);
        $closing = (float) str_replace(',', '.', $this->closingAmountInput);
        $diff = round($closing - $expected, 2);

        // Require reconciliation notes if discrepancy > R$5
        if (abs($diff) > 5.0 && blank($this->reconciliationNotes)) {
            $this->addError('reconciliation_notes', 'Diferença acima de R$5,00 — informe o motivo.');

            return;
        }

        $session->update([
            'closing_amount' => $closing,
            'expected_amount' => $expected,
            'reconciliation_notes' => blank($this->reconciliationNotes) ? null : trim($this->reconciliationNotes),
            'closed_at' => now(),
        ]);

        $this->audit('cash_closed', [
            'amount' => $closing,
            'reason' => blank($this->reconciliationNotes) ? null : trim($this->reconciliationNotes),
            'metadata' => [
                'expected_amount' => $expected,
                'difference' => $diff,
            ],
        ]);

        $this->cashSessionId = null;
        $this->closingAmountInput = '';
        $this->reconciliationNotes = '';
        $this->cart = [];
        $this->step = 'open_cash';

        $this->showClosingReports = true;
        $this->viewingClosedSessionId = $session->id;
    }

    public function cancelCloseCash(): void
    {
        $this->step = 'catalog';
    }

    #[Computed]
    public function cashSession(): ?PdvCashSession
    {
        if (! $this->cashSessionId) {
            return null;
        }

        return PdvCashSession::find($this->cashSessionId);
    }

    #[Computed]
    public function shiftStats(): array
    {
        if (! $this->cashSessionId) {
            return ['duration' => '', 'orders' => 0, 'revenue' => 0.0, 'terminal' => '', 'operator' => ''];
        }

        $session = $this->cashSession;
        if (! $session) {
            return ['duration' => '', 'orders' => 0, 'revenue' => 0.0, 'terminal' => '', 'operator' => ''];
        }

        $orderStats = $this->sessionOrderStats($session->id);

        return [
            'duration' => $session->created_at->diffForHumans(now(), true),
            'orders' => $orderStats['orders'],
            'revenue' => $orderStats['revenue'],
            'terminal' => $session->terminal_name ?? '',
            'operator' => auth()->user()?->name ?? '',
        ];
    }

    private function sessionOrderStats(int $sessionId): array
    {
        $stats = DB::table('orders')
            ->where('pdv_cash_session_id', $sessionId)
            ->where('is_open_tab', false)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->selectRaw('COUNT(*) as total_orders, COALESCE(SUM(total), 0) as total_revenue')
            ->first();

        return [
            'orders' => (int) ($stats->total_orders ?? 0),
            'revenue' => (float) ($stats->total_revenue ?? 0.0),
        ];
    }

    #[Computed]
    public function sessionOrders(): Collection
    {
        if (! $this->cashSessionId) {
            return collect();
        }

        return Order::withoutGlobalScopes()
            ->where('pdv_cash_session_id', $this->cashSessionId)
            ->where('is_open_tab', false)
            ->with('customer')
            ->latest()
            ->limit(50)
            ->get(['id', 'order_number', 'total', 'payment_method', 'status', 'created_at', 'customer_id', 'discount', 'manual_discount']);
    }

    public function cashSessionExpected(PdvCashSession $session): float
    {
        return $this->cashSessionBreakdown($session)['expected'];
    }

    /** Delega pro CashClosingReportService — mesma query usada no relatório de fechamento, sem duplicar aqui. */
    public function cashSessionBreakdown(?PdvCashSession $session = null): array
    {
        $session ??= $this->cashSession;

        if (! $session) {
            return [
                'opening' => 0.0,
                'cash_sales' => 0.0,
                'supplies' => 0.0,
                'withdrawals' => 0.0,
                'expected' => 0.0,
            ];
        }

        return app(CashClosingReportService::class)->breakdown($session);
    }
}
