<?php

namespace App\Livewire\Admin\Wallet;

use App\Services\Finance\AnticipationService;
use App\Services\Finance\BalanceService;
use App\Services\Finance\WithdrawalService;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class CompanyWallet extends Component
{
    use WithPagination;

    public int $companyId = 0;

    public float $availableBalance = 0.0;

    public float $pendingBalance = 0.0;

    public float $pixWithdrawalFee = 0.0;

    // Anticipation modal
    public bool $showAnticipationModal = false;

    public array $eligibleTransactions = [];   // lista completa calculada

    public array $selectedTransactionIds = [];   // IDs marcados pelo lojista

    public array $anticipationRates = [];   // faixas de taxa da empresa

    public ?array $gatewayAnticipationInfo = null;   // saldo disponível retornado pela Yapay

    // Withdrawal modal
    public bool $showWithdrawalModal = false;

    public float $withdrawalAmount = 0.0;

    public string $payoutType = 'PIX';

    public string $pixKey = '';

    public string $pixKeyType = 'EVP';

    public string $bankCode = '';

    public string $bankAgency = '';

    public string $bankAccount = '';

    public string $bankAccountDigit = '';

    public string $bankAccountType = 'CONTA_CORRENTE';

    public string $bankOwnerCpfCnpj = '';

    public string $bankOwnerName = '';

    public function mount(BalanceService $balanceService): void
    {
        $company = app('current.company');

        $this->companyId = $company->id;
        $this->refreshBalances($balanceService, $company);

        $this->pixWithdrawalFee = (float) config('payments.pix_withdrawal_fee', 0.50);

        if ($company->default_payout_type) {
            $this->payoutType = $company->default_payout_type;
            $this->pixKey = $company->default_pix_key ?? '';
            $this->pixKeyType = $company->default_pix_key_type ?? 'EVP';
            $this->bankCode = $company->default_bank_code ?? '';
            $this->bankAgency = $company->default_bank_agency ?? '';
            $this->bankAccount = $company->default_bank_account ?? '';
            $this->bankAccountDigit = $company->default_bank_account_digit ?? '';
            $this->bankAccountType = $company->default_bank_account_type ?? 'CONTA_CORRENTE';
            $this->bankOwnerCpfCnpj = $company->default_bank_owner_cpf_cnpj ?? '';
            $this->bankOwnerName = $company->default_bank_owner_name ?? '';
        }
    }

    // ─── Anticipation ────────────────────────────────────────────────────────

    public function openAnticipationModal(AnticipationService $anticipationService): void
    {
        $company = app('current.company');

        $this->eligibleTransactions = $anticipationService->getEligibleTransactions($company)->all();
        $this->anticipationRates = $anticipationService->getRates($company);
        $this->gatewayAnticipationInfo = $anticipationService->getGatewayAnticipationInfo($company);
        $this->selectedTransactionIds = collect($this->eligibleTransactions)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->showAnticipationModal = true;
    }

    public function closeAnticipationModal(): void
    {
        $this->showAnticipationModal = false;
        $this->eligibleTransactions = [];
        $this->selectedTransactionIds = [];
        $this->anticipationRates = [];
        $this->gatewayAnticipationInfo = null;
    }

    public function toggleAll(): void
    {
        $allIds = collect($this->eligibleTransactions)->pluck('id')->map(fn ($id) => (string) $id)->all();

        if (count($this->selectedTransactionIds) === count($allIds)) {
            $this->selectedTransactionIds = [];
        } else {
            $this->selectedTransactionIds = $allIds;
        }
    }

    #[Computed]
    public function anticipationSummary(): array
    {
        if (empty($this->eligibleTransactions) || empty($this->selectedTransactionIds)) {
            return [
                'transactions_count' => 0,
                'gross_amount' => 0.0,
                'fee_amount' => 0.0,
                'net_amount' => 0.0,
                'has_eligible' => false,
            ];
        }

        $selectedIds = array_map('intval', $this->selectedTransactionIds);
        $selected = collect($this->eligibleTransactions)->whereIn('id', $selectedIds);

        $grossAmount = round((float) $selected->sum('net_value'), 2);
        $feeAmount = round((float) $selected->sum('fee'), 2);

        return [
            'transactions_count' => $selected->count(),
            'gross_amount' => $grossAmount,
            'fee_amount' => $feeAmount,
            'net_amount' => round($grossAmount - $feeAmount, 2),
            'has_eligible' => $selected->isNotEmpty(),
        ];
    }

    public function confirmAnticipation(AnticipationService $anticipationService, BalanceService $balanceService): void
    {
        $company = app('current.company');
        $selectedIds = array_map('intval', $this->selectedTransactionIds);

        $count = $anticipationService->anticipateSelected($company, $selectedIds);

        $snapshot = $balanceService->updateSnapshot($company);
        $this->availableBalance = (float) $snapshot->available_balance;
        $this->pendingBalance = (float) $snapshot->blocked_balance;

        $this->showAnticipationModal = false;
        $this->eligibleTransactions = [];
        $this->selectedTransactionIds = [];
        $this->anticipationRates = [];
        $this->gatewayAnticipationInfo = null;

        // Atualiza o saldo no dashboard (mesmo componente de roteamento)
        $this->dispatch('balance-updated');

        $suffix = $count === 1 ? 'ão antecipada.' : 'ões antecipadas.';
        session()->flash('success', "Antecipação realizada com sucesso. {$count} transaç{$suffix}");
    }

    // ─── Withdrawal ──────────────────────────────────────────────────────────

    public function openWithdrawalModal(): void
    {
        $this->withdrawalAmount = 0.0;
        $this->showWithdrawalModal = true;
    }

    public function closeWithdrawalModal(): void
    {
        $this->showWithdrawalModal = false;
    }

    public function requestWithdrawal(WithdrawalService $withdrawalService, BalanceService $balanceService): void
    {
        $company = app('current.company');

        $this->validate([
            'withdrawalAmount' => ['required', 'numeric', 'min:10'],
            'payoutType' => ['required', 'in:PIX,TED'],
            'pixKey' => ['required_if:payoutType,PIX'],
            'pixKeyType' => ['required_if:payoutType,PIX'],
            'bankCode' => ['required_if:payoutType,TED'],
            'bankAgency' => ['required_if:payoutType,TED'],
            'bankAccount' => ['required_if:payoutType,TED'],
            'bankOwnerCpfCnpj' => ['required_if:payoutType,TED'],
            'bankOwnerName' => ['required_if:payoutType,TED'],
        ], [
            'withdrawalAmount.min' => 'O valor mínimo para saque é R$ 10,00.',
            'pixKey.required_if' => 'Informe a chave PIX.',
            'bankCode.required_if' => 'Informe o banco.',
        ]);

        try {
            $payoutData = [
                'payout_type' => $this->payoutType,
                'pix_key' => $this->payoutType === 'PIX' ? $this->pixKey : null,
                'pix_key_type' => $this->payoutType === 'PIX' ? $this->pixKeyType : null,
                'bank_code' => $this->payoutType === 'TED' ? $this->bankCode : null,
                'bank_agency' => $this->payoutType === 'TED' ? $this->bankAgency : null,
                'bank_account' => $this->payoutType === 'TED' ? $this->bankAccount : null,
                'bank_account_digit' => $this->payoutType === 'TED' ? $this->bankAccountDigit : null,
                'bank_account_type' => $this->payoutType === 'TED' ? $this->bankAccountType : null,
                'bank_owner_cpf_cnpj' => $this->payoutType === 'TED' ? $this->bankOwnerCpfCnpj : null,
                'bank_owner_name' => $this->payoutType === 'TED' ? $this->bankOwnerName : null,
            ];

            $withdrawalService->requestWithdrawal($company, $this->withdrawalAmount, $payoutData);
        } catch (\RuntimeException $e) {
            $this->addError('withdrawalAmount', $e->getMessage());

            return;
        }

        $company->update([
            'default_payout_type' => $this->payoutType,
            'default_pix_key' => $this->pixKey ?: null,
            'default_pix_key_type' => $this->pixKeyType ?: null,
            'default_bank_code' => $this->bankCode ?: null,
            'default_bank_agency' => $this->bankAgency ?: null,
            'default_bank_account' => $this->bankAccount ?: null,
            'default_bank_account_digit' => $this->bankAccountDigit ?: null,
            'default_bank_account_type' => $this->bankAccountType ?: null,
            'default_bank_owner_cpf_cnpj' => $this->bankOwnerCpfCnpj ?: null,
            'default_bank_owner_name' => $this->bankOwnerName ?: null,
        ]);

        $snapshot = $balanceService->updateSnapshot($company);
        $this->availableBalance = (float) $snapshot->available_balance;
        $this->pendingBalance = (float) $snapshot->blocked_balance;

        $this->showWithdrawalModal = false;

        session()->flash('success', 'Saque solicitado com sucesso. Será processado em instantes.');
    }

    // ─── Broadcast ───────────────────────────────────────────────────────────

    public function refreshWallet(BalanceService $balanceService): void
    {
        $company = app('current.company');
        $this->refreshBalances($balanceService, $company);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function refreshBalances(BalanceService $balanceService, $company): void
    {
        $calculated = $balanceService->calculateBalance($company);
        $this->availableBalance = $calculated['available_balance'];
        $this->pendingBalance = $calculated['blocked_balance'];
    }

    public function render(): View
    {
        $company = app('current.company');

        $entries = $company
            ->walletEntries()
            ->latest()
            ->paginate(15);

        $withdrawals = $company
            ->withdrawals()
            ->latest()
            ->paginate(10, ['*'], 'withdrawalsPage');

        return view('livewire.admin.wallet.company-wallet', compact('entries', 'withdrawals'))
            ->layout('layouts.app', ['title' => 'Carteira']);
    }
}
