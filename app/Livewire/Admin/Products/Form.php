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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

    public bool $promo_price_enabled = false;

    public string $promo_price_type = 'fixed';

    public string $promo_price_value = '';

    public bool $active = true;

    public int $sort_order = 0;

    public $image;

    public array $selectedBranches = [];

    public bool $showCategoryModal = false;

    public string $newCategoryName = '';

    public array $optionGroups = [];

    public array $groupImages = [];

    public array $optionImages = [];

    public bool $showGroupPicker = false;

    public bool $isVariant = false;

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
            'price' => $this->isVariant ? ['nullable', 'numeric', 'min:0'] : ['required', 'numeric', 'min:0.01'],
            'promo_price_enabled' => $this->isVariant ? ['boolean'] : ['boolean'],
            'promo_price_type' => $this->isVariant ? ['nullable'] : ['exclude_unless:promo_price_enabled,true', 'required', Rule::in(['fixed', 'percentage'])],
            'promo_price_value' => $this->isVariant ? ['nullable'] : ['exclude_unless:promo_price_enabled,true', 'required', 'numeric', 'min:0.01'],
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
            'optionGroups.*.options.*.active' => ['boolean'],
            'optionGroups.*.options.*.description' => ['nullable', 'string', 'max:255'],
            'optionGroups.*.options.*.additional_price' => ['required_with:optionGroups.*.options.*', 'numeric', 'min:0'],
            'optionGroups.*.options.*.default_qty' => ['required_with:optionGroups.*.options.*', 'integer', 'min:0'],
            'groupImages.*' => ['nullable', 'image', 'max:2048'],
            'optionImages.*' => ['nullable', 'image', 'max:2048'],
        ];
    }

    protected function messages(): array
    {
        return [
            'company_id.required' => 'Selecione uma empresa.',
            'product_category_id.required' => 'Selecione uma categoria.',
            'product_category_id.exists' => 'Categoria inválida.',
            'name.required' => 'Informe o nome do produto.',
            'price.required' => 'Informe o preço base do produto.',
            'price.numeric' => 'Preço inválido.',
            'price.min' => 'O preço deve ser maior que zero.',
            'promo_price_type.required' => 'Selecione o tipo de promoção.',
            'promo_price_value.required' => 'Informe o valor da promoção.',
            'image.image' => 'O arquivo deve ser uma imagem.',
            'image.max' => 'A imagem deve ter no máximo 2MB.',
            'groupImages.*.image' => 'O arquivo do grupo deve ser uma imagem.',
            'groupImages.*.max' => 'A imagem do grupo deve ter no máximo 2MB.',
            'optionImages.*.image' => 'O arquivo da opção deve ser uma imagem.',
            'optionImages.*.max' => 'A imagem da opção deve ter no máximo 2MB.',
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
            $this->isVariant = (bool) $product->is_variant;
            $this->promo_price_enabled = (bool) $product->promo_price_enabled;
            $this->promo_price_type = (string) ($product->promo_price_type ?: 'fixed');
            $this->promo_price_value = $product->promo_price_value !== null ? (string) $product->promo_price_value : '';
            $this->selectedBranches = $product->branches->pluck('id')->map(fn ($id) => (string) $id)->toArray();

            $this->optionGroups = $product->optionGroups->map(function ($group) {
                return [
                    'key' => 'gid-'.$group->id,
                    'group_id' => $group->id,
                    'name' => $group->name,
                    'image_path' => $group->image_path,
                    'image_url' => $group->image_url,
                    'total_qty' => (string) $group->total_qty,
                    'fixed' => (bool) $group->fixed,
                    'sort_order' => $group->pivot->sort_order,
                    'options' => $group->options->map(fn ($opt) => [
                        'key' => 'oid-'.$opt->id,
                        'id' => $opt->id,
                        'name' => $opt->name,
                        'image_path' => $opt->image_path,
                        'image_url' => $opt->image_url,
                        'active' => (bool) $opt->active,
                        'description' => $opt->description ?? '',
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

    public function updatedIsVariant(bool $value): void
    {
        if ($value) {
            $this->price = '0';
            $this->promo_price_enabled = false;
            $this->promo_price_type = 'fixed';
            $this->promo_price_value = '';
            $this->resetValidation(['price', 'promo_price_type', 'promo_price_value']);
        }
    }

    public function updatedPromoPriceEnabled(bool $enabled): void
    {
        if ($enabled) {
            return;
        }

        $this->promo_price_type = 'fixed';
        $this->promo_price_value = '';
        $this->resetValidation(['promo_price_type', 'promo_price_value']);
    }

    public function updatedPromoPriceType(string $type): void
    {
        if (! $this->promo_price_enabled) {
            return;
        }

        $this->resetValidation(['promo_price_value']);
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
            'key' => (string) Str::uuid(),
            'group_id' => null,
            'name' => '',
            'image_path' => null,
            'image_url' => null,
            'total_qty' => '100',
            'fixed' => false,
            'sort_order' => count($this->optionGroups),
            'options' => [],
        ];
    }

    public function attachExistingGroup(int $groupId): void
    {
        $alreadyAttached = collect($this->optionGroups)
            ->pluck('group_id')
            ->contains($groupId);

        if ($alreadyAttached) {
            return;
        }

        $group = ProductOptionGroup::with('options')->find($groupId);
        if (! $group) {
            return;
        }

        $this->optionGroups[] = [
            'key' => 'gid-'.$group->id,
            'group_id' => $group->id,
            'name' => $group->name,
            'image_path' => $group->image_path,
            'image_url' => $group->image_url,
            'total_qty' => (string) $group->total_qty,
            'fixed' => (bool) $group->fixed,
            'sort_order' => count($this->optionGroups),
            'options' => $group->options->map(fn ($opt) => [
                'key' => 'oid-'.$opt->id,
                'id' => $opt->id,
                'name' => $opt->name,
                'image_path' => $opt->image_path,
                'image_url' => $opt->image_url,
                'active' => (bool) $opt->active,
                'description' => $opt->description ?? '',
                'additional_price' => number_format((float) $opt->additional_price, 2, '.', ''),
                'default_qty' => (string) $opt->default_qty,
                'sort_order' => $opt->sort_order,
            ])->toArray(),
        ];

        $this->showGroupPicker = false;
    }

    public function removeOptionGroup(int $index): void
    {
        $group = $this->optionGroups[$index] ?? null;
        if ($group) {
            unset($this->groupImages[$group['key']]);
            foreach ($group['options'] ?? [] as $opt) {
                unset($this->optionImages[$group['key'].'_'.$opt['key']]);
            }
        }
        array_splice($this->optionGroups, $index, 1);
        $this->reindexOptionGroups();
    }

    public function addOption(int $groupIndex): void
    {
        $this->optionGroups[$groupIndex]['options'][] = [
            'key' => (string) Str::uuid(),
            'id' => null,
            'name' => '',
            'image_path' => null,
            'image_url' => null,
            'active' => true,
            'description' => '',
            'additional_price' => '0.00',
            'default_qty' => '0',
            'sort_order' => count($this->optionGroups[$groupIndex]['options']),
        ];
    }

    public function clearGroupImage(string $groupKey): void
    {
        unset($this->groupImages[$groupKey]);
        foreach ($this->optionGroups as $i => $group) {
            if ($group['key'] === $groupKey) {
                $this->optionGroups[$i]['image_path'] = null;
                $this->optionGroups[$i]['image_url'] = null;
                break;
            }
        }
    }

    public function clearOptionImage(string $groupKey, string $optKey): void
    {
        $imageKey = $groupKey.'_'.$optKey;
        unset($this->optionImages[$imageKey]);
        foreach ($this->optionGroups as $gi => $group) {
            if ($group['key'] !== $groupKey) {
                continue;
            }
            foreach ($group['options'] as $oi => $opt) {
                if ($opt['key'] === $optKey) {
                    $this->optionGroups[$gi]['options'][$oi]['image_path'] = null;
                    $this->optionGroups[$gi]['options'][$oi]['image_url'] = null;
                    break 2;
                }
            }
        }
    }

    public function removeOption(int $groupIndex, int $optionIndex): void
    {
        $group = $this->optionGroups[$groupIndex] ?? null;
        $opt = $group['options'][$optionIndex] ?? null;
        if ($group && $opt) {
            unset($this->optionImages[$group['key'].'_'.$opt['key']]);
        }
        array_splice($this->optionGroups[$groupIndex]['options'], $optionIndex, 1);
        $this->reindexOptions($groupIndex);
    }

    public function moveOptionUp(int $groupIndex, int $optionIndex): void
    {
        if ($groupIndex < 0 || $groupIndex >= count($this->optionGroups)) {
            return;
        }

        $optionsCount = count($this->optionGroups[$groupIndex]['options'] ?? []);
        if ($optionIndex <= 0 || $optionIndex >= $optionsCount) {
            return;
        }

        [$this->optionGroups[$groupIndex]['options'][$optionIndex - 1], $this->optionGroups[$groupIndex]['options'][$optionIndex]]
            = [$this->optionGroups[$groupIndex]['options'][$optionIndex], $this->optionGroups[$groupIndex]['options'][$optionIndex - 1]];

        $this->reindexOptions($groupIndex);
    }

    public function moveOptionDown(int $groupIndex, int $optionIndex): void
    {
        if ($groupIndex < 0 || $groupIndex >= count($this->optionGroups)) {
            return;
        }

        $optionsCount = count($this->optionGroups[$groupIndex]['options'] ?? []);
        if ($optionIndex < 0 || $optionIndex >= $optionsCount - 1) {
            return;
        }

        [$this->optionGroups[$groupIndex]['options'][$optionIndex + 1], $this->optionGroups[$groupIndex]['options'][$optionIndex]]
            = [$this->optionGroups[$groupIndex]['options'][$optionIndex], $this->optionGroups[$groupIndex]['options'][$optionIndex + 1]];

        $this->reindexOptions($groupIndex);
    }

    public function moveOptionGroupUp(int $index): void
    {
        if ($index <= 0 || $index >= count($this->optionGroups)) {
            return;
        }

        [$this->optionGroups[$index - 1], $this->optionGroups[$index]] = [$this->optionGroups[$index], $this->optionGroups[$index - 1]];
        $this->reindexOptionGroups();
    }

    public function moveOptionGroupDown(int $index): void
    {
        if ($index < 0 || $index >= count($this->optionGroups) - 1) {
            return;
        }

        [$this->optionGroups[$index + 1], $this->optionGroups[$index]] = [$this->optionGroups[$index], $this->optionGroups[$index + 1]];
        $this->reindexOptionGroups();
    }

    private function reindexOptionGroups(): void
    {
        foreach ($this->optionGroups as $i => &$group) {
            $group['sort_order'] = $i;
        }
    }

    private function reindexOptions(int $groupIndex): void
    {
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

        foreach ($validated['optionGroups'] ?? [] as $gi => $groupData) {
            if (empty($groupData['fixed'])) {
                continue;
            }
            $totalQty = (int) $groupData['total_qty'];
            $usedQty = collect($groupData['options'] ?? [])->sum(fn ($o) => (int) ($o['default_qty'] ?? 0));
            if ($usedQty > $totalQty) {
                $this->addError("optionGroups.{$gi}.total_qty", "A soma das qtds. fixas ({$usedQty}) ultrapassa a quantidade total ({$totalQty}).");

                return;
            }
        }

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

        $isVariantProduct = $this->isVariant;

        $data = [
            'product_category_id' => $validated['product_category_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price' => $isVariantProduct ? 0 : $validated['price'],
            'is_variant' => $isVariantProduct,
            'promo_price_enabled' => $isVariantProduct ? false : (bool) $validated['promo_price_enabled'],
            'promo_price_type' => (! $isVariantProduct && $validated['promo_price_enabled']) ? $validated['promo_price_type'] : 'fixed',
            'promo_price_value' => (! $isVariantProduct && $validated['promo_price_enabled']) ? $validated['promo_price_value'] : null,
            'active' => $validated['active'],
            'sort_order' => $validated['sort_order'],
            'image_path' => $imagePath,
        ];

        if (! $isVariantProduct && ! empty($data['promo_price_enabled'])) {
            if ($data['promo_price_type'] === 'fixed' && (float) $data['promo_price_value'] >= (float) $data['price']) {
                $this->addError('promo_price_value', 'O valor promocional deve ser menor que o preço normal.');

                return;
            }

            if ($data['promo_price_type'] === 'percentage' && (float) $data['promo_price_value'] > 100.0) {
                $this->addError('promo_price_value', 'A porcentagem não pode ser maior que 100%.');

                return;
            }
        }

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
                Cache::forget("pdv:products:branch:{$branchId}");
                Cache::forget("pdv:categories:branch:{$branchId}");
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
                Cache::forget("pdv:products:branch:{$branchId}");
                Cache::forget("pdv:categories:branch:{$branchId}");
            }
        }
        $product->branches()->sync($branchSync);

        // Sync option groups
        $companyId = $product->company_id ?? $this->company_id;

        $currentAttachedIds = $product->optionGroups()->pluck('product_option_groups.id')->toArray();
        $keptGroupIds = collect($this->optionGroups)->pluck('group_id')->filter()->map(fn ($id) => (int) $id)->values()->toArray();
        $toDetach = array_diff($currentAttachedIds, $keptGroupIds);

        if (! empty($toDetach)) {
            $product->optionGroups()->detach($toDetach);
        }

        foreach ($this->optionGroups as $i => $groupData) {
            if ($groupData['group_id']) {
                $group = ProductOptionGroup::find($groupData['group_id']);
                if (! $group) {
                    continue;
                }
            } else {
                $group = new ProductOptionGroup(['company_id' => $companyId]);
            }

            $groupKey = $groupData['key'];
            $groupImagePath = $groupData['image_path'] ?? null;
            if (! empty($this->groupImages[$groupKey])) {
                if ($groupImagePath) {
                    Storage::disk('s3')->delete($groupImagePath);
                }
                $stored = $this->groupImages[$groupKey]->store('product-option-groups', 's3');
                if ($stored !== false) {
                    $groupImagePath = $stored;
                }
            }

            $group->fill([
                'name' => $groupData['name'],
                'image_path' => $groupImagePath,
                'total_qty' => (int) $groupData['total_qty'],
                'fixed' => (bool) ($groupData['fixed'] ?? false),
                'sort_order' => $i,
            ]);

            if (! $group->company_id) {
                $group->company_id = $companyId;
            }

            $group->save();

            $product->optionGroups()->syncWithoutDetaching([
                $group->id => ['sort_order' => $i],
            ]);

            $keptOptionIds = collect($groupData['options'])->pluck('id')->filter()->values();
            ProductOption::where('product_option_group_id', $group->id)
                ->whereNotIn('id', $keptOptionIds)
                ->delete();

            foreach ($groupData['options'] as $optData) {
                $option = $optData['id']
                    ? (ProductOption::find($optData['id']) ?? new ProductOption(['product_option_group_id' => $group->id]))
                    : new ProductOption(['product_option_group_id' => $group->id]);

                $groupFixed = (bool) ($groupData['fixed'] ?? false);
                $isVariantPriceGroup = $this->isVariant && $i === 0;

                $optImageKey = $groupKey.'_'.$optData['key'];
                $optImagePath = $optData['image_path'] ?? null;
                if (! empty($this->optionImages[$optImageKey])) {
                    if ($optImagePath) {
                        Storage::disk('s3')->delete($optImagePath);
                    }
                    $stored = $this->optionImages[$optImageKey]->store('product-option-images', 's3');
                    if ($stored !== false) {
                        $optImagePath = $stored;
                    }
                }

                $option->fill([
                    'name' => $optData['name'],
                    'image_path' => $optImagePath,
                    'active' => (bool) ($optData['active'] ?? true),
                    'description' => $optData['description'] ?: null,
                    'additional_price' => ($isVariantPriceGroup || ! $groupFixed) ? (float) $optData['additional_price'] : 0.0,
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

            $availableGroups = $this->company_id
                ? ProductOptionGroup::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $this->company_id)
                    ->withCount('options')
                    ->orderBy('name')
                    ->get()
                : collect();
        } else {
            $categories = ProductCategory::where('active', true)->orderBy('name')->get();
            $branches = $this->branchManagerBranchId
                ? Branch::where('id', $this->branchManagerBranchId)->where('active', true)->get()
                : Branch::where('active', true)->orderBy('name')->get();
            $companies = collect();

            $availableGroups = ProductOptionGroup::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->company_id)
                ->withCount('options')
                ->orderBy('name')
                ->get();
        }

        $attachedGroupIds = collect($this->optionGroups)->pluck('group_id')->filter()->values();
        $availableGroups = $availableGroups->whereNotIn('id', $attachedGroupIds)->values();

        return view('livewire.admin.products.form', compact('categories', 'branches', 'companies', 'availableGroups'))
            ->layout('layouts.app', ['title' => $this->isEditing ? 'Editar Produto' : 'Novo Produto']);
    }
}
