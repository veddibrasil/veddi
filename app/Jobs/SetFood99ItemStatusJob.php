<?php

namespace App\Jobs;

use App\Exceptions\Food99RateLimitedException;
use App\Models\Food99Integration;
use App\Services\Food99\Food99CatalogSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Disponibilidade em tempo real (pausa de item, estoque zerado, reativação) na 99Food. Com
 * $disable, tira de venda sem olhar o cadastro (produto excluído ou filial desmarcada).
 */
class SetFood99ItemStatusJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 300];

    /** @param  array<int, int>  $productIds */
    public function __construct(public int $branchId, public array $productIds, public bool $disable = false) {}

    /** Filiais da empresa com 99Food conectada e cardápio publicado que vendem o produto. */
    public static function forProduct(int $companyId, int $productId, ?array $branchIds = null, bool $disable = false): void
    {
        Food99Integration::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', $disable ? [Food99Integration::STATUS_ACTIVE, Food99Integration::STATUS_PAUSED] : [Food99Integration::STATUS_ACTIVE])
            ->whereNotNull('catalog_published_at')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->pluck('branch_id')
            ->each(fn (int $branchId) => static::dispatch($branchId, [$productId], $disable)->afterCommit());
    }

    public function handle(Food99CatalogSyncService $catalog): void
    {
        // Suspensa também tira de venda: ao retomar, o item não pode voltar vendendo.
        $integration = Food99Integration::withoutGlobalScopes()
            ->where('branch_id', $this->branchId)
            ->whereIn('status', $this->disable ? [Food99Integration::STATUS_ACTIVE, Food99Integration::STATUS_PAUSED] : [Food99Integration::STATUS_ACTIVE])
            ->whereNotNull('shop_id')
            ->first();

        if (! $integration || ! $integration->hasPublishedCatalog() || ! $integration->company?->canUseMarketplaceIntegrations()) {
            return;
        }

        try {
            $this->disable
                ? $catalog->disableItems($integration, $this->productIds)
                : $catalog->syncAvailability($integration, $this->productIds);
        } catch (Food99RateLimitedException $e) {
            $this->release(max(1, $e->retryAfter));
        }
    }
}
