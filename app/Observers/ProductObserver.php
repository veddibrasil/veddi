<?php

namespace App\Observers;

use App\Jobs\DisableIfoodItemJob;
use App\Jobs\SyncIfoodCatalogJob;
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
    }
}
