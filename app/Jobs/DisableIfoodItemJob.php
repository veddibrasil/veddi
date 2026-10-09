<?php

namespace App\Jobs;

use App\Models\IfoodIntegration;
use App\Services\Ifood\IfoodCatalogSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tira um item de venda no iFood depois que o vínculo local sumiu (produto excluído ou
 * desmarcado da filial). Recebe o id do item porque o pivot que o guardava já foi apagado.
 */
class DisableIfoodItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 300, 600];

    public function __construct(public int $branchId, public string $itemId) {}

    public function handle(IfoodCatalogSyncService $catalogSyncService): void
    {
        // Suspensa também: a loja volta a vender ao retomar, e o pivot com o id do item já foi
        // apagado aqui, então nenhum sync posterior tiraria o item de venda.
        $integration = IfoodIntegration::withoutGlobalScopes()
            ->where('branch_id', $this->branchId)
            ->whereIn('status', ['active', 'paused'])
            ->whereNotNull('merchant_id')
            ->first();

        if (! $integration || ! $integration->company?->canUseIfoodIntegration()) {
            return;
        }

        $catalogSyncService->disableItem($integration, $this->itemId);
    }
}
