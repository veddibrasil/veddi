<?php

namespace App\Livewire\Admin;

use App\DTOs\SoldItemsFilters;
use App\Livewire\Admin\Concerns\RequiresCompanyAdmin;
use App\Models\Order;
use App\Models\Product;
use App\Services\Sales\SalesInsightsService;
use App\Services\Sales\SoldItemsReportService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Lazy]
class SalesSuggestions extends Component
{
    use RequiresCompanyAdmin;

    #[Locked]
    public string $context = 'all';

    #[Locked]
    public ?int $productId = null;

    #[Locked]
    public ?int $orderId = null;

    #[Locked]
    public ?int $branchId = null;

    #[Locked]
    public ?int $categoryId = null;

    public function mount(string $context = 'all', ?int $productId = null, ?int $orderId = null, ?int $branchId = null, ?int $categoryId = null): void
    {
        abort_unless(in_array($context, ['all', 'products', 'orders', 'stock', 'coupons'], true), 404);
        $this->context = $context;
        $this->productId = $productId;
        $this->orderId = $orderId;
        $this->branchId = $branchId;
        $this->categoryId = $categoryId;
        $this->authorizeTargets();
    }

    public function hydrate(): void
    {
        $this->authorizeTargets();
    }

    private function authorizeTargets(): void
    {
        $companyId = app('current.company')->id;
        if ($this->productId !== null) {
            abort_unless(Product::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')->whereKey($this->productId)->exists(), 404);
        }
        if ($this->orderId !== null) {
            abort_unless(Order::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($this->orderId)->exists(), 404);
        }
    }

    #[Computed]
    public function filters(): SoldItemsFilters
    {
        return SoldItemsFilters::fromInput(
            app('current.company')->id,
            today()->subDays(27)->format('Y-m-d'),
            today()->format('Y-m-d'),
            $this->branchId,
            categoryId: $this->categoryId,
        );
    }

    #[Computed]
    public function suggestions(): array
    {
        $company = app('current.company');
        $productIds = [];
        $filters = $this->filters;

        if ($this->productId !== null) {
            $product = Product::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('deleted_at')->findOrFail($this->productId);
            $productIds = [$product->id];
        }

        if ($this->orderId !== null) {
            $order = Order::withoutGlobalScopes()->where('company_id', $company->id)->with('items')->findOrFail($this->orderId);
            $productIds = $order->items->pluck('product_id')->filter()->unique()->values()->all();
            $filters = SoldItemsFilters::fromInput($company->id, $filters->start->format('Y-m-d'), $filters->end->format('Y-m-d'), $order->branch_id);
        }

        $result = app(SalesInsightsService::class)->generate($company, $filters, $this->orderId ? 'all' : $this->context, $productIds);

        // Um pedido só com itens avulsos não deve exibir recomendações de outros produtos.
        if ($this->orderId !== null && $productIds === []) {
            $result['insights'] = [];
        }

        return $result;
    }

    public function refresh(): void
    {
        $filters = $this->suggestions['window'];
        $companyId = app('current.company')->id;
        $reports = app(SoldItemsReportService::class);
        $reports->forget($companyId, $filters);
        $reports->forget($companyId, $filters->withChannel(null));
        app(SalesInsightsService::class)->forget($companyId, $filters);
        unset($this->suggestions);
    }

    public function placeholder(): string
    {
        return '<div role="status" class="rounded-xl border border-neutral-200 p-4 text-sm text-neutral-500 dark:border-zinc-700 dark:text-neutral-400">Carregando sugestões de vendas…</div>';
    }

    public function render()
    {
        return view('livewire.admin.sales-suggestions');
    }
}
