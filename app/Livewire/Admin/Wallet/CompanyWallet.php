<?php

namespace App\Livewire\Admin\Wallet;

use App\Models\CompanyWalletEntry;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

// Histórico de lançamentos da carteira. Saldo e saque ficam no portal Vindi
// (botão "Ver Saldo"); o saldo local está em BalanceService, usado no dashboard.
// A view escuta o canal privado wallet.{companyId} (WalletBalanceUpdated) e chama
// refreshWallet() via Livewire quando o saldo mudar em tempo real.
class CompanyWallet extends Component
{
    use WithPagination;

    /** Taxas descontadas de cada crédito — exibidas junto do crédito, não como linhas soltas. */
    private const FEE_TYPES = ['fee', 'pix_fee', 'card_fee', 'anticipation_fee'];

    #[Locked]
    public int $companyId = 0;

    public function mount(): void
    {
        $this->companyId = app('current.company')->id;
    }

    // Só força um novo render com o histórico atualizado.
    public function refreshWallet(): void {}

    public function render(): View
    {
        $company = app('current.company');

        $entries = $company
            ->walletEntries()
            ->whereIn('type', ['credit', 'withdrawal', 'refund'])
            ->latest()
            ->paginate(15);

        return view('livewire.admin.wallet.company-wallet', [
            'entries' => $entries,
            'feesByCredit' => $this->feesByCredit($company->id, $entries->getCollection()),
        ])->layout('layouts.app', ['title' => 'Carteira']);
    }

    /**
     * Soma das taxas de cada crédito da página, por "order_id|reference".
     *
     * @return array<string, float>
     */
    private function feesByCredit(int $companyId, $entries): array
    {
        $credits = $entries->where('type', 'credit');

        if ($credits->isEmpty()) {
            return [];
        }

        return CompanyWalletEntry::where('company_id', $companyId)
            ->whereIn('order_id', $credits->pluck('order_id')->filter()->unique())
            ->whereIn('reference', $credits->pluck('reference')->filter()->unique())
            ->whereIn('type', self::FEE_TYPES)
            ->selectRaw('order_id, reference, SUM(amount) as total')
            ->groupBy('order_id', 'reference')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->order_id.'|'.$row->reference => (float) $row->total])
            ->all();
    }
}
