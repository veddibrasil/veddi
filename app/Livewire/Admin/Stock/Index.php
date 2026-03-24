<?php

namespace App\Livewire\Admin\Stock;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Scopes\CompanyScope;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url(as: 'search')]
    public string $search = '';

    public ?int $branchFilter = null;
    public bool $showLowStockOnly = false;

    // Modal de ajuste
    public ?int $adjustingProductId = null;
    public ?int $adjustingBranchId  = null;
    public string $adjustProductName = '';
    public string $adjustMode        = 'add';
    public string $adjustQuantity    = '';
    public string $adjustNotes       = '';
    public int $currentQuantity      = 0;

    public bool $isSuperAdmin = false;
    public bool $canAdjust    = false;
    public bool $canToggle    = false;

    protected function rules(): array
    {
        return [
            'adjustQuantity' => ['required', 'integer', 'min:0'],
            'adjustNotes'    => ['required', 'string', 'max:500'],
        ];
    }

    protected function messages(): array
    {
        return [
            'adjustQuantity.required' => 'Informe a quantidade.',
            'adjustQuantity.integer'  => 'Informe um número inteiro.',
            'adjustQuantity.min'      => 'A quantidade deve ser zero ou maior.',
            'adjustNotes.required'    => 'Informe a observação para auditoria.',
        ];
    }

    public function mount(): void
    {
        $user = auth()->user();
        $this->isSuperAdmin = $user->isSuperAdmin();

        if ($this->isSuperAdmin) {
            $this->canAdjust = $this->canToggle = true;
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');
            $this->canAdjust = $user->hasPermission('stock.adjust', $company);
            $this->canToggle = $user->hasPermission('stock.toggle', $company);
        }

        $branches = Branch::where('active', true)->get();
        if ($branches->count() === 1) {
            $this->branchFilter = $branches->first()->id;
        }
    }

    private function currentCompanyId(): ?int
    {
        if ($this->isSuperAdmin) {
            return null;
        }

        return app()->bound('current.company')
            ? app('current.company')->id
            : auth()->user()->companies()->first()?->id;
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingBranchFilter(): void { $this->resetPage(); }
    public function updatingShowLowStockOnly(): void { $this->resetPage(); }

    private function checkPermission(string $permission): void
    {
        if ($this->isSuperAdmin) {
            return;
        }

        $company = app()->bound('current.company')
            ? app('current.company')
            : auth()->user()->companies()->first();

        if (! $company || ! auth()->user()->hasPermission($permission, $company)) {
            $this->dispatch('notify', type: 'error', message: 'Sem permissão para esta ação.');
            throw new \Illuminate\Auth\Access\AuthorizationException();
        }
    }

    public function openAdjustModal(int $productId, int $branchId): void
    {
        $this->checkPermission('stock.adjust');

        $pivot = DB::table('branch_product')
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->first();

        $product = Product::withoutGlobalScope(CompanyScope::class)->find($productId);

        $this->adjustingProductId = $productId;
        $this->adjustingBranchId  = $branchId;
        $this->adjustProductName  = $product?->name ?? '';
        $this->currentQuantity    = $pivot?->quantity ?? 0;
        $this->adjustMode         = 'add';
        $this->adjustQuantity     = '';
        $this->adjustNotes        = '';
    }

    public function applyAdjustment(): void
    {
        $this->checkPermission('stock.adjust');
        $this->validate($this->rules(), $this->messages());

        $branch  = Branch::withoutGlobalScope(CompanyScope::class)->findOrFail($this->adjustingBranchId);
        $product = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($this->adjustingProductId);
        $qty     = (int) $this->adjustQuantity;

        $service = app(StockService::class);

        match ($this->adjustMode) {
            'add'      => $service->adjust($branch, $product, $qty, $this->adjustNotes, auth()->user()),
            'subtract' => $service->adjust($branch, $product, -$qty, $this->adjustNotes, auth()->user()),
            'set'      => $service->setQuantity($branch, $product, $qty, $this->adjustNotes, auth()->user()),
        };

        $this->reset(['adjustingProductId', 'adjustingBranchId', 'adjustProductName', 'adjustQuantity', 'adjustNotes', 'currentQuantity']);
        session()->flash('status', 'Estoque atualizado com sucesso.');
    }

    public function toggleTracking(int $productId, int $branchId): void
    {
        $this->checkPermission('stock.toggle');

        $pivot = DB::table('branch_product')
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->first();

        $branch  = Branch::withoutGlobalScope(CompanyScope::class)->findOrFail($branchId);
        $product = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($productId);

        app(StockService::class)->toggleTracking($branch, $product, ! ($pivot?->track_stock ?? false));
    }

    public function updateMinQuantity(int $productId, int $branchId, int $minQuantity): void
    {
        $this->checkPermission('stock.adjust');

        DB::table('branch_product')
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->update(['min_quantity' => max(0, $minQuantity)]);
    }

    public function render()
    {
        // Filiais da empresa (CompanyScope aplicado automaticamente)
        $branches = Branch::where('active', true)->orderBy('name')->get();
        $branchIds = $branches->pluck('id');

        $query = DB::table('branch_product')
            ->join('products', 'products.id', '=', 'branch_product.product_id')
            ->join('product_categories', 'product_categories.id', '=', 'products.product_category_id')
            ->where('products.active', true)
            ->whereIn('branch_product.branch_id', $branchIds); // garante escopo da empresa

        // Filtro adicional por company_id nos produtos (dupla garantia)
        if (! $this->isSuperAdmin && $this->currentCompanyId()) {
            $query->where('products.company_id', $this->currentCompanyId());
        }

        if ($this->branchFilter) {
            $query->where('branch_product.branch_id', $this->branchFilter);
        }

        if ($this->search) {
            $query->where('products.name', 'like', '%' . $this->search . '%');
        }

        if ($this->showLowStockOnly) {
            $query->where('branch_product.track_stock', true)
                  ->whereColumn('branch_product.quantity', '<=', 'branch_product.min_quantity');
        }

        $items = $query
            ->select(
                'branch_product.branch_id',
                'branch_product.product_id',
                'branch_product.quantity',
                'branch_product.min_quantity',
                'branch_product.track_stock',
                'branch_product.available',
                'products.name as product_name',
                'product_categories.name as category_name'
            )
            ->orderBy('product_categories.name')
            ->orderBy('products.name')
            ->paginate(20);

        $lowStockCount = DB::table('branch_product')
            ->join('products', 'products.id', '=', 'branch_product.product_id')
            ->whereIn('branch_product.branch_id', $branchIds)
            ->where('branch_product.track_stock', true)
            ->whereColumn('branch_product.quantity', '<=', 'branch_product.min_quantity')
            ->when(! $this->isSuperAdmin && $this->currentCompanyId(), fn ($q) => $q->where('products.company_id', $this->currentCompanyId()))
            ->count();

        return view('livewire.admin.stock.index', compact('items', 'branches', 'lowStockCount'))
            ->layout('layouts.app', ['title' => 'Controle de Estoque']);
    }
}
