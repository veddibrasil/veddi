<?php

namespace App\Livewire\Admin\Products;

use App\Jobs\DisableIfoodItemJob;
use App\Jobs\SetFood99ItemStatusJob;
use App\Jobs\SyncFood99CatalogJob;
use App\Jobs\SyncIfoodCatalogJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Food99Integration;
use App\Models\IfoodIntegration;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Scopes\CompanyScope;
use App\Services\Order\MenuCache;
use App\Services\Order\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class Form extends Component
{
    use WithFileUploads;

    public ?Product $product = null;

    #[Locked]
    public bool $isEditing = false;

    #[Locked]
    public bool $isSuperAdmin = false;

    #[Locked]
    public bool $canSave = false;

    // Sem #[Locked]: o select de empresa do super admin grava aqui. Para os demais papéis o
    // valor é ignorado; o escopo vem de targetCompanyId().
    public ?int $company_id = null;

    #[Locked]
    public ?int $branchManagerBranchId = null; // null = não é branch_manager

    public int $product_category_id = 0;

    public string $name = '';

    public string $description = '';

    public string $price = '';

    public bool $promo_price_enabled = false;

    public string $promo_price_type = 'fixed';

    public string $promo_price_value = '';

    public bool $active = true;

    public bool $available_in_pdv = true;

    public bool $available_in_delivery = true;

    public bool $available_in_ifood = false;

    public bool $available_in_99food = true;

    public int $sort_order = 0;

    public $image;

    public array $selectedBranches = [];

    public bool $showCategoryModal = false;

    public string $newCategoryName = '';

    public array $optionGroups = [];

    public array $groupImages = [];

    public array $optionImages = [];

    public bool $showGroupPicker = false;

    public bool $showProductPicker = false;

    public ?int $productPickerGroupIndex = null;

    public string $productSearch = '';

    public bool $isVariant = false;

    public bool $trackStock = false;

    public string $initialQuantity = '0';

    public string $minQuantity = '0';

    public array $fiscalData = [];

    public string $fiscalTaxes = '';

    /** Promoção ligada por um atalho das sugestões de venda (só muda o aviso exibido). */
    public bool $promoFromSuggestion = false;

    protected function rules(): array
    {
        return [
            'fiscalData' => ['array'],
            'fiscalData.ncm' => ['nullable', 'regex:/^[0-9]{8}$/'],
            'fiscalData.cfop' => ['nullable', 'regex:/^5[0-9]{3}$/'],
            'fiscalData.interstate_cfop' => ['nullable', 'regex:/^6[0-9]{3}$/'],
            'fiscalData.icms_situation' => ['nullable', 'regex:/^[0-9]{2,3}$/'],
            'fiscalData.origin' => ['nullable', 'integer', 'between:0,8'],
            'fiscalData.unit' => ['nullable', 'string', 'max:6'],
            'fiscalData.cest' => ['nullable', 'regex:/^[0-9]{7}$/'],
            'fiscalData.pis_situation' => ['nullable', 'regex:/^[0-9]{2}$/'],
            'fiscalData.cofins_situation' => ['nullable', 'regex:/^[0-9]{2}$/'],
            'fiscalTaxes' => ['nullable', 'json'],
            'company_id' => $this->isSuperAdmin ? ['required', 'integer', 'exists:companies,id'] : ['nullable'],
            'product_category_id' => ['required', 'integer', Rule::exists('product_categories', 'id')->where('company_id', $this->targetCompanyId())],
            'name' => ['required', 'string', $this->appliesIfoodRules() ? 'max:100' : 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => $this->isVariant ? ['nullable', 'numeric', 'min:0'] : ['required', 'numeric', 'min:0.01'],
            'promo_price_enabled' => $this->isVariant ? ['boolean'] : ['boolean'],
            'promo_price_type' => $this->isVariant ? ['nullable'] : ['exclude_unless:promo_price_enabled,true', 'required', Rule::in(['fixed', 'percentage'])],
            'promo_price_value' => $this->isVariant ? ['nullable'] : ['exclude_unless:promo_price_enabled,true', 'required', 'numeric', 'min:0.01'],
            'active' => ['boolean'],
            'available_in_pdv' => ['boolean'],
            'available_in_delivery' => ['boolean'],
            'available_in_ifood' => ['boolean'],
            'available_in_99food' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
            'image' => ['nullable', 'image', 'max:2048', ...($this->appliesIfoodRules() ? ['mimes:jpg,jpeg,png'] : [])],
            'selectedBranches' => ['array'],
            'selectedBranches.*' => [
                Rule::exists('branches', 'id')->where('company_id', $this->targetCompanyId()),
            ],
            'optionGroups' => ['array'],
            'optionGroups.*.name' => ['required_with:optionGroups.*', 'string', 'max:150'],
            'optionGroups.*.total_qty' => ['required_with:optionGroups.*', 'integer', 'min:1'],
            'optionGroups.*.min_qty' => ['required_with:optionGroups.*', 'integer', 'min:0'],
            'optionGroups.*.fixed' => ['boolean'],
            'optionGroups.*.allow_skip' => ['boolean'],
            'optionGroups.*.options' => ['array'],
            'optionGroups.*.options.*.name' => ['required_with:optionGroups.*.options.*', 'string', $this->appliesIfoodRules() ? 'max:100' : 'max:150'],
            'optionGroups.*.options.*.active' => ['boolean'],
            'optionGroups.*.options.*.description' => ['nullable', 'string', 'max:2000'],
            'optionGroups.*.options.*.additional_price' => ['required_with:optionGroups.*.options.*', 'numeric', 'min:0'],
            'optionGroups.*.options.*.default_qty' => ['required_with:optionGroups.*.options.*', 'integer', 'min:0'],
            'optionGroups.*.options.*.max_qty' => ['nullable', 'integer', 'min:1'],
            'groupImages.*' => ['nullable', 'image', 'max:2048'],
            'optionImages.*' => ['nullable', 'image', 'max:2048', ...($this->appliesIfoodRules() ? ['mimes:jpg,jpeg,png'] : [])],
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
            'optionGroups.*.min_qty.required_with' => 'A quantidade mínima é obrigatória.',
            'optionGroups.*.options.*.name.required_with' => 'O nome da opção é obrigatório.',
            'optionGroups.*.options.*.additional_price.numeric' => 'O acréscimo deve ser um valor numérico.',
            'optionGroups.*.options.*.additional_price.min' => 'O acréscimo não pode ser negativo.',
        ];
    }

    public function getPdvEnabledProperty(): bool
    {
        if ($this->isSuperAdmin) {
            return (bool) Company::find($this->company_id)?->pdv_module_enabled;
        }

        return app()->bound('current.company') ? (bool) app('current.company')->pdv_module_enabled : false;
    }

    /** Canal iFood só aparece (e só restringe nome/foto) para empresa com o módulo de integrações. */
    public function getIfoodEnabledProperty(): bool
    {
        $company = $this->isSuperAdmin
            ? Company::find($this->company_id)
            : (app()->bound('current.company') ? app('current.company') : null);

        return (bool) $company?->canUseIfoodIntegration();
    }

    /** Canal 99Food só aparece com a integração ligada na plataforma e conectada em alguma filial da empresa. */
    public function getFood99EnabledProperty(): bool
    {
        $companyId = $this->isSuperAdmin ? $this->company_id : (app()->bound('current.company') ? app('current.company')->id : null);

        return config('food99.enabled') && $companyId && $this->ifoodEnabled
            && Food99Integration::withoutGlobalScopes()->where('company_id', $companyId)->whereNotNull('shop_id')->exists();
    }

    /**
     * Situação do último envio do produto à 99Food, por filial.
     *
     * @return array<int, array{branch: string, status: ?string, error: ?string}>
     */
    public function getFood99SyncStatusProperty(): array
    {
        if (! $this->isEditing || ! $this->product?->id || ! $this->food99Enabled) {
            return [];
        }

        return DB::table('branch_product')
            ->join('branches', 'branches.id', '=', 'branch_product.branch_id')
            ->where('branch_product.product_id', $this->product->id)
            ->where('branches.company_id', $this->product->company_id)
            ->where(fn ($q) => $q->whereNotNull('branch_product.food99_sync_status')->orWhereNotNull('branch_product.food99_sync_error'))
            ->orderBy('branches.name')
            ->get(['branches.name as branch', 'branch_product.food99_sync_status as status', 'branch_product.food99_sync_error as error'])
            ->map(fn ($row) => ['branch' => $row->branch, 'status' => $row->status, 'error' => $row->error])
            ->all();
    }

    /**
     * Motivo do último envio que o iFood não aceitou, por filial (o sync segue item a item e
     * grava o erro no vínculo do produto com a filial).
     *
     * @return array<int, array{branch: string, error: string}>
     */
    public function getIfoodSyncErrorsProperty(): array
    {
        if (! $this->isEditing || ! $this->product?->id || ! $this->ifoodEnabled) {
            return [];
        }

        return DB::table('branch_product')
            ->join('branches', 'branches.id', '=', 'branch_product.branch_id')
            ->where('branch_product.product_id', $this->product->id)
            ->where('branches.company_id', $this->product->company_id)
            ->whereNotNull('branch_product.ifood_sync_error')
            ->orderBy('branches.name')
            ->get(['branches.name as branch', 'branch_product.ifood_sync_error as error'])
            ->map(fn ($row) => ['branch' => $row->branch, 'error' => $row->error])
            ->all();
    }

    /**
     * Empresa dona do cadastro. Só o super admin escolhe (select em company_id); para os demais
     * vale o tenant da requisição, porque company_id é prop pública e o navegador a reescreve.
     */
    private function targetCompanyId(): ?int
    {
        if (auth()->user()->isSuperAdmin()) {
            return $this->company_id;
        }

        return app()->bound('current.company') ? app('current.company')->id : null;
    }

    private function appliesIfoodRules(): bool
    {
        return $this->available_in_ifood && $this->ifoodEnabled;
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
            $this->company_id = $this->targetCompanyId();
        }

        if (app()->bound('current.branch')) {
            $this->branchManagerBranchId = app('current.branch')->id;
        }

        if ($product?->exists) {
            $this->product = $product;
            $this->fiscalData = $product->fiscal_data ?? [];
            $this->fiscalTaxes = empty($this->fiscalData['taxes']) ? '' : json_encode($this->fiscalData['taxes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->isEditing = true;
            $this->company_id = $product->company_id;
            $this->fill($product->only('product_category_id', 'name', 'active', 'available_in_pdv', 'available_in_delivery', 'sort_order'));
            $this->available_in_ifood = (bool) ($product->available_in_ifood ?? true);
            $this->available_in_99food = (bool) ($product->available_in_99food ?? true);
            $this->description = $product->description ?? '';
            $this->price = (string) $product->price;
            $this->isVariant = (bool) $product->is_variant;
            $this->promo_price_enabled = (bool) $product->promo_price_enabled;
            $this->promo_price_type = (string) ($product->promo_price_type ?: 'fixed');
            $this->promo_price_value = $product->promo_price_value !== null ? (string) $product->promo_price_value : '';
            $this->applySuggestionPromo();
            $this->selectedBranches = $product->branches->pluck('id')->map(fn ($id) => (string) $id)->toArray();

            $this->optionGroups = $product->optionGroups->map(function ($group) {
                return [
                    'key' => 'gid-'.$group->id,
                    'group_id' => $group->id,
                    'name' => $group->name,
                    'image_path' => $group->image_path,
                    'image_url' => $group->image_url,
                    'total_qty' => (string) $group->total_qty,
                    'min_qty' => (string) $group->min_qty,
                    'fixed' => (bool) $group->fixed,
                    'allow_skip' => (bool) $group->allow_skip,
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
                        'max_qty' => $opt->max_qty !== null ? (string) $opt->max_qty : '',
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

    /**
     * Atalho "Testar X% de desconto" das sugestões de venda: liga a promoção percentual no
     * formulário, sem salvar. Não mexe em variante nem em promoção já configurada.
     */
    private function applySuggestionPromo(): void
    {
        $promo = filter_var(request()->query('promo'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);

        if ($promo === false || ! $this->canSave || $this->isVariant || $this->promo_price_enabled) {
            return;
        }

        $this->promo_price_enabled = true;
        $this->promo_price_type = 'percentage';
        $this->promo_price_value = (string) $promo;
        $this->promoFromSuggestion = true;
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

        if ($companyId = $this->targetCompanyId()) {
            $data['company_id'] = $companyId;
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
            'min_qty' => '0',
            'fixed' => false,
            'allow_skip' => false,
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

        // Grupo não tem CompanyScope: sem o filtro, o id digitado anexava grupo de outra empresa.
        $group = ProductOptionGroup::with('options')->where('company_id', $this->targetCompanyId())->find($groupId);
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
            'min_qty' => (string) $group->min_qty,
            'fixed' => (bool) $group->fixed,
            'allow_skip' => (bool) $group->allow_skip,
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
                'max_qty' => $opt->max_qty !== null ? (string) $opt->max_qty : '',
                'sort_order' => $opt->sort_order,
            ])->toArray(),
        ];

        $this->showGroupPicker = false;
    }

    public function openProductPicker(int $groupIndex): void
    {
        $this->productPickerGroupIndex = $groupIndex;
        $this->productSearch = '';
        $this->showProductPicker = true;
    }

    public function closeProductPicker(): void
    {
        $this->showProductPicker = false;
        $this->productPickerGroupIndex = null;
        $this->productSearch = '';
    }

    public function importProductAsOption(int $productId): void
    {
        if ($this->productPickerGroupIndex === null || ! isset($this->optionGroups[$this->productPickerGroupIndex])) {
            return;
        }

        if ($this->isEditing && $this->product && $productId === $this->product->id) {
            return;
        }

        $query = auth()->user()->isSuperAdmin()
            ? Product::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->company_id)
            : Product::query();

        $product = $query->find($productId);
        if (! $product) {
            return;
        }

        $groupIndex = $this->productPickerGroupIndex;

        $this->optionGroups[$groupIndex]['options'][] = [
            'key' => (string) Str::uuid(),
            'id' => null,
            'name' => $product->name,
            'image_path' => $product->image_path,
            'image_url' => $product->image_url,
            'active' => true,
            'description' => Str::limit($product->description ?? '', 2000, ''),
            'additional_price' => number_format((float) $product->price, 2, '.', ''),
            'default_qty' => '0',
            'max_qty' => '',
            'sort_order' => count($this->optionGroups[$groupIndex]['options']),
            'source_product_id' => $product->id,
            'source_product_name' => $product->name,
        ];

        $this->closeProductPicker();
    }

    public function unlinkSourceProduct(int $groupIndex, int $optionIndex): void
    {
        if (isset($this->optionGroups[$groupIndex]['options'][$optionIndex])) {
            $this->optionGroups[$groupIndex]['options'][$optionIndex]['source_product_id'] = null;
            $this->optionGroups[$groupIndex]['options'][$optionIndex]['source_product_name'] = null;
        }
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
            'max_qty' => '',
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
            $totalQty = (int) $groupData['total_qty'];

            if (! empty($groupData['fixed'])) {
                $usedQty = collect($groupData['options'] ?? [])->sum(fn ($o) => (int) ($o['default_qty'] ?? 0));
                if ($usedQty > $totalQty) {
                    $this->addError("optionGroups.{$gi}.total_qty", "A soma das qtds. fixas ({$usedQty}) ultrapassa a quantidade total ({$totalQty}).");

                    return;
                }

                continue;
            }

            $minQty = (int) ($groupData['min_qty'] ?? 0);
            if ($minQty > $totalQty) {
                $this->addError("optionGroups.{$gi}.min_qty", "A quantidade mínima ({$minQty}) não pode ser maior que a máxima ({$totalQty}).");

                return;
            }

            // Produto com variações tem preço base 0: o preço vem do 1º grupo. Se o cliente puder pular esse
            // grupo, o item vira R$ 0,00 (o chat e o servidor agora recusam esse item).
            if ($this->isVariant && $gi === 0 && ($minQty < 1 || ! empty($groupData['allow_skip']))) {
                $this->addError("optionGroups.{$gi}.min_qty", 'Em produto com variações o 1º grupo define o preço: use quantidade mínima 1 ou mais e deixe "Permitir Não quero" desmarcado.');

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

        $taxes = $this->fiscalTaxes !== '' ? json_decode($this->fiscalTaxes, true) : [];
        if (! is_array($taxes) || array_is_list($taxes) && $taxes !== []) {
            $this->addError('fiscalTaxes', 'Informe um objeto JSON de campos tributários.');

            return;
        }
        foreach ($taxes as $key => $value) {
            if (! preg_match('/^(icms_|pis_|cofins_|ipi_|ibs_|cbs_|is_)/', $key) || is_array($value) || is_object($value)) {
                $this->addError('fiscalTaxes', 'Use somente campos tributários válidos da Focus NFe.');

                return;
            }
        }
        $isVariantProduct = $this->isVariant;
        $branchIds = $this->branchIdsToSync();

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
            'available_in_pdv' => $this->pdvEnabled ? $validated['available_in_pdv'] : false,
            'available_in_delivery' => $validated['available_in_delivery'],
            'available_in_ifood' => $validated['available_in_ifood'],
            'available_in_99food' => $validated['available_in_99food'],
            'sort_order' => $validated['sort_order'],
            'image_path' => $imagePath,
            'fiscal_data' => array_replace($this->fiscalData, ['taxes' => $taxes]),
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

        $isSuperAdmin = auth()->user()->isSuperAdmin();

        if ($this->isEditing) {
            if ($isSuperAdmin && $this->company_id) {
                $data['company_id'] = $this->company_id;
            }
            Product::withoutGlobalScope(CompanyScope::class)
                ->where('id', $this->product->id)
                ->update($data);
            $product = $this->product->refresh();

            session()->flash('status', 'Produto atualizado.');
        } else {
            if ($isSuperAdmin) {
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

            foreach ($branchIds as $branchId) {
                $existing = $existingPivots->get($branchId);
                $branchSync[$branchId] = [
                    'available' => $existing?->available ?? true,
                    'track_stock' => $existing?->track_stock ?? false,
                    'min_quantity' => $existing?->min_quantity ?? 0,
                    'quantity' => $existing?->quantity ?? 0,
                ];

            }
        } else {
            foreach ($branchIds as $branchId) {
                $branchSync[$branchId] = [
                    'available' => true,
                    'track_stock' => $this->trackStock,
                    'min_quantity' => (int) $this->minQuantity,
                    'quantity' => 0,
                ];

            }
        }
        $this->disableIfoodItemsOfRemovedBranches($product, array_keys($branchSync));
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
                // group_id vem do navegador e o modelo não tem CompanyScope: sem o filtro, o
                // formulário editava (e limpava as opções de) grupo de outra empresa.
                $group = ProductOptionGroup::where('company_id', $companyId)->find($groupData['group_id']);
                if (! $group) {
                    continue;
                }
            } else {
                $group = new ProductOptionGroup(['company_id' => $companyId]);
            }

            $groupKey = $groupData['key'];
            $storedGroupImage = $group->exists ? $group->image_path : null;
            // O navegador só mantém ou limpa a imagem gravada; caminho mandado por ele nunca chega
            // ao S3 (apagaria logo/foto de qualquer empresa).
            $groupImagePath = filled($groupData['image_path'] ?? null) ? $storedGroupImage : null;
            if (! empty($this->groupImages[$groupKey])) {
                if ($this->ownsUploadedImage($storedGroupImage, 'product-option-groups/')) {
                    Storage::disk('s3')->delete($storedGroupImage);
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
                'min_qty' => (int) ($groupData['min_qty'] ?? 0),
                'fixed' => (bool) ($groupData['fixed'] ?? false),
                'allow_skip' => (bool) ($groupData['allow_skip'] ?? false),
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
                // Só opção do próprio grupo (já validado acima); id de outro grupo vira opção nova.
                $option = ($optData['id'] ? ProductOption::where('product_option_group_id', $group->id)->find($optData['id']) : null)
                    ?? new ProductOption(['product_option_group_id' => $group->id]);

                $groupFixed = (bool) ($groupData['fixed'] ?? false);
                $isVariantPriceGroup = $this->isVariant && $i === 0;

                $optImageKey = $groupKey.'_'.$optData['key'];
                $storedOptImage = $option->exists ? $option->image_path : null;
                $optImagePath = filled($optData['image_path'] ?? null)
                    ? ($option->exists ? $storedOptImage : $this->importedProductImage($optData, $companyId))
                    : null;
                if (! empty($this->optionImages[$optImageKey])) {
                    if ($this->ownsUploadedImage($storedOptImage, 'product-option-images/')) {
                        Storage::disk('s3')->delete($storedOptImage);
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
                    'max_qty' => ($optData['max_qty'] ?? '') !== '' ? (int) $optData['max_qty'] : null,
                    'sort_order' => $optData['sort_order'],
                ])->save();
            }
        }

        // Define estoque inicial para produtos novos
        if (! $this->isEditing && $this->trackStock && (int) $this->initialQuantity > 0) {
            $service = app(StockService::class);
            foreach ($branchIds as $branchId) {
                $branch = Branch::withoutGlobalScope(CompanyScope::class)->find($branchId);
                if ($branch) {
                    $service->setQuantity($branch, $product, (int) $this->initialQuantity, 'Estoque inicial', auth()->user());
                }
            }
        }

        // Depois de gravar filiais, grupos e opções: invalida o cardápio de todas as filiais da empresa
        // (inclui filiais desmarcadas neste save), sem janela para outro request repopular com dado velho.
        app(MenuCache::class)->forgetCompany((int) ($product->company_id ?? $this->company_id));

        // O formulário grava relações depois do produto; publicar apenas o estado final.
        $integrations = IfoodIntegration::withoutGlobalScopes()
            ->where('company_id', $product->company_id)->where('status', 'active')
            ->whereIn('branch_id', $product->branches()->pluck('branches.id'))->get();
        foreach ($integrations as $integration) {
            try {
                SyncIfoodCatalogJob::dispatch($integration->branch_id, $product->id, true)->afterCommit();
            } catch (\Throwable $e) {
                report($e);
                session()->flash('error', 'Produto salvo. A sincronização com o iFood não foi concluída; use Sincronizar cardápio agora nas configurações da integração para tentar novamente.');
            }
        }

        // 99Food: nome, preço, foto e complementos vão no próximo upload completo (1 por minuto).
        if (config('food99.enabled')) {
            Food99Integration::withoutGlobalScopes()
                ->where('company_id', $product->company_id)->where('status', Food99Integration::STATUS_ACTIVE)
                ->whereNotNull('catalog_published_at')
                ->whereIn('branch_id', DB::table('branch_product')->where('product_id', $product->id)->select('branch_id'))
                ->pluck('branch_id')
                ->each(fn (int $branchId) => SyncFood99CatalogJob::debounce($branchId));
        }

        $this->redirect(route('admin.products.index'));
    }

    /**
     * Filiais a gravar. Papel preso à filial (gerente) só marca/desmarca a própria: as demais
     * ficam como já estavam no produto, qualquer que seja a lista mandada pelo navegador.
     *
     * @return array<int, int>
     */
    private function branchIdsToSync(): array
    {
        $selected = collect($this->selectedBranches)->map(fn ($id) => (int) $id);
        $user = auth()->user();

        if ($user->isSuperAdmin() || ! app()->bound('current.company')) {
            return $selected->unique()->values()->all();
        }

        $company = app('current.company');
        $current = $this->isEditing
            ? $this->product->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)
            : collect();

        return $selected->filter(fn ($id) => $user->canAccessBranch($company, $id))
            ->merge($current->reject(fn ($id) => $user->canAccessBranch($company, $id)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Opção nova importada de um produto herda a foto dele (mesma empresa). Qualquer outro
     * caminho vindo do navegador é descartado.
     */
    private function importedProductImage(array $optData, ?int $companyId): ?string
    {
        $path = $optData['image_path'] ?? null;

        // Aceita só a foto de um produto da própria empresa (inclusive depois de desvincular a
        // opção do produto de origem); qualquer outro caminho vindo do navegador é descartado.
        return $path && Product::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('image_path', $path)
            ->exists() ? $path : null;
    }

    /**
     * Só apaga do S3 arquivo que este formulário enviou: opção importada aponta para a foto do
     * produto de origem, que continua em uso.
     */
    private function ownsUploadedImage(?string $path, string $prefix): bool
    {
        return $path !== null && str_starts_with($path, $prefix);
    }

    /**
     * Desmarcar a filial apaga o pivot com o id do item no iFood: tira de venda antes,
     * senão a loja daquela filial segue vendendo o produto.
     *
     * @param  array<int, int|string>  $keptBranchIds
     */
    private function disableIfoodItemsOfRemovedBranches(Product $product, array $keptBranchIds): void
    {
        $items = DB::table('branch_product')
            ->where('product_id', $product->id)
            ->whereNotIn('branch_id', $keptBranchIds)
            ->whereNotNull('ifood_item_id')
            ->whereIn('branch_id', IfoodIntegration::withoutGlobalScopes()
                ->where('company_id', $product->company_id)
                ->whereIn('status', ['active', 'paused'])
                ->select('branch_id'))
            ->get(['branch_id', 'ifood_item_id']);

        foreach ($items as $item) {
            DisableIfoodItemJob::dispatch($item->branch_id, $item->ifood_item_id)->afterCommit();
        }

        $removedBranchIds = DB::table('branch_product')->where('product_id', $product->id)
            ->whereNotIn('branch_id', $keptBranchIds)->pluck('branch_id')->all();
        if ($removedBranchIds !== []) {
            SetFood99ItemStatusJob::forProduct($product->company_id, $product->id, $removedBranchIds, disable: true);
        }
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
                ->where('company_id', $this->targetCompanyId())
                ->withCount('options')
                ->orderBy('name')
                ->get();
        }

        $attachedGroupIds = collect($this->optionGroups)->pluck('group_id')->filter()->values();
        $availableGroups = $availableGroups->whereNotIn('id', $attachedGroupIds)->values();

        $excludedProductIds = collect($this->optionGroups)
            ->flatMap(fn ($group) => collect($group['options'] ?? [])->pluck('source_product_id'))
            ->filter()
            ->when($this->isEditing && $this->product, fn ($ids) => $ids->push($this->product->id))
            ->values();

        if (filled($this->productSearch)) {
            if ($this->isSuperAdmin) {
                $importableProducts = $this->company_id
                    ? Product::withoutGlobalScope(CompanyScope::class)
                        ->where('company_id', $this->company_id)
                        ->where('active', true)
                        ->where('name', 'like', '%'.$this->productSearch.'%')
                        ->whereNotIn('id', $excludedProductIds)
                        ->orderBy('name')
                        ->limit(20)
                        ->get()
                    : collect();
            } else {
                $importableProducts = Product::where('active', true)
                    ->where('name', 'like', '%'.$this->productSearch.'%')
                    ->whereNotIn('id', $excludedProductIds)
                    ->orderBy('name')
                    ->limit(20)
                    ->get();
            }
        } else {
            $importableProducts = collect();
        }

        return view('livewire.admin.products.form', compact('categories', 'branches', 'companies', 'availableGroups', 'importableProducts'))
            ->layout('layouts.app', ['title' => $this->isEditing ? 'Editar Produto' : 'Novo Produto']);
    }
}
