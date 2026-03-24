<?php

namespace App\Livewire\Admin\Products;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\CompanyScope;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;

class Form extends Component
{
    use WithFileUploads;

    public ?Product $product  = null;
    public bool $isEditing    = false;

    public bool $isSuperAdmin          = false;
    public ?int $company_id            = null;
    public ?int $branchManagerBranchId = null; // null = não é branch_manager

    public int    $product_category_id = 0;
    public string $name                = '';
    public string $description         = '';
    public string $price               = '';
    public bool   $active              = true;
    public int    $sort_order          = 0;
    public $image;

    public array $selectedBranches = [];

    public bool   $trackStock       = false;
    public string $initialQuantity  = '0';
    public string $minQuantity      = '0';

    protected function rules(): array
    {
        return [
            'company_id'          => $this->isSuperAdmin ? ['required', 'integer', 'exists:companies,id'] : ['nullable'],
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
            'company_id.required'          => 'Selecione uma empresa.',
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
        $user = auth()->user();
        $this->isSuperAdmin = $user->isSuperAdmin();

        if (! $this->isSuperAdmin) {
            $this->company_id = $user->companies()->first()?->id;
        }

        if (app()->bound('current.branch')) {
            $this->branchManagerBranchId = app('current.branch')->id;
        }

        if ($product?->exists) {
            $this->product    = $product;
            $this->isEditing  = true;
            $this->company_id = $product->company_id;
            $this->fill($product->only('product_category_id', 'name', 'active', 'sort_order'));
            $this->description      = $product->description ?? '';
            $this->price            = (string) $product->price;
            $this->selectedBranches = $product->branches->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        } elseif ($this->branchManagerBranchId) {
            // Pré-seleciona a filial do gerente em novos produtos
            $this->selectedBranches = [(string) $this->branchManagerBranchId];
        }
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules(), $this->messages());

        $imagePath = $this->isEditing ? $this->product->image_path : null;
        if ($this->image) {
            $stored = $this->image->storePublicly('products', 's3');
            if ($stored === false) {
                $this->addError('image', 'Falha ao fazer upload da imagem. Verifique a configuração do storage.');
                return;
            }
            $imagePath = $stored;
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
            if ($this->isSuperAdmin && $this->company_id) {
                $data['company_id'] = $this->company_id;
            }
            Product::withoutGlobalScope(CompanyScope::class)
                ->where('id', $this->product->id)
                ->update($data);
            $product = $this->product->refresh();
            session()->flash('status', 'Produto atualizado.');
        } else {
            if ($this->isSuperAdmin) {
                $data['company_id'] = $this->company_id;
                $product = Product::withoutGlobalScope(CompanyScope::class)->create($data);
            } else {
                $product = Product::create($data);
            }
            session()->flash('status', 'Produto criado.');
        }

        // Sync branches
        $branchSync = [];
        if ($this->isEditing) {
            $existingPivots = DB::table('branch_product')
                ->where('product_id', $product->id)
                ->get()
                ->keyBy('branch_id');

            foreach ($this->selectedBranches as $branchId) {
                $existing = $existingPivots->get($branchId);
                $branchSync[$branchId] = [
                    'available'    => $existing?->available ?? true,
                    'track_stock'  => $existing?->track_stock ?? false,
                    'min_quantity' => $existing?->min_quantity ?? 0,
                    'quantity'     => $existing?->quantity ?? 0,
                ];
            }
        } else {
            foreach ($this->selectedBranches as $branchId) {
                $branchSync[$branchId] = [
                    'available'    => true,
                    'track_stock'  => $this->trackStock,
                    'min_quantity' => (int) $this->minQuantity,
                    'quantity'     => 0,
                ];
            }
        }
        $product->branches()->sync($branchSync);

        // Define estoque inicial para produtos novos
        if (! $this->isEditing && $this->trackStock && (int) $this->initialQuantity > 0) {
            $service = app(StockService::class);
            foreach ($this->selectedBranches as $branchId) {
                $branch = Branch::withoutGlobalScope(CompanyScope::class)->find($branchId);
                if ($branch) {
                    $service->setQuantity($branch, $product, (int) $this->initialQuantity, 'Estoque inicial', auth()->user());
                }
            }
        }

        $this->redirect(route('admin.products.index'));
    }

    public function render()
    {
        if ($this->isSuperAdmin) {
            $categories = ProductCategory::withoutGlobalScope(CompanyScope::class)
                ->when($this->company_id, fn ($q) => $q->where('company_id', $this->company_id))
                ->where('active', true)
                ->orderBy('name')
                ->get();

            $branches = Branch::withoutGlobalScope(CompanyScope::class)
                ->when($this->company_id, fn ($q) => $q->where('company_id', $this->company_id))
                ->where('active', true)
                ->orderBy('name')
                ->get();

            $companies = Company::withoutGlobalScope(CompanyScope::class)
                ->where('active', true)
                ->orderBy('name')
                ->get();
        } else {
            $categories = ProductCategory::where('active', true)->orderBy('name')->get();
            $branches   = $this->branchManagerBranchId
                ? Branch::where('id', $this->branchManagerBranchId)->where('active', true)->get()
                : Branch::where('active', true)->orderBy('name')->get();
            $companies  = collect();
        }

        return view('livewire.admin.products.form', compact('categories', 'branches', 'companies'))
            ->layout('layouts.app', ['title' => $this->isEditing ? 'Editar Produto' : 'Novo Produto']);
    }
}
