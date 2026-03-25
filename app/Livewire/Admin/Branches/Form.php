<?php

namespace App\Livewire\Admin\Branches;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Form extends Component
{
    public ?Branch $branch = null;
    public bool $isEditing  = false;

    public string $name      = '';
    public string $address   = '';
    public string $city      = '';
    public string $phone     = '';
    public bool   $active    = true;
    public string $opens_at  = '08:00';
    public string $closes_at = '20:00';

    public bool $needsCompanySelect = false;
    public ?int $company_id         = null;

    protected function rules(): array
    {
        $companyRule = $this->needsCompanySelect
            ? ['required', 'integer', 'exists:companies,id']
            : ['nullable'];

        return [
            'company_id' => $companyRule,
            'name'       => ['required', 'string', 'max:100'],
            'address'    => ['required', 'string', 'max:255'],
            'city'       => ['required', 'string', 'max:100'],
            'phone'      => ['nullable', 'regex:/^\(?\d{2}\)?[\s\-]?\d{4,5}[\-]?\d{4}$/'],
            'active'     => ['boolean'],
            'opens_at'   => ['required', 'date_format:H:i'],
            'closes_at'  => ['required', 'date_format:H:i'],
        ];
    }

    protected function messages(): array
    {
        return [
            'company_id.required'  => 'Selecione uma empresa.',
            'company_id.exists'    => 'Empresa inválida.',
            'name.required'        => 'Informe o nome da filial.',
            'address.required'     => 'Informe o endereço.',
            'city.required'        => 'Informe a cidade.',
            'phone.regex'          => 'Telefone inválido.',
            'opens_at.required'    => 'Informe o horário de abertura.',
            'closes_at.required'   => 'Informe o horário de fechamento.',
        ];
    }

    public function mount(?Branch $branch = null): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            $this->needsCompanySelect = true;
            $this->company_id = Company::withoutGlobalScope(CompanyScope::class)
                ->where('active', true)
                ->orderBy('name')
                ->value('id');
        } else {
            // Usuário possui empresa vinculada — pegar a primeira
            $company = $user->companies()->first();
            $this->company_id = $company?->id;
        }

        if ($branch?->exists) {
            $this->branch    = $branch;
            $this->isEditing = true;
            $this->company_id = $branch->company_id;
            $this->fill($branch->only('name', 'address', 'city', 'phone', 'active', 'opens_at', 'closes_at'));
        }
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules(), $this->messages());

        if ($this->isEditing) {
            $data = collect($validated)->except('company_id')->toArray();
            if ($this->needsCompanySelect && $this->company_id) {
                $data['company_id'] = $this->company_id;
            }
            Branch::withoutGlobalScope(CompanyScope::class)
                ->where('id', $this->branch->id)
                ->update($data);
            $companyId = $this->company_id ?? $this->branch->company_id;
            session()->flash('status', 'Filial atualizada.');
        } else {
            $data = collect($validated)->except('company_id')->toArray();

            if ($this->needsCompanySelect) {
                $data['company_id'] = $this->company_id;
                $branch = Branch::withoutGlobalScope(CompanyScope::class)->create($data);
                $companyId = $this->company_id;
            } else {
                // company_id será preenchido automaticamente pelo BelongsToCompany trait
                $branch = Branch::create($data);
                $companyId = app()->bound('current.company') ? app('current.company')->id : $this->company_id;
            }

            session()->flash('status', 'Filial criada.');
        }

        Cache::forget("branches:company:{$companyId}");
        Cache::forget("open_branches:company:{$companyId}");

        $this->redirect(route('admin.branches.index'));
    }

    public function render()
    {
        $companies = $this->needsCompanySelect
            ? Company::withoutGlobalScope(CompanyScope::class)
                ->where('active', true)
                ->orderBy('name')
                ->get()
            : collect();

        return view('livewire.admin.branches.form', compact('companies'))
            ->layout('layouts.app', ['title' => $this->isEditing ? 'Editar Filial' : 'Nova Filial']);
    }
}
