<?php

namespace App\Observers;

use App\Jobs\DisableIfoodItemJob;
use App\Jobs\SetFood99ItemStatusJob;
use App\Jobs\SyncFood99CatalogJob;
use App\Jobs\SyncIfoodCatalogJob;
use App\Models\Food99Integration;
use App\Models\IfoodIntegration;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProductObserver
{
    /**
     * Dispara sync de disponibilidade em tempo real no iFood quando o produto
     * muda de status (pausar item em falta, reativar, etc). Preço/cardápio
     * completo fica pro batch diário (SyncIfoodCatalogJob sem productId).
     */
    public function saved(Product $product): void
    {
        $this->syncFood99($product);

        if (! $product->wasChanged(['active', 'available_in_ifood'])) {
            return;
        }

        $branchIds = IfoodIntegration::withoutGlobalScopes()
            ->where('company_id', $product->company_id)
            ->where('status', 'active')
            ->whereIn('branch_id', $product->branches()->pluck('branches.id'))
            ->pluck('branch_id');

        foreach ($branchIds as $branchId) {
            SyncIfoodCatalogJob::dispatch($branchId, $product->id);
        }
    }

    /**
     * 99Food: pausar/reativar muda o status do item na hora; entrar ou sair do canal muda o
     * cardápio, que vai no próximo upload completo (o item novo ainda não existe lá).
     */
    private function syncFood99(Product $product): void
    {
        if (! config('food99.enabled') || ! $product->wasChanged(['active', 'available_in_99food'])) {
            return;
        }

        // Pivot direto: o escopo de empresa das filiais pode ser de outra empresa (super admin).
        $branchIds = DB::table('branch_product')->where('product_id', $product->id)->pluck('branch_id')->all();

        if ($product->wasChanged('available_in_99food')) {
            Food99Integration::withoutGlobalScopes()
                ->where('company_id', $product->company_id)
                ->where('status', Food99Integration::STATUS_ACTIVE)
                ->whereNotNull('catalog_published_at')
                ->whereIn('branch_id', $branchIds)
                ->pluck('branch_id')
                ->each(fn (int $branchId) => SyncFood99CatalogJob::debounce($branchId));

            return;
        }

        SetFood99ItemStatusJob::forProduct($product->company_id, $product->id, $branchIds);
    }

    /**
     * Exclusão definitiva apaga o pivot com o id do item no iFood: tira de venda antes,
     * senão o iFood segue vendendo um produto que não existe mais aqui.
     */
    public function forceDeleting(Product $product): void
    {
        $items = DB::table('branch_product')
            ->where('product_id', $product->id)
            ->whereNotNull('ifood_item_id')
            ->whereIn('branch_id', IfoodIntegration::withoutGlobalScopes()
                ->where('company_id', $product->company_id)
                ->whereIn('status', ['active', 'paused'])
                ->select('branch_id'))
            ->get(['branch_id', 'ifood_item_id']);

        foreach ($items as $item) {
            DisableIfoodItemJob::dispatch($item->branch_id, $item->ifood_item_id)->afterCommit();
        }

        SetFood99ItemStatusJob::forProduct($product->company_id, $product->id, disable: true);
    }
}
