<?php

namespace App\Livewire\Admin\Wallet;

use App\Jobs\ProcessWithdrawal;
use App\Models\CompanyWalletEntry;
use App\Models\CompanyWithdrawal;
use Illuminate\View\View;
use Livewire\Component;

class CompanyWallet extends Component
{
    public float  $balance = 0.0;
    public array  $entries = [];
    public array  $withdrawals = [];

    // Withdrawal modal
    public bool   $showWithdrawalModal = false;
    public float  $withdrawalAmount    = 0.0;
    public string $payoutType          = 'PIX';
    public string $pixKey              = '';
    public string $pixKeyType          = 'EVP';
    public string $bankCode            = '';
    public string $bankAgency          = '';
    public string $bankAccount         = '';
    public string $bankAccountDigit    = '';
    public string $bankAccountType     = 'CONTA_CORRENTE';
    public string $bankOwnerCpfCnpj    = '';
    public string $bankOwnerName       = '';

    public function mount(): void
    {
        $company = app('current.company');

        $this->balance = CompanyWalletEntry::balanceFor($company->id);

        $this->entries = $company->walletEntries()
            ->latest()
            ->limit(50)
            ->get()
            ->toArray();

        $this->withdrawals = $company->withdrawals()
            ->latest()
            ->limit(20)
            ->get()
            ->toArray();

        // Pre-fill payout defaults
        if ($company->default_payout_type) {
            $this->payoutType       = $company->default_payout_type;
            $this->pixKey           = $company->default_pix_key ?? '';
            $this->pixKeyType       = $company->default_pix_key_type ?? 'EVP';
            $this->bankCode         = $company->default_bank_code ?? '';
            $this->bankAgency       = $company->default_bank_agency ?? '';
            $this->bankAccount      = $company->default_bank_account ?? '';
            $this->bankAccountDigit = $company->default_bank_account_digit ?? '';
            $this->bankAccountType  = $company->default_bank_account_type ?? 'CONTA_CORRENTE';
            $this->bankOwnerCpfCnpj = $company->default_bank_owner_cpf_cnpj ?? '';
            $this->bankOwnerName    = $company->default_bank_owner_name ?? '';
        }
    }

    public function openWithdrawalModal(): void
    {
        $this->withdrawalAmount = 0.0;
        $this->showWithdrawalModal = true;
    }

    public function closeWithdrawalModal(): void
    {
        $this->showWithdrawalModal = false;
    }

    public function requestWithdrawal(): void
    {
        $company = app('current.company');

        $this->validate([
            'withdrawalAmount' => ['required', 'numeric', 'min:10'],
            'payoutType'       => ['required', 'in:PIX,TED'],
            'pixKey'           => ['required_if:payoutType,PIX'],
            'pixKeyType'       => ['required_if:payoutType,PIX'],
            'bankCode'         => ['required_if:payoutType,TED'],
            'bankAgency'       => ['required_if:payoutType,TED'],
            'bankAccount'      => ['required_if:payoutType,TED'],
            'bankOwnerCpfCnpj' => ['required_if:payoutType,TED'],
            'bankOwnerName'    => ['required_if:payoutType,TED'],
        ], [
            'withdrawalAmount.min' => 'O valor mínimo para saque é R$ 10,00.',
            'pixKey.required_if'   => 'Informe a chave PIX.',
            'bankCode.required_if' => 'Informe o banco.',
        ]);

        $balance = CompanyWalletEntry::balanceFor($company->id);

        if ($this->withdrawalAmount > $balance) {
            $this->addError('withdrawalAmount', 'Saldo insuficiente. Saldo disponível: R$ ' . number_format($balance, 2, ',', '.'));
            return;
        }

        $withdrawal = CompanyWithdrawal::create([
            'company_id'           => $company->id,
            'amount'               => $this->withdrawalAmount,
            'status'               => 'pending',
            'payout_type'          => $this->payoutType,
            'pix_key'              => $this->payoutType === 'PIX' ? $this->pixKey : null,
            'pix_key_type'         => $this->payoutType === 'PIX' ? $this->pixKeyType : null,
            'bank_code'            => $this->payoutType === 'TED' ? $this->bankCode : null,
            'bank_agency'          => $this->payoutType === 'TED' ? $this->bankAgency : null,
            'bank_account'         => $this->payoutType === 'TED' ? $this->bankAccount : null,
            'bank_account_digit'   => $this->payoutType === 'TED' ? $this->bankAccountDigit : null,
            'bank_account_type'    => $this->payoutType === 'TED' ? $this->bankAccountType : null,
            'bank_owner_cpf_cnpj'  => $this->payoutType === 'TED' ? $this->bankOwnerCpfCnpj : null,
            'bank_owner_name'      => $this->payoutType === 'TED' ? $this->bankOwnerName : null,
        ]);

        // Save payout defaults for next time
        $company->update([
            'default_payout_type'          => $this->payoutType,
            'default_pix_key'              => $this->pixKey ?: null,
            'default_pix_key_type'         => $this->pixKeyType ?: null,
            'default_bank_code'            => $this->bankCode ?: null,
            'default_bank_agency'          => $this->bankAgency ?: null,
            'default_bank_account'         => $this->bankAccount ?: null,
            'default_bank_account_digit'   => $this->bankAccountDigit ?: null,
            'default_bank_account_type'    => $this->bankAccountType ?: null,
            'default_bank_owner_cpf_cnpj'  => $this->bankOwnerCpfCnpj ?: null,
            'default_bank_owner_name'      => $this->bankOwnerName ?: null,
        ]);

        ProcessWithdrawal::dispatch($withdrawal->id);

        $this->showWithdrawalModal = false;
        $this->balance             = CompanyWalletEntry::balanceFor($company->id);
        $this->withdrawals         = $company->withdrawals()->latest()->limit(20)->get()->toArray();

        session()->flash('success', 'Saque solicitado com sucesso. Será processado em instantes.');
    }

    public function render(): View
    {
        return view('livewire.admin.wallet.company-wallet')
            ->layout('layouts.app', ['title' => 'Carteira']);
    }
}
