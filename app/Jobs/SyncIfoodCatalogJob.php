<?php

namespace App\Jobs;

use App\Models\IfoodIntegration;
use App\Models\Product;
use App\Services\Ifood\IfoodCatalogSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sem $productId: sync completo da filial (batch periódico de segurança).
 * Com $productId: sync de disponibilidade em tempo real de 1 produto (disparado
 * por ProductObserver quando active/available_in_ifood mudam).
 */
class SyncIfoodCatalogJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $branchId, public ?int $productId = null) {}

    public function handle(IfoodCatalogSyncService $catalogSyncService): void
    {
        $integration = IfoodIntegration::withoutGlobalScopes()
            ->where('branch_id', $this->branchId)
            ->where('status', 'active')
            ->first();

        if (! $integration) {
            return;
        }

        try {
            app()->instance('current.company', $integration->company);

            if ($this->productId) {
                $product = Product::withoutGlobalScopes()->find($this->productId);
                if ($product) {
                    $catalogSyncService->syncAvailability($integration->branch, $product);
                }

                return;
            }

            $catalogSyncService->syncFullCatalog($integration);
        } finally {
            app()->forgetInstance('current.company');
        }
    }
}
