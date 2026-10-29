<?php

namespace App\Livewire\Admin\Coupons;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\CompanyScope;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    // --- List filters ---
    public string $filterStatus = '';

    public string $filterType = '';

    // --- Form ---
    public bool $showModal = false;

    #[Locked]
    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $code = '';

    public string $name = '';

    public string $description = '';

    public string $type = 'percentage';

    public ?float $discount_value = null;

    public ?int $free_product_id = null;

    public string $scope = 'order';

    public array $scope_ids = [];

    public ?string $payment_method = null;

    public ?float $minimum_order_value = null;

    public ?int $max_uses = null;

    public ?int $max_uses_per_customer = 1;

    public ?string $starts_at = null;

    public ?string $expires_at = null;

    public bool $active = true;

    /** Formulário aberto por um atalho das sugestões de venda (só muda o aviso exibido). */
    public bool $prefilledFromSuggestion = false;

    #[Locked]
    public bool $isSuperAdmin = false;

    #[Locked]
    public bool $canCreate = false;

    #[Locked]
    public bool $canUpdate = false;

    #[Locked]
    public bool $canDelete = false;

    public function mount(): void
    {
        $user = auth()->user();
        $this->isSuperAdmin = $user->isSuperAdmin();

        if ($this->isSuperAdmin) {
            $this->canCreate = $this->canUpdate = $this->canDelete = true;
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');

            $this->authorize('viewAny', Coupon::class);
            $this->canCreate = $user->hasPermission('coupons.create', $company);
            $this->canUpdate = $user->hasPermission('coupons.update', $company);
            $this->canDelete = $user->hasPermission('coupons.delete', $company);
        }

        $this->applySuggestionPrefill();
    }

    /**
     * Atalho das sugestões de venda (Itens vendidos): abre o formulário de novo cupom preenchido
     * pela query string. Só preenche; nada é gravado até o save(), que valida tudo de novo.
     */
    private function applySuggestionPrefill(): void
    {
        $query = fn (string $key) => is_string($value = request()->query($key)) ? trim($value) : null;

        if (! $query('sugestao') || ! $this->canCreate || ! app()->bound('current.company')) {
            return;
        }

        $productIds = [];
        if ($query('escopo') === 'product') {
            $ids = array_filter(array_map('intval', explode(',', (string) $query('produtos'))));
            // Só produtos ativos do tenant: id de outra empresa na URL é descartado.
            $productIds = Product::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', app('current.company')->id)
                ->where('active', true)
                ->whereIn('id', $ids ?: [0])
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            // Sem produto válido não pré-preenche: cair para o escopo "pedido" daria desconto em tudo.
            if (! $productIds) {
                return;
            }
        }

        $this->openModal();
        $this->prefilledFromSuggestion = true;
        $this->type = 'percentage';

        if ($productIds) {
            $this->scope = 'product';
            $this->scope_ids = $productIds;
        }

        $value = filter_var($query('valor'), FILTER_VALIDATE_FLOAT);
        if ($value !== false && $value >= 1 && $value <= 100) {
            $this->discount_value = $value;
        }

        foreach (['inicio' => 'starts_at', 'fim' => 'expires_at'] as $param => $field) {
            $date = $query($param) ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $query($param)) : false;
            if ($date && $date->format('Y-m-d\TH:i') === $query($param)) {
                $this->{$field} = $query($param);
            }
        }
        if ($this->starts_at && $this->expires_at && $this->expires_at < $this->starts_at) {
            $this->expires_at = null;
        }

        $this->name = Str::limit(strip_tags((string) $query('nome')), 100, '');
        $this->code = $this->availableCouponCode(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper(Str::ascii((string) $query('codigo')))), 0, 20));

        $maxUses = filter_var($query('max_usos'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if ($maxUses !== false) {
            $this->max_uses = $maxUses;
        }
    }

    /** A mesma sugestão volta em outro mês: código já usado ganha sufixo (PEDEDIRETO2, ...). */
    private function availableCouponCode(string $code): string
    {
        if ($code === '') {
            return '';
        }

        $taken = Coupon::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', app('current.company')->id)
            ->where('code', 'like', substr($code, 0, 17).'%')
            ->pluck('code')
            ->all();

        for ($candidate = $code, $suffix = 2; in_array($candidate, $taken, true) && $suffix < 1000; $suffix++) {
            $candidate = substr($code, 0, 20 - strlen((string) $suffix)).$suffix;
        }

        return $candidate;
    }

    public function updatingFilterStatus(): void
    {
        $this->resetPage();
    }

    public function updatingFilterType(): void
    {
        $this->resetPage();
    }

    /** Empresa do cupom: a do cupom em edição (super admin edita de qualquer uma) ou o tenant atual. */
    private function couponCompanyId(): ?int
    {
        if ($this->editingId) {
            return Coupon::withoutGlobalScope(CompanyScope::class)->whereKey($this->editingId)->value('company_id');
        }

        return app()->bound('current.company') ? app('current.company')->id : null;
    }

    protected function rules(): array
    {
        $companyId = $this->couponCompanyId();

        $codeUnique = Rule::unique('coupons', 'code')
            ->where(function ($q) use ($companyId) {
                if ($companyId) {
                    $q->where('company_id', $companyId);
                }
            });

        if ($this->editingId) {
            $codeUnique->ignore($this->editingId);
        }

        return [
            'code' => ['required', 'string', 'max:50', $codeUnique],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', Rule::in(['percentage', 'fixed', 'free_delivery', 'free_product'])],
            'discount_value' => [
                'nullable', 'numeric', 'min:0.01', 'required_if:type,percentage', 'required_if:type,fixed',
                Rule::when($this->type === 'percentage', ['max:100']),
            ],
            // Brinde só da própria empresa: o preço do produto de outra entrava no desconto.
            'free_product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId), 'required_if:type,free_product'],
            'scope' => ['required', Rule::in(['order', 'category', 'product'])],
            'scope_ids' => ['nullable', 'array'],
            'payment_method' => ['nullable', Rule::in(['CASH', 'PIX', 'CARD'])],
            'minimum_order_value' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_customer' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'code.required' => 'Informe o código do cupom.',
            'code.unique' => 'Este código já está em uso.',
            'name.required' => 'Informe o nome do cupom.',
            'type.required' => 'Selecione o tipo de desconto.',
            'discount_value.required_if' => 'Informe o valor do desconto.',
            'discount_value.min' => 'O valor deve ser maior que zero.',
            'discount_value.max' => 'Desconto percentual não pode passar de 100%.',
            'free_product_id.required_if' => 'Selecione o produto grátis.',
            'expires_at.after_or_equal' => 'A data de expiração deve ser após a data de início.',
        ];
    }

    public function openModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function save(): void
    {
        $this->code = strtoupper(trim($this->code));
        $validated = $this->validate($this->rules(), $this->messages());

        // Sem filtrar nulos: na edição, limpar validade, limite de usos, pedido mínimo ou forma de
        // pagamento precisa gravar null (todas essas colunas são nullable).
        $data = [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'type' => $this->type,
            'discount_value' => in_array($this->type, ['percentage', 'fixed']) ? $this->discount_value : null,
            'free_product_id' => $this->type === 'free_product' ? $this->free_product_id : null,
            'scope' => in_array($this->type, ['percentage', 'fixed']) ? $this->scope : 'order',
            'scope_ids' => (in_array($this->type, ['percentage', 'fixed']) && $this->scope !== 'order') ? $this->scope_ids : null,
            'payment_method' => $this->payment_method ?: null,
            'minimum_order_value' => $this->minimum_order_value,
            'max_uses' => $this->max_uses,
            'max_uses_per_customer' => $this->max_uses_per_customer,
            'starts_at' => $this->starts_at ?: null,
            'expires_at' => $this->expires_at ?: null,
            'active' => $this->active,
        ];

        if ($this->editingId) {
            $coupon = Coupon::withoutGlobalScope(CompanyScope::class)->findOrFail($this->editingId);
            $this->authorize('update', $coupon);
            $coupon->update($data);
            Log::channel('audit')->info('Cupom atualizado', ['admin_id' => auth()->id(), 'coupon_id' => $coupon->id, 'code' => $coupon->code]);
            session()->flash('status', 'Cupom atualizado com sucesso.');
        } else {
            $this->authorize('create', Coupon::class);
            // Na criação, campo vazio fica com o padrão do banco (ex.: 1 uso por cliente);
            // só na edição o null serve para limpar a restrição.
            $coupon = Coupon::create(array_filter($data, fn ($v) => $v !== null));
            Log::channel('audit')->info('Cupom criado', ['admin_id' => auth()->id(), 'coupon_id' => $coupon->id, 'code' => $coupon->code]);
            session()->flash('status', 'Cupom criado com sucesso.');
        }

        $this->closeModal();
    }

    public function edit(int $id): void
    {
        $coupon = Coupon::withoutGlobalScope(CompanyScope::class)->findOrFail($id);
        $this->authorize('view', $coupon);

        $this->editingId = $id;
        $this->code = $coupon->code;
        $this->name = $coupon->name;
        $this->description = $coupon->description ?? '';
        $this->type = $coupon->type;
        $this->discount_value = $coupon->discount_value;
        $this->free_product_id = $coupon->free_product_id;
        $this->scope = $coupon->scope;
        $this->scope_ids = $coupon->scope_ids ?? [];
        $this->payment_method = $coupon->payment_method;
        $this->minimum_order_value = $coupon->minimum_order_value;
        $this->max_uses = $coupon->max_uses;
        $this->max_uses_per_customer = $coupon->max_uses_per_customer;
        $this->starts_at = $coupon->starts_at?->format('Y-m-d\TH:i');
        $this->expires_at = $coupon->expires_at?->format('Y-m-d\TH:i');
        $this->active = $coupon->active;
        $this->showModal = true;
    }

    public function toggleActive(int $id): void
    {
        $coupon = Coupon::withoutGlobalScope(CompanyScope::class)->findOrFail($id);
        $this->authorize('update', $coupon);
        $coupon->update(['active' => ! $coupon->active]);
        session()->flash('status', $coupon->fresh()->active ? 'Cupom ativado.' : 'Cupom desativado.');
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
        $coupon = Coupon::withoutGlobalScope(CompanyScope::class)->findOrFail($this->deletingId);
        $this->authorize('delete', $coupon);
        Log::channel('audit')->info('Cupom excluído', ['admin_id' => auth()->id(), 'coupon_id' => $coupon->id, 'code' => $coupon->code]);
        $coupon->delete();
        $this->deletingId = null;
        session()->flash('status', 'Cupom excluído.');
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'code', 'name', 'description', 'type', 'discount_value',
            'free_product_id', 'scope', 'scope_ids', 'payment_method', 'minimum_order_value',
            'max_uses', 'starts_at', 'expires_at',
        ]);
        $this->max_uses_per_customer = 1;
        $this->active = true;
        $this->scope = 'order';
        $this->type = 'percentage';
        $this->prefilledFromSuggestion = false;
        $this->resetErrorBag();
    }

    public function render()
    {
        $query = Coupon::withoutGlobalScope(CompanyScope::class)
            ->withCount(['countedUsages as usages_count']);

        if (! $this->isSuperAdmin && app()->bound('current.company')) {
            $query->where('company_id', app('current.company')->id);
        }

        if ($this->filterStatus !== '') {
            $query->where('active', $this->filterStatus === 'active');
        }

        if ($this->filterType !== '') {
            $query->where('type', $this->filterType);
        }

        $coupons = $query->orderByDesc('created_at')->paginate(15);

        $products = Product::withoutGlobalScope(CompanyScope::class)
            ->when(app()->bound('current.company'), fn ($q) => $q->where('company_id', app('current.company')->id))
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $categories = ProductCategory::withoutGlobalScope(CompanyScope::class)
            ->when(app()->bound('current.company'), fn ($q) => $q->where('company_id', app('current.company')->id))
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return view('livewire.admin.coupons.index', compact('coupons', 'products', 'categories'))
            ->layout('layouts.app', ['title' => 'Cupons de Desconto']);
    }
}
