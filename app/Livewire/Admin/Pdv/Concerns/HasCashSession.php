<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\Order;
use App\Models\PdvCashSession;
use App\Services\Pdv\CashClosingReportService;
use App\Support\MoneyInput;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

trait HasCashSession
{
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

        if (! $this->selectedBranchId) {
            return;
        }

        $this->assertSelectedBranchBelongsToCurrentCompany();
        $this->resetValidation('openingAmountInput');

        $amount = blank($this->openingAmountInput) ? 0.0 : MoneyInput::parse($this->openingAmountInput);

        if ($amount === null || $amount < 0) {
            $this->addError('openingAmountInput', 'Informe um valor de abertura válido (zero ou maior).');

            return;
        }

        $company = app('current.company');
        $terminalName = trim($this->terminalName) ?: null;
        $userId = auth()->id();

        // Duplo clique, Enter + clique ou duas abas abrindo ao mesmo tempo criavam duas sessões
        // abertas pro mesmo operador/filial (syncCashSession pega a mais recente e a outra ficava
        // órfã, com vendas fora da conferência). A trava serializa a checagem + criação.
        $lock = Cache::lock("pdv:open-cash:{$company->id}:{$this->selectedBranchId}:{$userId}", 10);

        $session = $lock->get(function () use ($company, $userId, $terminalName, $amount) {
            $existing = PdvCashSession::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('branch_id', $this->selectedBranchId)
                ->where('user_id', $userId)
                ->whereNull('closed_at')
                ->latest()
                ->first();

            if ($existing) {
                return $existing;
            }

            $created = PdvCashSession::create([
                'company_id' => $company->id,
                'branch_id' => $this->selectedBranchId,
                'user_id' => $userId,
                'terminal_name' => $terminalName,
                'opening_amount' => $amount,
            ]);

            $this->cashSessionId = $created->id;

            $this->audit('cash_opened', [
                'amount' => $amount,
                'reason' => $terminalName,
            ]);

            return $created;
        });

        if (! $session) {
            // Outra requisição do mesmo operador está abrindo o caixa agora — só ressincroniza.
            $this->syncCashSession();

            return;
        }

        $this->cashSessionId = $session->id;
        $this->openingAmountInput = '';
        $this->step = 'catalog';
    }

    public function proceedToCloseCash(): void
    {
        abort_unless(! $this->isWaiter, 403);

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

        $session = $this->ownOpenCashSession();

        if (! $session) {
            $this->addError('cash_movement_amount', 'O caixa não está aberto.');
            $this->syncCashSession();

            return;
        }

        $amount = MoneyInput::toFloat($this->cashMovementAmountInput);
        $reason = trim($this->cashMovementReason);

        if ($amount <= 0) {
            $this->addError('cash_movement_amount', 'Informe um valor maior que zero.');

            return;
        }

        if (blank($reason)) {
            $this->addError('cash_movement_reason', 'Informe o motivo da movimentação.');

            return;
        }

        if ($this->cashMovementType === 'withdrawal') {
            $available = $this->cashSessionExpected($session);

            if ($amount > $available + 0.001) {
                $this->addError('cash_movement_amount', 'Sangria maior que o dinheiro disponível no caixa (R$ '.number_format($available, 2, ',', '.').').');

                return;
            }
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

        if (! $this->cashSessionId) {
            $this->step = 'catalog';

            return;
        }

        $session = $this->ownOpenCashSession();

        if (! $session) {
            $this->cashSessionId = null;
            $this->step = 'open_cash';

            return;
        }

        $this->resetValidation(['closingAmountInput', 'reconciliation_notes']);

        if (blank($this->closingAmountInput)) {
            $this->addError('closingAmountInput', 'Informe o valor contado no caixa.');

            return;
        }

        $closing = MoneyInput::parse($this->closingAmountInput);

        if ($closing === null || $closing < 0) {
            $this->addError('closingAmountInput', 'Informe um valor contado válido (zero ou maior).');

            return;
        }

        $expected = $this->cashSessionExpected($session);
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

    /**
     * Sessão aberta do operador logado nesta filial. `cashSessionId` é `#[Locked]`, mas quem
     * fecha/movimenta o caixa precisa ser o dono da sessão — nunca só "alguma sessão da empresa".
     */
    private function ownOpenCashSession(): ?PdvCashSession
    {
        if (! $this->cashSessionId) {
            return null;
        }

        return PdvCashSession::query()
            ->whereKey($this->cashSessionId)
            ->where('user_id', auth()->id())
            ->where('branch_id', $this->selectedBranchId)
            ->whereNull('closed_at')
            ->first();
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
