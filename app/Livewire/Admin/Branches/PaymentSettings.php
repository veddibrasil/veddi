<?php

namespace App\Livewire\Admin\Branches;

use App\Models\Branch;
use App\Support\BranchPaymentMethods;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PaymentSettings extends Component
{
    #[Locked]
    public Branch $branch;

    #[Locked]
    public bool $canSave = false;

    public array $methods = [];

    public function mount(Branch $branch): void
    {
        $this->branch = $branch;
        $this->authorizeAccess('branches.view');
        $company = $branch->company;
        $this->canSave = auth()->user()->hasPermission('branches.update', $company);

        foreach (BranchPaymentMethods::ORDER_TYPES as $channel => $types) {
            foreach ($types as $type => $label) {
                $this->methods[$channel][$type] = $branch->paymentMethodsFor($channel, $type);
            }
        }
    }

    public function save(): void
    {
        $this->authorizeAccess('branches.update');

        $rules = ['methods' => ['required', 'array:chat,pdv']];
        foreach (BranchPaymentMethods::ORDER_TYPES as $channel => $types) {
            $rules["methods.{$channel}"] = ['required', 'array:'.implode(',', array_keys($types))];
            foreach ($types as $type => $label) {
                $rules["methods.{$channel}.{$type}"] = ['required', 'array', 'min:1'];
                $rules["methods.{$channel}.{$type}.*"] = ['required', 'string', 'distinct', Rule::in(array_keys(BranchPaymentMethods::LABELS[$channel]))];
            }
        }

        $validated = $this->validate($rules, [
            'methods.*.*.required' => 'Selecione ao menos uma forma de pagamento para esta modalidade.',
            'methods.*.*.min' => 'Selecione ao menos uma forma de pagamento para esta modalidade.',
            'methods.*.*.*.in' => 'Forma de pagamento inválida.',
        ]);

        $this->branch->update(['payment_methods' => $validated['methods']]);
        Cache::forget("branches:company:{$this->branch->company_id}");
        session()->flash('status', 'Formas de pagamento salvas.');
    }

    private function authorizeAccess(string $permission): void
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) {
            return;
        }

        $company = app()->bound('current.company') ? app('current.company') : null;
        abort_unless($company
            && (int) $this->branch->company_id === (int) $company->id
            && $user->hasPermission($permission, $company)
            && $user->canAccessBranch($company, $this->branch->id), 403);
    }

    public function render()
    {
        return view('livewire.admin.branches.payment-settings', [
            'orderTypes' => BranchPaymentMethods::ORDER_TYPES,
            'methodLabels' => BranchPaymentMethods::LABELS,
        ])->layout('layouts.app', ['title' => 'Pagamentos — '.$this->branch->name]);
    }
}
