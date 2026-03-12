<?php

namespace App\Livewire\Admin\Categories;

use App\Models\Company;
use App\Models\ProductCategory;
use App\Models\Scopes\CompanyScope;
use Livewire\Component;

class Index extends Component
{
    public string $name       = '';
    public int $sort_order    = 0;
    public ?int $editingId    = null;
    public ?int $deletingId   = null;

    public bool $isSuperAdmin = false;
    public ?int $company_id   = null;

    protected function rules(): array
    {
        $companyRule = $this->isSuperAdmin
            ? ['required', 'integer', 'exists:companies,id']
            : ['nullable'];

        return [
            'company_id' => $companyRule,
            'name'       => ['required', 'string', 'max:100'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }

    protected function messages(): array
    {
        return [
            'company_id.required' => 'Selecione uma empresa.',
            'name.required'       => 'Informe o nome da categoria.',
        ];
    }

    public function mount(): void
    {
        $user = auth()->user();
        $this->isSuperAdmin = $user->isSuperAdmin();

        if (! $this->isSuperAdmin) {
            $this->company_id = $user->companies()->first()?->id;
        }
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules(), $this->messages());

        if ($this->editingId) {
            $category = ProductCategory::withoutGlobalScope(CompanyScope::class)->findOrFail($this->editingId);
            $this->authorize('update', $category);
            $data = collect($validated)->except('company_id')->toArray();
            if ($this->isSuperAdmin && $this->company_id) {
                $data['company_id'] = $this->company_id;
            }
            $category->update($data);
            session()->flash('status', 'Categoria atualizada.');
        } else {
            $this->authorize('create', ProductCategory::class);
            $data = collect($validated)->except('company_id')->toArray();
            if ($this->isSuperAdmin) {
                $data['company_id'] = $this->company_id;
                ProductCategory::withoutGlobalScope(CompanyScope::class)->create($data);
            } else {
                ProductCategory::create($data);
            }
            session()->flash('status', 'Categoria criada.');
        }

        $this->reset(['name', 'sort_order', 'editingId']);
    }

    public function edit(int $id): void
    {
        $category         = ProductCategory::withoutGlobalScope(CompanyScope::class)->findOrFail($id);
        $this->editingId  = $id;
        $this->name       = $category->name;
        $this->sort_order = $category->sort_order;
        $this->company_id = $category->company_id;
    }

    public function cancelEdit(): void
    {
        $this->reset(['name', 'sort_order', 'editingId']);
        if (! $this->isSuperAdmin) {
            $this->company_id = auth()->user()->companies()->first()?->id;
        }
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
    }

    public function delete(): void
    {
        $category = ProductCategory::withoutGlobalScope(CompanyScope::class)->findOrFail($this->deletingId);
        $this->authorize('delete', $category);
        $category->delete();
        $this->deletingId = null;
        session()->flash('status', 'Categoria removida.');
    }

    public function render()
    {
        $categories = $this->isSuperAdmin
            ? ProductCategory::withoutGlobalScope(CompanyScope::class)
                ->with('company')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
            : ProductCategory::orderBy('sort_order')->orderBy('name')->get();

        $companies = $this->isSuperAdmin
            ? Company::withoutGlobalScope(CompanyScope::class)
                ->where('active', true)
                ->orderBy('name')
                ->get()
            : collect();

        return view('livewire.admin.categories.index', compact('categories', 'companies'))
            ->layout('layouts.app', ['title' => 'Categorias']);
    }
}
