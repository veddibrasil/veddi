<?php

namespace App\Livewire\SuperAdmin\Companies;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public function toggleActive(int $companyId): void
    {
        $company = Company::withoutGlobalScope(CompanyScope::class)->findOrFail($companyId);
        $company->update(['active' => ! $company->active]);
    }

    public function delete(int $companyId): void
    {
        Company::withoutGlobalScope(CompanyScope::class)->findOrFail($companyId)->delete();
        session()->flash('status', 'Empresa excluída.');
    }

    public function render()
    {
        $companies = Company::withoutGlobalScope(CompanyScope::class)
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->withCount('branches', 'orders')
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.super-admin.companies.index', compact('companies'))
            ->layout('layouts.app', ['title' => 'Super Admin — Empresas']);
    }
}
