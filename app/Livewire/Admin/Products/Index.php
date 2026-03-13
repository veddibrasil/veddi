<?php

namespace App\Livewire\Admin\Products;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\CompanyScope;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;
    public string $search         = '';
    public string $categoryFilter = '';
    public string $companyFilter  = '';
    public ?int $deletingId       = null;

    public bool $isSuperAdmin = false;

    public function mount(): void
    {
        $this->isSuperAdmin = auth()->user()->isSuperAdmin();
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingCategoryFilter(): void { $this->resetPage(); }
    public function updatingCompanyFilter(): void { $this->resetPage(); }

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
        $product->delete();
        $this->deletingId = null;
        session()->flash('status', 'Produto removido.');
    }

    public function render()
    {
        $productQuery = $this->isSuperAdmin
            ? Product::withoutGlobalScope(CompanyScope::class)->with(['category', 'company'])
            : Product::with('category');

        $products = $productQuery
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->categoryFilter, fn ($q) => $q->where('product_category_id', $this->categoryFilter))
            ->when($this->isSuperAdmin && $this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter))
            ->orderBy('product_category_id')
            ->orderBy('sort_order')
            ->paginate(15);

        $categoryQuery = $this->isSuperAdmin
            ? ProductCategory::withoutGlobalScope(CompanyScope::class)
                ->when($this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter))
                ->orderBy('name')
            : ProductCategory::orderBy('name');

        $companies = $this->isSuperAdmin
            ? Company::withoutGlobalScope(CompanyScope::class)
                ->where('active', true)
                ->orderBy('name')
                ->get()
            : collect();

        return view('livewire.admin.products.index', [
            'products'     => $products,
            'categories'   => $categoryQuery->get(),
            'companies'    => $companies,
            'isSuperAdmin' => $this->isSuperAdmin,
        ])->layout('layouts.app', ['title' => 'Produtos']);
    }
}
