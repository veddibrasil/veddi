<?php

namespace App\Livewire\Admin\Coupons;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\CompanyScope;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    // --- List filters ---
    public string $filterStatus = '';
    public string $filterType   = '';

    // --- Form ---
    public bool $showModal = false;
    public ?int $editingId = null;
    public ?int $deletingId = null;

    public string $code                  = '';
    public string $name                  = '';
    public string $description           = '';
    public string $type                  = 'percentage';
    public ?float $discount_value        = null;
    public ?int $free_product_id         = null;
    public string $scope                 = 'order';
    public array $scope_ids              = [];
    public ?float $minimum_order_value   = null;
    public ?int $max_uses                = null;
    public ?int $max_uses_per_customer   = 1;
    public ?string $starts_at            = null;
    public ?string $expires_at           = null;
    public bool $active                  = true;

    public bool $isSuperAdmin = false;

    public function mount(): void
    {
        $this->isSuperAdmin = auth()->user()->isSuperAdmin();
    }

    public function updatingFilterStatus(): void { $this->resetPage(); }
    public function updatingFilterType(): void { $this->resetPage(); }

    protected function rules(): array
    {
        $codeUnique = Rule::unique('coupons', 'code')
            ->where(function ($q) {
                $companyId = app()->bound('current.company') ? app('current.company')->id : null;
                if ($companyId) {
                    $q->where('company_id', $companyId);
                }
            });

        if ($this->editingId) {
            $codeUnique->ignore($this->editingId);
        }

        return [
            'code'                => ['required', 'string', 'max:50', $codeUnique],
            'name'                => ['required', 'string', 'max:100'],
            'description'         => ['nullable', 'string', 'max:500'],
            'type'                => ['required', Rule::in(['percentage', 'fixed', 'free_delivery', 'free_product'])],
            'discount_value'      => ['nullable', 'numeric', 'min:0.01', 'required_if:type,percentage', 'required_if:type,fixed'],
            'free_product_id'     => ['nullable', 'integer', 'exists:products,id', 'required_if:type,free_product'],
            'scope'               => ['required', Rule::in(['order', 'category', 'product'])],
            'scope_ids'           => ['nullable', 'array'],
            'minimum_order_value' => ['nullable', 'numeric', 'min:0'],
            'max_uses'            => ['nullable', 'integer', 'min:1'],
            'max_uses_per_customer' => ['nullable', 'integer', 'min:1'],
            'starts_at'           => ['nullable', 'date'],
            'expires_at'          => ['nullable', 'date', 'after_or_equal:starts_at'],
            'active'              => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'code.required'              => 'Informe o código do cupom.',
            'code.unique'                => 'Este código já está em uso.',
            'name.required'              => 'Informe o nome do cupom.',
            'type.required'              => 'Selecione o tipo de desconto.',
            'discount_value.required_if' => 'Informe o valor do desconto.',
            'discount_value.min'         => 'O valor deve ser maior que zero.',
            'free_product_id.required_if' => 'Selecione o produto grátis.',
            'expires_at.after_or_equal'  => 'A data de expiração deve ser após a data de início.',
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
        $validated  = $this->validate($this->rules(), $this->messages());

        $data = array_filter([
            'code'                 => $this->code,
            'name'                 => $this->name,
            'description'         => $this->description ?: null,
            'type'                 => $this->type,
            'discount_value'       => in_array($this->type, ['percentage', 'fixed']) ? $this->discount_value : null,
            'free_product_id'      => $this->type === 'free_product' ? $this->free_product_id : null,
            'scope'                => in_array($this->type, ['percentage', 'fixed']) ? $this->scope : 'order',
            'scope_ids'            => (in_array($this->type, ['percentage', 'fixed']) && $this->scope !== 'order') ? $this->scope_ids : null,
            'minimum_order_value'  => $this->minimum_order_value,
            'max_uses'             => $this->max_uses,
            'max_uses_per_customer' => $this->max_uses_per_customer,
            'starts_at'            => $this->starts_at ?: null,
            'expires_at'           => $this->expires_at ?: null,
            'active'               => $this->active,
        ], fn ($v) => $v !== null || is_bool($v) || is_numeric($v));

        if ($this->editingId) {
            $coupon = Coupon::withoutGlobalScope(CompanyScope::class)->findOrFail($this->editingId);
            $coupon->update($data);
            session()->flash('status', 'Cupom atualizado com sucesso.');
        } else {
            Coupon::create($data);
            session()->flash('status', 'Cupom criado com sucesso.');
        }

        $this->closeModal();
    }

    public function edit(int $id): void
    {
        $coupon = Coupon::withoutGlobalScope(CompanyScope::class)->findOrFail($id);

        $this->editingId            = $id;
        $this->code                 = $coupon->code;
        $this->name                 = $coupon->name;
        $this->description          = $coupon->description ?? '';
        $this->type                 = $coupon->type;
        $this->discount_value       = $coupon->discount_value;
        $this->free_product_id      = $coupon->free_product_id;
        $this->scope                = $coupon->scope;
        $this->scope_ids            = $coupon->scope_ids ?? [];
        $this->minimum_order_value  = $coupon->minimum_order_value;
        $this->max_uses             = $coupon->max_uses;
        $this->max_uses_per_customer = $coupon->max_uses_per_customer;
        $this->starts_at            = $coupon->starts_at?->format('Y-m-d\TH:i');
        $this->expires_at           = $coupon->expires_at?->format('Y-m-d\TH:i');
        $this->active               = $coupon->active;
        $this->showModal            = true;
    }

    public function toggleActive(int $id): void
    {
        $coupon = Coupon::withoutGlobalScope(CompanyScope::class)->findOrFail($id);
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
        $coupon->delete();
        $this->deletingId = null;
        session()->flash('status', 'Cupom excluído.');
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'code', 'name', 'description', 'type', 'discount_value',
            'free_product_id', 'scope', 'scope_ids', 'minimum_order_value',
            'max_uses', 'starts_at', 'expires_at',
        ]);
        $this->max_uses_per_customer = 1;
        $this->active                = true;
        $this->scope                 = 'order';
        $this->type                  = 'percentage';
        $this->resetErrorBag();
    }

    public function render()
    {
        $query = Coupon::withoutGlobalScope(CompanyScope::class)
            ->withCount('usages');

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
