<?php

namespace App\Livewire\Admin\Products;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $categoryFilter = '';

    public string $companyFilter = '';

    public string $sort = 'menu';

    public ?int $deletingId = null;

    public bool $isSuperAdmin = false;

    public bool $canCreate = false;

    public bool $canUpdate = false;

    public bool $canDelete = false;

    public ?int $lockedBranchId = null; // branch_manager: escopo fixo de filial

    public function mount(): void
    {
        $user = auth()->user();
        $this->isSuperAdmin = $user->isSuperAdmin();

        if ($this->isSuperAdmin) {
            $this->canCreate = $this->canUpdate = $this->canDelete = true;
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');
            $this->canCreate = $user->hasPermission('products.create', $company);
            $this->canUpdate = $user->hasPermission('products.update', $company);
            $this->canDelete = $user->hasPermission('products.delete', $company);
        }

        if (app()->bound('current.branch')) {
            $this->lockedBranchId = app('current.branch')->id;
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatingCompanyFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSort(): void
    {
        $this->resetPage();
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
        $product = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($this->deletingId);
        $this->authorize('delete', $product);

        if ($product->orderItems()->exists()) {
            $product->delete();
            session()->flash('status', 'Produto desativado pois possui pedidos vinculados.');
        } else {
            $product->forceDelete();
            session()->flash('status', 'Produto removido.');
        }

        $this->deletingId = null;
    }

    public function render()
    {
        $productQuery = $this->isSuperAdmin
            ? Product::withoutGlobalScope(CompanyScope::class)->with(['category', 'company'])
            : Product::with('category');

        $productsQuery = $productQuery
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->categoryFilter, fn ($q) => $q->where('product_category_id', $this->categoryFilter))
            ->when($this->isSuperAdmin && $this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter))
            ->when($this->lockedBranchId, fn ($q) => $q->whereHas('branches', fn ($bq) => $bq->where('branches.id', $this->lockedBranchId)));

        $productsQuery = match ($this->sort) {
            'price_desc' => $productsQuery->orderByDesc('price')->orderBy('name'),
            'price_asc' => $productsQuery->orderBy('price')->orderBy('name'),
            'created_desc' => $productsQuery->orderByDesc('created_at')->orderBy('name'),
            default => $productsQuery->orderBy('product_category_id')->orderBy('sort_order')->orderBy('name'),
        };

        $products = $productsQuery->paginate(15);

        $categoryQuery = $this->isSuperAdmin
            ? ProductCategory::withoutGlobalScope(CompanyScope::class)
                ->when($this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter))
                ->orderBy('name')
            : ProductCategory::orderBy('name');

        $companies = $this->isSuperAdmin
            ? Cache::remember('companies:active', now()->addHours(24), fn () => Company::withoutGlobalScope(CompanyScope::class)
                ->where('active', true)
                ->orderBy('name')
                ->get()
            )
            : collect();

        return view('livewire.admin.products.index', [
            'products' => $products,
            'categories' => $categoryQuery->get(),
            'companies' => $companies,
            'isSuperAdmin' => $this->isSuperAdmin,
        ])->layout('layouts.app', ['title' => 'Produtos']);
    }
}
