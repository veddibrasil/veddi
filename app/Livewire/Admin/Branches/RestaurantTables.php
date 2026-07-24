<?php

namespace App\Livewire\Admin\Branches;

use App\Models\Branch;
use App\Models\RestaurantTable;
use Livewire\Component;

class RestaurantTables extends Component
{
    public Branch $branch;

    public bool $canSave = false;

    public string $newCount = '';

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
    }

    public function generate(): void
    {
        abort_unless($this->canSave, 403);

        $this->validate([
            'newCount' => ['required', 'integer', 'min:1', 'max:200'],
        ], [], ['newCount' => 'Quantidade de mesas']);

        $count = (int) $this->newCount;
        $existingNumbers = $this->branch->restaurantTables()->pluck('number');

        for ($number = 1; $number <= $count; $number++) {
            if ($existingNumbers->contains($number)) {
                continue;
            }

            RestaurantTable::create([
                'company_id' => $this->branch->company_id,
                'branch_id' => $this->branch->id,
                'number' => $number,
                'active' => true,
            ]);
        }

        $this->newCount = '';
        session()->flash('status', 'Mesas geradas com sucesso.');
    }

    public function toggleActive(int $tableId): void
    {
        abort_unless($this->canSave, 403);

        $table = $this->branch->restaurantTables()->findOrFail($tableId);
        $table->update(['active' => ! $table->active]);
    }

    public function getTablesProperty()
    {
        return $this->branch->restaurantTables()->orderBy('number')->get();
    }

    public function render()
    {
        return view('livewire.admin.branches.restaurant-tables')
            ->layout('layouts.app', ['title' => 'Mesas e Comandas — '.$this->branch->name]);
    }
}
