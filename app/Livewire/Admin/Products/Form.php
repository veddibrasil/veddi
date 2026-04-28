<?php

namespace App\Livewire\Admin\Products;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Scopes\CompanyScope;
use App\Services\Order\StockService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class Form extends Component
{
    use WithFileUploads;

    public ?Product $product = null;

    public bool $isEditing = false;

    public bool $isSuperAdmin = false;

    public bool $canSave = false;

    public ?int $company_id = null;

    public ?int $branchManagerBranchId = null; // null = não é branch_manager

    public int $product_category_id = 0;

    public string $name = '';

    public string $description = '';

    public string $price = '';

    public bool $active = true;

    public int $sort_order = 0;

    public $image;

    public array $selectedBranches = [];

    public bool $showCategoryModal = false;

    public string $newCategoryName = '';

    public array $optionGroups = [];

    public bool $trackStock = false;

    public string $initialQuantity = '0';

    public string $minQuantity = '0';

    protected function rules(): array
    {
        return [
            'company_id' => $this->isSuperAdmin ? ['required', 'integer', 'exists:companies,id'] : ['nullable'],
            'product_category_id' => ['required', 'integer', 'exists:product_categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'numeric', 'min:0.01'],
            'active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
            'image' => ['nullable', 'image', 'max:2048'],
            'selectedBranches' => ['array'],
            'selectedBranches.*' => ['exists:branches,id'],
            'optionGroups' => ['array'],
            'optionGroups.*.name' => ['required_with:optionGroups.*', 'string', 'max:150'],
            'optionGroups.*.total_qty' => ['required_with:optionGroups.*', 'integer', 'min:1'],
            'optionGroups.*.fixed' => ['boolean'],
            'optionGroups.*.options' => ['array'],
            'optionGroups.*.options.*.name' => ['required_with:optionGroups.*.options.*', 'string', 'max:150'],
            'optionGroups.*.options.*.additional_price' => ['required_with:optionGroups.*.options.*', 'numeric', 'min:0'],
            'optionGroups.*.options.*.default_qty' => ['required_with:optionGroups.*.options.*', 'integer', 'min:0'],
        ];
    }

    protected function messages(): array
    {
        return [
            'company_id.required' => 'Selecione uma empresa.',
            'product_category_id.required' => 'Selecione uma categoria.',
            'product_category_id.exists' => 'Categoria inválida.',
            'name.required' => 'Informe o nome do produto.',
            'price.required' => 'Informe o preço.',
            'price.numeric' => 'Preço inválido.',
            'price.min' => 'O preço deve ser maior que zero.',
            'image.image' => 'O arquivo deve ser uma imagem.',
            'image.max' => 'A imagem deve ter no máximo 2MB.',
            'optionGroups.*.name.required_with' => 'O nome do grupo é obrigatório.',
            'optionGroups.*.total_qty.required_with' => 'A quantidade total é obrigatória.',
            'optionGroups.*.total_qty.min' => 'A quantidade total deve ser pelo menos 1.',
            'optionGroups.*.options.*.name.required_with' => 'O nome da opção é obrigatório.',
            'optionGroups.*.options.*.additional_price.numeric' => 'O acréscimo deve ser um valor numérico.',
            'optionGroups.*.options.*.additional_price.min' => 'O acréscimo não pode ser negativo.',
        ];
    }

    public function mount(?Product $product = null): void
    {
        $user = auth()->user();
        $this->isSuperAdmin = $user->isSuperAdmin();

        if ($this->isSuperAdmin) {
            $this->canSave = true;
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');
            $isEditing = $product?->exists ?? false;
            $this->canSave = $isEditing
                ? $user->hasPermission('products.update', $company)
                : $user->hasPermission('products.create', $company);
        }

        if (! $this->isSuperAdmin) {
            $this->company_id = $user->companies()->first()?->id;
        }

        if (app()->bound('current.branch')) {
            $this->branchManagerBranchId = app('current.branch')->id;
        }

        if ($product?->exists) {
            $this->product = $product;
            $this->isEditing = true;
            $this->company_id = $product->company_id;
            $this->fill($product->only('product_category_id', 'name', 'active', 'sort_order'));
            $this->description = $product->description ?? '';
            $this->price = (string) $product->price;
            $this->selectedBranches = $product->branches->pluck('id')->map(fn ($id) => (string) $id)->toArray();

            $this->optionGroups = $product->optionGroups->map(function ($group) {
                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'total_qty' => (string) $group->total_qty,
                    'fixed' => (bool) $group->fixed,
                    'sort_order' => $group->sort_order,
                    'options' => $group->options->map(fn ($opt) => [
                        'id' => $opt->id,
                        'name' => $opt->name,
                        'additional_price' => number_format((float) $opt->additional_price, 2, '.', ''),
                        'default_qty' => (string) $opt->default_qty,
                        'sort_order' => $opt->sort_order,
                    ])->toArray(),
                ];
            })->toArray();
        } elseif ($this->branchManagerBranchId) {
            // Branch manager só enxerga e opera sua própria filial
            $this->selectedBranches = [(string) $this->branchManagerBranchId];
        } else {
            // Company admin: pré-seleciona todas as filiais ativas.
            // CompanyScope global já filtra pelo tenant correto.
            $this->selectedBranches = Branch::where('active', true)
                ->orderBy('name')
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->toArray();
        }
    }

    public function openCategoryModal(): void
    {
        $this->newCategoryName = '';
        $this->showCategoryModal = true;
    }

    public function saveCategory(): void
    {
        $this->validate(['newCategoryName' => ['required', 'string', 'max:100']], [
            'newCategoryName.required' => 'Informe o nome da categoria.',
            'newCategoryName.max' => 'O nome deve ter no máximo 100 caracteres.',
        ]);

        $data = ['name' => $this->newCategoryName, 'active' => true];

        if ($this->isSuperAdmin && $this->company_id) {
            $data['company_id'] = $this->company_id;
        } elseif (! $this->isSuperAdmin) {
            $data['company_id'] = $this->company_id;
        }

        $category = ProductCategory::withoutGlobalScope(CompanyScope::class)->create($data);

        $this->product_category_id = $category->id;
        $this->newCategoryName = '';
        $this->showCategoryModal = false;
    }

    public function addOptionGroup(): void
    {
        $this->optionGroups[] = [
            'id' => null,
            'name' => '',
            'total_qty' => '100',
            'fixed' => false,
            'sort_order' => count($this->optionGroups),
            'options' => [],
        ];
    }

    public function removeOptionGroup(int $index): void
    {
        array_splice($this->optionGroups, $index, 1);
        foreach ($this->optionGroups as $i => &$group) {
            $group['sort_order'] = $i;
        }
    }

    public function addOption(int $groupIndex): void
    {
        $this->optionGroups[$groupIndex]['options'][] = [
            'id' => null,
            'name' => '',
            'additional_price' => '0.00',
            'default_qty' => '0',
            'sort_order' => count($this->optionGroups[$groupIndex]['options']),
        ];
    }

    public function removeOption(int $groupIndex, int $optionIndex): void
    {
        array_splice($this->optionGroups[$groupIndex]['options'], $optionIndex, 1);
        foreach ($this->optionGroups[$groupIndex]['options'] as $i => &$opt) {
            $opt['sort_order'] = $i;
        }
    }

    public function selectAllBranches(): void
    {
        $query = $this->branchManagerBranchId
            ? Branch::where('id', $this->branchManagerBranchId)->where('active', true)
            : Branch::where('active', true)->orderBy('name');

        $this->selectedBranches = $query->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->toArray();
    }

    public function deselectAllBranches(): void
    {
        $this->selectedBranches = [];
    }

    public function save(): void
    {
        abort_unless($this->canSave, 403);

        $validated = $this->validate($this->rules(), $this->messages());

        $imagePath = $this->isEditing ? $this->product->image_path : null;
        if ($this->image) {
            if ($this->isEditing && $this->product->image_path) {
                Storage::disk('s3')->delete($this->product->image_path);
            }
            $stored = $this->image->store('products', 's3');
            if ($stored === false) {
                $this->addError('image', 'Falha ao fazer upload da imagem. Verifique a configuração do storage.');

                return;
            }
            $imagePath = $stored;
        }

        $data = [
            'product_category_id' => $validated['product_category_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'active' => $validated['active'],
            'sort_order' => $validated['sort_order'],
            'image_path' => $imagePath,
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
                    'available' => $existing?->available ?? true,
                    'track_stock' => $existing?->track_stock ?? false,
                    'min_quantity' => $existing?->min_quantity ?? 0,
                    'quantity' => $existing?->quantity ?? 0,
                ];

                Cache::forget("menu:branch:{$branchId}:company:{$this->company_id}");

            }
        } else {
            foreach ($this->selectedBranches as $branchId) {
                $branchSync[$branchId] = [
                    'available' => true,
                    'track_stock' => $this->trackStock,
                    'min_quantity' => (int) $this->minQuantity,
                    'quantity' => 0,
                ];

                Cache::forget("menu:branch:{$branchId}:company:{$this->company_id}");

            }
        }
        $product->branches()->sync($branchSync);

        // Sync option groups
        $keptGroupIds = collect($this->optionGroups)->pluck('id')->filter()->values();
        ProductOptionGroup::where('product_id', $product->id)
            ->whereNotIn('id', $keptGroupIds)
            ->delete();

        foreach ($this->optionGroups as $groupData) {
            $group = $groupData['id']
                ? (ProductOptionGroup::find($groupData['id']) ?? new ProductOptionGroup(['product_id' => $product->id]))
                : new ProductOptionGroup(['product_id' => $product->id]);

            $group->fill([
                'name' => $groupData['name'],
                'total_qty' => (int) $groupData['total_qty'],
                'fixed' => (bool) ($groupData['fixed'] ?? false),
                'sort_order' => $groupData['sort_order'],
            ])->save();

            $keptOptionIds = collect($groupData['options'])->pluck('id')->filter()->values();
            ProductOption::where('product_option_group_id', $group->id)
                ->whereNotIn('id', $keptOptionIds)
                ->delete();

            foreach ($groupData['options'] as $optData) {
                $option = $optData['id']
                    ? (ProductOption::find($optData['id']) ?? new ProductOption(['product_option_group_id' => $group->id]))
                    : new ProductOption(['product_option_group_id' => $group->id]);

                $option->fill([
                    'name' => $optData['name'],
                    'additional_price' => (float) $optData['additional_price'],
                    'default_qty' => (int) ($optData['default_qty'] ?? 0),
                    'sort_order' => $optData['sort_order'],
                ])->save();
            }
        }

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
            $branches = $this->branchManagerBranchId
                ? Branch::where('id', $this->branchManagerBranchId)->where('active', true)->get()
                : Branch::where('active', true)->orderBy('name')->get();
            $companies = collect();
        }

        return view('livewire.admin.products.form', compact('categories', 'branches', 'companies'))
            ->layout('layouts.app', ['title' => $this->isEditing ? 'Editar Produto' : 'Novo Produto']);
    }
}
