<?php

namespace App\Livewire\Admin\Wallet;

use App\Services\Finance\BalanceService;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

// Exibe saldo disponível, saldo a receber e histórico de lançamentos da carteira.
// Saldo calculado on-demand via BalanceService (não usa snapshot CompanyBalance).
// A view escuta o canal privado wallet.{companyId} (WalletBalanceUpdated) e chama
// refreshWallet() via Livewire quando o saldo mudar em tempo real.
class CompanyWallet extends Component
{
    use WithPagination;

    public int $companyId = 0;

    // Transações status=released não sacadas (saldo para saque imediato).
    // public float $availableBalance = 0.0;

    // Transações status=confirmed não sacadas (aguardando prazo de liberação).
    public float $pendingBalance = 0.0;

    public function mount(BalanceService $balanceService): void
    {
        $company = app('current.company');

        $this->companyId = $company->id;
        $this->refreshBalances($balanceService, $company);
    }

    public function refreshWallet(BalanceService $balanceService): void
    {
        $company = app('current.company');
        $this->refreshBalances($balanceService, $company);
    }

    private function refreshBalances(BalanceService $balanceService, $company): void
    {
        $calculated = $balanceService->calculateBalance($company);
        // $this->availableBalance = $calculated['available_balance'];
        // blocked = confirmed (pendente de liberação), mostrado como "a receber"
        $this->pendingBalance = $calculated['blocked_balance'];
    }

    public function render(): View
    {
        $company = app('current.company');

        // Exibe apenas lançamentos principais — créditos de pedidos, saques e estornos.
        // Taxas (fee, pix_fee, card_fee, anticipation_fee) ficam ocultas para simplificar o histórico.
        $entries = $company
            ->walletEntries()
            ->whereIn('type', ['credit', 'withdrawal', 'refund'])
            ->latest()
            ->paginate(15);

        return view('livewire.admin.wallet.company-wallet', compact('entries'))
            ->layout('layouts.app', ['title' => 'Carteira']);
    }
}
