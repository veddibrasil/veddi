<?php

namespace App\Livewire\Admin\Categories;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\CompanyScope;
use App\Services\Order\MenuCache;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $name = '';

    public string $station = '';

    public int $sort_order = 0;

    public bool $active = true;

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public bool $isSuperAdmin = false;

    public ?int $company_id = null;

    public bool $canCreate = false;

    public bool $canUpdate = false;

    public bool $canDelete = false;

    protected function rules(): array
    {
        $companyRule = $this->isSuperAdmin
            ? ['required', 'integer', 'exists:companies,id']
            : ['nullable'];

        return [
            'company_id' => $companyRule,
            'name' => ['required', 'string', 'max:100'],
            'station' => ['nullable', 'in:cozinha,bar'],
            'sort_order' => ['integer', 'min:0'],
            'active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'company_id.required' => 'Selecione uma empresa.',
            'name.required' => 'Informe o nome da categoria.',
        ];
    }

    public function mount(): void
    {
        $user = auth()->user();
        $this->isSuperAdmin = $user->isSuperAdmin();

        if ($this->isSuperAdmin) {
            $this->canCreate = true;
            $this->canUpdate = true;
            $this->canDelete = true;
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');
            $this->canCreate = $user->hasPermission('categories.create', $company);
            $this->canUpdate = $user->hasPermission('categories.update', $company);
            $this->canDelete = $user->hasPermission('categories.delete', $company);
            $this->company_id = $user->companies()->where('companies.id', $company->id)->first()?->id
                ?? $user->companies()->first()?->id;
        } else {
            $this->company_id = $user->companies()->first()?->id;
        }
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules(), $this->messages());
        $validated['station'] = $validated['station'] !== '' ? $validated['station'] : null;

        if ($this->editingId) {
            $category = ProductCategory::withoutGlobalScope(CompanyScope::class)->findOrFail($this->editingId);
            $this->authorize('update', $category);
            $data = collect($validated)->except('company_id')->toArray();
            if ($this->isSuperAdmin && $this->company_id) {
                $data['company_id'] = $this->company_id;
            }
            $category->update($data);
            $companyId = $category->company_id;
            session()->flash('status', 'Categoria atualizada.');
        } else {
            $this->authorize('create', ProductCategory::class);
            $data = collect($validated)->except('company_id')->toArray();
            if ($this->isSuperAdmin) {
                $data['company_id'] = $this->company_id;
                $created = ProductCategory::withoutGlobalScope(CompanyScope::class)->create($data);
            } else {
                $created = ProductCategory::create($data);
            }
            $companyId = $created->company_id;
            session()->flash('status', 'Categoria criada.');
        }

        // Nome, ordem e ativação aparecem no cardápio do chat, que fica em cache por filial.
        app(MenuCache::class)->forgetCompany((int) $companyId);

        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $category = ProductCategory::withoutGlobalScope(CompanyScope::class)->findOrFail($id);
        $this->authorize('view', $category);
        $this->editingId = $id;
        $this->name = $category->name;
        $this->station = $category->station ?? '';
        $this->sort_order = $category->sort_order;
        $this->active = (bool) $category->active;
        $this->company_id = $category->company_id;
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
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

        // A FK products.product_category_id é cascadeOnDelete: excluir a categoria apagaria os produtos
        // (ou estouraria a FK de order_items quando algum já foi vendido). Conta inclusive os removidos
        // logicamente (withoutGlobalScopes tira o SoftDeletingScope) porque eles também seguram a FK.
        $productsCount = Product::withoutGlobalScopes()->where('product_category_id', $category->id)->count();

        if ($productsCount > 0) {
            $this->deletingId = null;
            session()->flash('error', "Não é possível excluir \"{$category->name}\": ela ainda tem {$productsCount} produto(s), inclusive os desativados por terem pedidos. Mova os produtos para outra categoria ou desative a categoria.");

            return;
        }

        $companyId = $category->company_id;
        $category->delete();
        app(MenuCache::class)->forgetCompany((int) $companyId);

        $this->deletingId = null;
        session()->flash('status', 'Categoria removida.');
    }

    private function resetForm(): void
    {
        $this->reset(['name', 'station', 'sort_order', 'editingId']);
        $this->active = true;
        $this->resetValidation();
    }

    public function render()
    {
        $categories = $this->isSuperAdmin
            ? ProductCategory::withoutGlobalScope(CompanyScope::class)
                ->with('company')
                ->withCount(['products' => fn ($q) => $q->withoutGlobalScopes()])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->paginate(15)
            : ProductCategory::withCount(['products' => fn ($q) => $q->withoutGlobalScopes()])
                ->orderBy('sort_order')->orderBy('name')->paginate(15);

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
