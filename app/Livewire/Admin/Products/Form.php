<?php

namespace App\Livewire\Admin\Products;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use Livewire\Component;
use Livewire\WithFileUploads;

class Form extends Component
{
    use WithFileUploads;

    public ?Product $product  = null;
    public bool $isEditing    = false;

    public int    $product_category_id = 0;
    public string $name                = '';
    public string $description         = '';
    public string $price               = '';
    public bool   $active              = true;
    public int    $sort_order          = 0;
    public $image;

    public array $selectedBranches = [];

    protected function rules(): array
    {
        return [
            'product_category_id' => ['required', 'integer', 'exists:product_categories,id'],
            'name'                => ['required', 'string', 'max:150'],
            'description'         => ['nullable', 'string', 'max:500'],
            'price'               => ['required', 'numeric', 'min:0.01'],
            'active'              => ['boolean'],
            'sort_order'          => ['integer', 'min:0'],
            'image'               => ['nullable', 'image', 'max:2048'],
            'selectedBranches'    => ['array'],
            'selectedBranches.*'  => ['exists:branches,id'],
        ];
    }

    protected function messages(): array
    {
        return [
            'product_category_id.required' => 'Selecione uma categoria.',
            'product_category_id.exists'   => 'Categoria inválida.',
            'name.required'                => 'Informe o nome do produto.',
            'price.required'               => 'Informe o preço.',
            'price.numeric'                => 'Preço inválido.',
            'price.min'                    => 'O preço deve ser maior que zero.',
            'image.image'                  => 'O arquivo deve ser uma imagem.',
            'image.max'                    => 'A imagem deve ter no máximo 2MB.',
        ];
    }

    public function mount(?Product $product = null): void
    {
        if ($product?->exists) {
            $this->product    = $product;
            $this->isEditing  = true;
            $this->fill($product->only('product_category_id', 'name', 'active', 'sort_order'));
            $this->description = $product->description ?? '';
            $this->price            = (string) $product->price;
            $this->selectedBranches = $product->branches->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        }
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules(), $this->messages());

        $imagePath = $this->isEditing ? $this->product->image_path : null;
        if ($this->image) {
            $imagePath = $this->image->store('products', 'public');
        }

        $data = [
            'product_category_id' => $validated['product_category_id'],
            'name'                => $validated['name'],
            'description'         => $validated['description'] ?? null,
            'price'               => $validated['price'],
            'active'              => $validated['active'],
            'sort_order'          => $validated['sort_order'],
            'image_path'          => $imagePath,
        ];

        if ($this->isEditing) {
            $this->product->update($data);
            $product = $this->product;
            session()->flash('status', 'Produto atualizado.');
        } else {
            $product = Product::create($data);
            session()->flash('status', 'Produto criado.');
        }

        // Sync branches
        $branchSync = [];
        foreach ($this->selectedBranches as $branchId) {
            $branchSync[$branchId] = ['available' => true];
        }
        $product->branches()->sync($branchSync);

        $this->redirect(route('admin.products.index'));
    }

    public function render()
    {
        return view('livewire.admin.products.form', [
            'categories' => ProductCategory::where('active', true)->orderBy('name')->get(),
            'branches'   => Branch::where('active', true)->orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => $this->isEditing ? 'Editar Produto' : 'Novo Produto']);
    }
}
