<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

trait HasCatalog
{
    public function selectCategory(?int $categoryId): void
    {
        $this->activeCategoryId = $categoryId;
        $this->search = '';
    }

    #[Computed]
    public function branches(): \Illuminate\Database\Eloquent\Collection
    {
        $company = app()->bound('current.company') ? app('current.company') : null;

        if (! $company) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        // Mesma chave usada em OrderChat::getBranchesProperty() — invalidada em
        // Branches/Form.php, DeliverySettings.php e ServiceCharges.php.
        return Cache::remember(
            "branches:company:{$company->id}",
            now()->addMinutes(10),
            fn () => Branch::where('company_id', $company->id)
                ->where('active', true)
                ->with('deliverySetting')
                ->orderBy('name')
                ->get()
        );
    }

    #[Computed]
    public function categories(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->selectedBranchId) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        $branchId = $this->selectedBranchId;

        return Cache::remember(
            "pdv:categories:branch:{$branchId}",
            now()->addMinutes(5),
            fn () => ProductCategory::withoutGlobalScopes()
                ->whereHas('products', fn ($q) => $q
                    ->where('active', true)
                    ->where('available_in_pdv', true)
                    ->whereHas('branches', fn ($bq) => $bq
                        ->where('branches.id', $branchId)
                        ->where('branch_product.available', true)
                    )
                )
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    #[Computed]
    public function products(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->selectedBranchId) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        $branchId = $this->selectedBranchId;

        $all = Cache::remember(
            "pdv:products:branch:{$branchId}",
            now()->addMinutes(5),
            fn () => Product::withoutGlobalScopes()
                ->join('product_categories', 'product_categories.id', '=', 'products.product_category_id')
                ->whereHas('branches', fn ($q) => $q
                    ->where('branches.id', $branchId)
                    ->where('branch_product.available', true)
                )
                ->where('products.active', true)
                ->where('products.available_in_pdv', true)
                ->with([
                    'optionGroups.options' => fn ($q) => $q->where('active', true),
                    'optionGroups.inactiveOptions',
                ])
                ->orderBy('product_categories.sort_order')
                ->orderBy('product_categories.name')
                ->orderBy('products.sort_order')
                ->orderBy('products.name')
                ->select('products.*')
                ->get()
        );

        return $all
            ->when($this->activeCategoryId, fn ($c) => $c->where('product_category_id', $this->activeCategoryId))
            ->when(filled($this->search), fn ($c) => $c->filter(fn ($p) => str_contains(mb_strtolower($p->name), mb_strtolower($this->search))))
            ->values();
    }

    #[Computed]
    public function productStocks(): array
    {
        if (! $this->selectedBranchId) {
            return [];
        }

        return DB::table('branch_product')
            ->where('branch_id', $this->selectedBranchId)
            ->where('track_stock', true)
            ->pluck('quantity', 'product_id')
            ->toArray();
    }
}
