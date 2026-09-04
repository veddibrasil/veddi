<?php

namespace App\Observers;

use App\Jobs\SyncIfoodCatalogJob;
use App\Models\IfoodIntegration;
use App\Models\Product;

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
}
