<?php

namespace App\Livewire\Admin\Branches;

use App\Models\Branch;
use App\Models\BranchServiceCharge;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class ServiceCharges extends Component
{
    public Branch $branch;

    public bool $canSave = false;

    public bool $service_fee_enabled = false;

    public string $service_fee_type = 'percent';

    public string $service_fee_value = '0';

    public bool $couvert_enabled = false;

    public string $couvert_type = 'fixed';

    public string $couvert_value = '0';

    protected function rules(): array
    {
        return [
            'service_fee_enabled' => ['boolean'],
            'service_fee_type' => ['required', 'in:fixed,percent'],
            'service_fee_value' => ['required', 'numeric', 'min:0', 'max:100000'],
            'couvert_enabled' => ['boolean'],
            'couvert_type' => ['required', 'in:fixed,percent'],
            'couvert_value' => ['required', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    protected function messages(): array
    {
        return [
            'service_fee_value.max' => 'Valor muito alto.',
            'couvert_value.max' => 'Valor muito alto.',
        ];
    }

    public function mount(Branch $branch): void
    {
        $this->branch = $branch;

        $user = auth()->user();
        if ($user->isSuperAdmin()) {
            $this->canSave = true;
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');
            abort_unless($company->pdv_module_enabled, 403, 'Módulo PDV não está habilitado para esta empresa.');
            $this->canSave = $user->hasPermission('branches.update', $company);

            if ($user->isBranchScoped($company) && $user->branchIdForCompany($company) !== $branch->id) {
                abort(403);
            }
        }

        $charge = $branch->serviceCharge;

        if (! $charge) {
            return;
        }

        $this->service_fee_enabled = $charge->service_fee_enabled;
        $this->service_fee_type = $charge->service_fee_type;
        $this->service_fee_value = (string) $charge->service_fee_value;
        $this->couvert_enabled = $charge->couvert_enabled;
        $this->couvert_type = $charge->couvert_type;
        $this->couvert_value = (string) $charge->couvert_value;
    }

    public function save(): void
    {
        abort_unless($this->canSave, 403);

        if ($this->service_fee_type === 'percent' && (float) $this->service_fee_value > 100) {
            $this->addError('service_fee_value', 'Percentual não pode exceder 100%.');

            return;
        }

        if ($this->couvert_type === 'percent' && (float) $this->couvert_value > 100) {
            $this->addError('couvert_value', 'Percentual não pode exceder 100%.');

            return;
        }

        $this->validate($this->rules(), $this->messages());

        BranchServiceCharge::updateOrCreate(
            ['branch_id' => $this->branch->id],
            [
                'company_id' => $this->branch->company_id,
                'service_fee_enabled' => $this->service_fee_enabled,
                'service_fee_type' => $this->service_fee_type,
                'service_fee_value' => (float) $this->service_fee_value,
                'couvert_enabled' => $this->couvert_enabled,
                'couvert_type' => $this->couvert_type,
                'couvert_value' => (float) $this->couvert_value,
            ]
        );

        Cache::forget("branches:company:{$this->branch->company_id}");

        session()->flash('status', 'Taxa de serviço e couvert salvos.');
        $this->redirect(route('admin.branches.service-charges', $this->branch));
    }

    public function render()
    {
        return view('livewire.admin.branches.service-charges')
            ->layout('layouts.app', ['title' => 'Taxa de Serviço e Couvert — '.$this->branch->name]);
    }
}
