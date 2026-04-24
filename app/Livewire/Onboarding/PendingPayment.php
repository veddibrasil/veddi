<?php

namespace App\Livewire\Onboarding;

use App\Models\Company;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PendingPayment extends Component
{
    public ?Company $company = null;

    // PIX / Boleto polling state
    public bool $ready = false;

    // Copy-paste feedback
    public bool $copied = false;

    public function mount(): void
    {
        $companyId = session('pending_company_id');

        if (! $companyId) {
            $this->redirectRoute('register.create');

            return;
        }

        $this->company = Company::find($companyId);

        if (! $this->company) {
            $this->redirectRoute('register.create');

            return;
        }

        if ($this->company->status === 'ACTIVE') {
            session()->forget('pending_company_id');
            $this->redirectRoute('login');

            return;
        }

        $this->ready = $this->company->asaas_setup_charge_id !== null;
    }

    #[Computed]
    public function firstPaymentTotal(): float
    {
        $plan = $this->company?->plan;
        $setup = $plan?->setupFee() ?? 99.00;
        $monthly = $plan?->hasMonthlySubscription() ? ($plan?->monthlyPrice() ?? 0.0) : 0.0;

        return $setup + $monthly;
    }

    #[Computed]
    public function planMonthlyPrice(): float
    {
        return (float) ($this->company?->plan?->monthlyPrice() ?? 0.0);
    }

    #[Computed]
    public function planHasMonthly(): bool
    {
        return (bool) ($this->company?->plan?->hasMonthlySubscription() ?? false);
    }

    /**
     * Polling: chamado a cada 2.5s para PIX e Boleto enquanto aguarda o job.
     */
    public function checkPaymentStatus(): void
    {
        if (! $this->company) {
            return;
        }

        $this->company->refresh();
        $this->ready = $this->company->asaas_setup_charge_id !== null;

        if ($this->company->status === 'ACTIVE') {
            session()->forget('pending_company_id');
            $this->redirectRoute('login');
        }
    }

    /**
     * Copia o código PIX para área de transferência via JS.
     */
    public function copyPix(): void
    {
        $this->dispatch('copy-to-clipboard', text: $this->company->asaas_setup_pix_copy_paste);
        $this->copied = true;
    }

    public function render()
    {
        return view('livewire.onboarding.pending-payment')
            ->layout('layouts.auth');
    }
}
