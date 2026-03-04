<?php

namespace App\Livewire\Admin\Products;

use App\Models\Product;
use App\Models\ProductCategory;
use Livewire\Component;

class Index extends Component
{
    public string $search        = '';
    public string $categoryFilter = '';
    public ?int $deletingId      = null;

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
        Product::findOrFail($this->deletingId)->delete();
        $this->deletingId = null;
        session()->flash('status', 'Produto removido.');
    }

    public function render()
    {
        $products = Product::with('category')
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->categoryFilter, fn ($q) => $q->where('product_category_id', $this->categoryFilter))
            ->orderBy('product_category_id')
            ->orderBy('sort_order')
            ->get();

        return view('livewire.admin.products.index', [
            'products'   => $products,
            'categories' => ProductCategory::orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Produtos']);
    }
}
