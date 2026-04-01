<?php

namespace App\Livewire\SuperAdmin\Card;

use App\Models\Company;
use App\Models\PaymentSettings;
use Livewire\Component;

class Index extends Component
{
    // Empresas
    public $companies = [];
    public $selectedCompanyId = null;

    // Taxas
    public float $cardRate1x              = 0.0299;
    public float $anticipationD2          = 0.0299;
    public float $anticipationD7          = 0.0249;
    public float $anticipationD15         = 0.0199;
    public float $anticipationD30         = 0.0000;
    public float $systemFeeRate           = 0.0000;
    public int   $defaultAnticipationDays = 15;

    public function mount(): void
    {
        $this->companies = Company::all();

        if ($this->companies->count()) {
            $this->selectedCompanyId = $this->companies->first()->id;
            $this->loadCompanyData();
        }
    }

    // 🔥 ESSENCIAL → dispara ao trocar empresa
    public function updatedSelectedCompanyId()
    {
        $this->loadCompanyData();
    }

    public function loadCompanyData(): void
    {
        // Reset completo evita bug visual
        $this->reset([
            'cardRate1x',
            'anticipationD2',
            'anticipationD7',
            'anticipationD15',
            'anticipationD30',
            'systemFeeRate',
            'defaultAnticipationDays',
        ]);

        $company = Company::find($this->selectedCompanyId);

        if (!$company) return;

        $ps = $company->paymentSettings;

        if ($ps) {
            $this->cardRate1x              = (float) $ps->card_rate_1x;
            $this->anticipationD2          = (float) $ps->anticipation_rate_d2;
            $this->anticipationD7          = (float) $ps->anticipation_rate_d7;
            $this->anticipationD15         = (float) $ps->anticipation_rate_d15;
            $this->anticipationD30         = (float) $ps->anticipation_rate_d30;
            $this->systemFeeRate           = (float) $ps->system_fee_rate;
            $this->defaultAnticipationDays = (int) $ps->default_anticipation_days;
        } else {
            // valores padrão
            $this->cardRate1x              = 0.0299;
            $this->anticipationD2          = 0.0299;
            $this->anticipationD7          = 0.0249;
            $this->anticipationD15         = 0.0199;
            $this->anticipationD30         = 0.0000;
            $this->systemFeeRate           = 0.0000;
            $this->defaultAnticipationDays = 15;
        }
    }

    public function saveCardSettings(): void
    {
        $this->validate([
            'cardRate1x'              => ['required', 'numeric', 'min:0', 'max:1'],
            'anticipationD2'          => ['required', 'numeric', 'min:0', 'max:1'],
            'anticipationD7'          => ['required', 'numeric', 'min:0', 'max:1'],
            'anticipationD15'         => ['required', 'numeric', 'min:0', 'max:1'],
            'anticipationD30'         => ['required', 'numeric', 'min:0', 'max:1'],
            'systemFeeRate'           => ['required', 'numeric', 'min:0', 'max:1'],
            'defaultAnticipationDays' => ['required', 'integer', 'in:2,7,15,30'],
        ]);

        $company = Company::find($this->selectedCompanyId);

        if (!$company) {
            session()->flash('error', 'Empresa não encontrada.');
            return;
        }

        PaymentSettings::updateOrCreate(
            ['company_id' => $company->id],
            [
                'card_rate_1x'              => $this->cardRate1x,
                'anticipation_rate_d2'      => $this->anticipationD2,
                'anticipation_rate_d7'      => $this->anticipationD7,
                'anticipation_rate_d15'     => $this->anticipationD15,
                'anticipation_rate_d30'     => $this->anticipationD30,
                'system_fee_rate'           => $this->systemFeeRate,
                'default_anticipation_days' => $this->defaultAnticipationDays,
            ]
        );

        session()->flash('status', 'Taxas de cartão salvas com sucesso.');
    }

    public function render()
    {
        return view('livewire.super-admin.card.index', [
            'currentCompany' => Company::find($this->selectedCompanyId),
        ])->layout('layouts.app', ['title' => 'Configurações das taxas']);
    }
}