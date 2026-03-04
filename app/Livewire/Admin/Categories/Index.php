<?php

namespace App\Livewire\Admin\Categories;

use App\Models\ProductCategory;
use Livewire\Component;

class Index extends Component
{
    public string $name       = '';
    public int $sort_order    = 0;
    public ?int $editingId    = null;
    public ?int $deletingId   = null;

    protected function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:100'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Informe o nome da categoria.',
        ];
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules(), $this->messages());

        if ($this->editingId) {
            ProductCategory::findOrFail($this->editingId)->update($validated);
            session()->flash('status', 'Categoria atualizada.');
        } else {
            ProductCategory::create($validated);
            session()->flash('status', 'Categoria criada.');
        }

        $this->reset(['name', 'sort_order', 'editingId']);
    }

    public function edit(int $id): void
    {
        $category      = ProductCategory::findOrFail($id);
        $this->editingId   = $id;
        $this->name        = $category->name;
        $this->sort_order  = $category->sort_order;
    }

    public function cancelEdit(): void
    {
        $this->reset(['name', 'sort_order', 'editingId']);
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
        ProductCategory::findOrFail($this->deletingId)->delete();
        $this->deletingId = null;
        session()->flash('status', 'Categoria removida.');
    }

    public function render()
    {
        return view('livewire.admin.categories.index', [
            'categories' => ProductCategory::orderBy('sort_order')->orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Categorias']);
    }
}
