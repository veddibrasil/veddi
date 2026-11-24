<?php

namespace App\Livewire\Admin\Products;

use App\Models\Branch;
use App\Models\Product;
use App\Services\Order\StockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pausa rápida de produto na filial, para quem está no balcão e na cozinha: acabou o
 * ingrediente, o item sai de venda no chat, no PDV e nos marketplaces de uma vez. Caixa,
 * cozinha e bar usam a mesma lista ao mesmo tempo (a tela se atualiza sozinha).
 */
class Pauses extends Component
{
    use WithPagination;

    public ?int $branchId = null;

    public string $search = '';

    /** all | paused */
    public string $filter = 'all';

    /** Resultado da última ação; não usa flash porque o wire:poll consumiria a mensagem. */
    public string $notice = '';

    public bool $noticeIsError = false;

    /** Papel preso a uma filial só pausa a própria (não confiar no branchId vindo do navegador). */
    #[Locked]
    public ?int $lockedBranchId = null;

    public function mount(): void
    {
        $company = app()->bound('current.company') ? app('current.company') : null;
        abort_unless($company, 403);
        $this->authorizePause();

        $this->lockedBranchId = auth()->user()->lockedBranchId($company);
        $this->branchId = $this->lockedBranchId ?? $this->branches()->first()?->id;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function updatingBranchId(): void
    {
        $this->resetPage();
    }

    public function pause(int $productId, StockService $stock): void
    {
        $this->toggle($productId, $stock, true);
    }

    public function resume(int $productId, StockService $stock): void
    {
        $this->toggle($productId, $stock, false);
    }

    private function toggle(int $productId, StockService $stock, bool $pause): void
    {
        $this->authorizePause();
        $branch = $this->selectedBranch();
        // CompanyScope: produto de outra empresa não é encontrado.
        $product = Product::whereKey($productId)->firstOrFail();

        $done = $pause
            ? $stock->pause($branch, $product, auth()->user())
            : $stock->resume($branch, $product, auth()->user());

        $this->noticeIsError = ! $done;
        $this->notice = match (true) {
            ! $done => "{$product->name} não está no cardápio de {$branch->name}.",
            $pause => "{$product->name} pausado em {$branch->name}: saiu do chat, do PDV e dos marketplaces.",
            default => "{$product->name} voltou à venda em {$branch->name}.",
        };
    }

    private function authorizePause(): void
    {
        $user = auth()->user();
        $company = app()->bound('current.company') ? app('current.company') : null;

        abort_unless($user->isSuperAdmin() || ($company && $user->hasPermission('products.pause', $company)), 403);
    }

    /** @return Collection<int, Branch> */
    private function branches(): Collection
    {
        return Branch::where('active', true)
            ->when($this->lockedBranchId, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('name')
            ->get();
    }

    private function selectedBranch(): Branch
    {
        $branch = $this->branches()->firstWhere('id', $this->branchId);
        abort_unless($branch, 403);

        return $branch;
    }

    public function render()
    {
        $branches = $this->branches();
        $branch = $branches->firstWhere('id', $this->branchId);

        $items = DB::table('branch_product')
            ->join('products', 'products.id', '=', 'branch_product.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.product_category_id')
            ->leftJoin('users', 'users.id', '=', 'branch_product.paused_by')
            ->where('branch_product.branch_id', $branch?->id ?? 0)
            ->where('products.company_id', app('current.company')->id)
            ->where('products.active', true)
            ->whereNull('products.deleted_at')
            ->when($this->search !== '', fn ($q) => $q->where('products.name', 'like', '%'.$this->search.'%'))
            ->when($this->filter === 'paused', fn ($q) => $q->whereNotNull('branch_product.paused_at'))
            ->orderByRaw('branch_product.paused_at is null')
            ->orderBy('product_categories.name')
            ->orderBy('products.name')
            ->select([
                'products.id as product_id',
                'products.name as product_name',
                'product_categories.name as category_name',
                'branch_product.available',
                'branch_product.track_stock',
                'branch_product.quantity',
                'branch_product.paused_at',
                'users.name as paused_by_name',
            ])
            ->paginate(40);

        $items->getCollection()->transform(function ($item) {
            $item->paused_at = $item->paused_at ? Carbon::parse($item->paused_at) : null;

            return $item;
        });

        $pausedCount = $branch
            ? DB::table('branch_product')->where('branch_id', $branch->id)->whereNotNull('paused_at')->count()
            : 0;

        return view('livewire.admin.products.pauses', compact('branches', 'branch', 'items', 'pausedCount'))
            ->layout('layouts.app', ['title' => 'Pausar produtos']);
    }
}
