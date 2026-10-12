<?php

namespace App\Livewire\Admin\Products;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\CompanyScope;
use App\Services\Order\MenuCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public bool $reorderMode = false;

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

    public function toggleReorderMode(): void
    {
        $this->reorderMode = ! $this->reorderMode;
    }

    public function updateOrder(int $categoryId, array $orderedIds): void
    {
        if (! $this->canUpdate) {
            abort(403);
        }

        $category = $this->isSuperAdmin
            ? ProductCategory::withoutGlobalScope(CompanyScope::class)->findOrFail($categoryId)
            : ProductCategory::findOrFail($categoryId);

        DB::transaction(function () use ($category, $orderedIds) {
            foreach ($orderedIds as $index => $productId) {
                Product::withoutGlobalScope(CompanyScope::class)
                    ->where('id', $productId)
                    ->where('product_category_id', $category->id)
                    ->where('company_id', $category->company_id)
                    ->update(['sort_order' => $index]);
            }
        });

        $this->forgetMenuCache($category->company_id);
    }

    public function updateCategoryOrder(array $orderedIds): void
    {
        if (! $this->canUpdate) {
            abort(403);
        }

        $categories = ($this->isSuperAdmin
            ? ProductCategory::withoutGlobalScope(CompanyScope::class)
            : ProductCategory::query()
        )->whereIn('id', $orderedIds)->get()->keyBy('id');

        if ($categories->isEmpty()) {
            return;
        }

        $companyId = $categories->first()->company_id;

        DB::transaction(function () use ($orderedIds, $categories, $companyId) {
            foreach ($orderedIds as $index => $categoryId) {
                $category = $categories->get($categoryId);

                if (! $category || $category->company_id !== $companyId) {
                    continue;
                }

                $category->update(['sort_order' => $index]);
            }
        });

        $this->forgetMenuCache($companyId);
    }

    /**
     * Alternativa ao arrastar (teclado/toque): sobe ou desce um produto dentro da categoria.
     */
    public function moveProduct(int $productId, string $direction): void
    {
        if (! $this->canUpdate) {
            abort(403);
        }

        $product = $this->isSuperAdmin
            ? Product::withoutGlobalScope(CompanyScope::class)->findOrFail($productId)
            : Product::findOrFail($productId);

        $orderedIds = Product::withoutGlobalScope(CompanyScope::class)
            ->where('product_category_id', $product->product_category_id)
            ->where('company_id', $product->company_id)
            ->when($this->lockedBranchId, fn ($q) => $q->whereHas('branches', fn ($bq) => $bq->where('branches.id', $this->lockedBranchId)))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('id')
            ->all();

        $this->updateOrder($product->product_category_id, $this->shiftId($orderedIds, $productId, $direction));
    }

    /**
     * Alternativa ao arrastar (teclado/toque): sobe ou desce uma categoria no cardápio.
     */
    public function moveCategory(int $categoryId, string $direction): void
    {
        if (! $this->canUpdate) {
            abort(403);
        }

        $category = $this->isSuperAdmin
            ? ProductCategory::withoutGlobalScope(CompanyScope::class)->findOrFail($categoryId)
            : ProductCategory::findOrFail($categoryId);

        $orderedIds = ProductCategory::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $category->company_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->updateCategoryOrder($this->shiftId($orderedIds, $categoryId, $direction));
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function shiftId(array $ids, int $id, string $direction): array
    {
        $index = array_search($id, $ids, true);
        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index === false || ! in_array($direction, ['up', 'down'], true) || ! isset($ids[$target])) {
            return $ids;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        return $ids;
    }

    private function forgetMenuCache(int $companyId): void
    {
        app(MenuCache::class)->forgetCompany($companyId);
    }

    public function delete(): void
    {
        $product = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($this->deletingId);
        $this->authorize('delete', $product);

        $companyId = $product->company_id;

        $product->active = false;
        $product->save();

        if ($product->orderItems()->exists()) {
            $product->delete();
            session()->flash('status', 'Produto desativado pois possui pedidos vinculados.');
        } else {
            $product->forceDelete();
            session()->flash('status', 'Produto removido.');
        }

        $this->forgetMenuCache($companyId);

        $this->deletingId = null;
    }

    public function render()
    {
        $productQuery = $this->isSuperAdmin
            ? Product::withoutGlobalScope(CompanyScope::class)->with(['category', 'company'])
            : Product::with('category');

        $productsQuery = $productQuery
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
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

        $canReorder = ! $this->isSuperAdmin || $this->companyFilter;

        $reorderGroups = ($this->reorderMode && $canReorder)
            ? (clone $categoryQuery)->reorder('sort_order')->orderBy('id')->with(['products' => function ($q) {
                $q->when($this->isSuperAdmin, fn ($qq) => $qq->withoutGlobalScope(CompanyScope::class))
                    ->when($this->lockedBranchId, fn ($qq) => $qq->whereHas('branches', fn ($bq) => $bq->where('branches.id', $this->lockedBranchId)))
                    ->orderBy('sort_order')->orderBy('name');
            }])->get()
            : collect();

        $companies = $this->isSuperAdmin
            ? Cache::remember('companies:active', now()->addHours(24), fn () => Company::withoutGlobalScope(CompanyScope::class)
                ->where('active', true)
                ->orderBy('name')
                ->get()
            )
            : collect();

        return view('livewire.admin.products.index', [
            'products' => $products,
            'reorderGroups' => $reorderGroups,
            'canReorder' => $canReorder,
            'categories' => $categoryQuery->get(),
            'companies' => $companies,
            'isSuperAdmin' => $this->isSuperAdmin,
        ])->layout('layouts.app', ['title' => 'Produtos']);
    }
}
