<?php

namespace App\Jobs;

use App\Exceptions\KeetaRateLimitedException;
use App\Models\KeetaIntegration;
use App\Services\Keeta\KeetaCatalogSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Avisa a Keeta que o cardápio da filial mudou (merchantUpdate vazio: ela busca o GET merchant
 * de novo). Alterações em sequência viram um aviso só (debounce() + unique).
 *
 * Publicação é opt-in: enquanto o restaurante não publica, nenhum aviso automático acontece.
 */
class SyncKeetaCatalogJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public array $backoff = [60, 120, 300];

    public int $tries = 4;

    public int $uniqueFor = 120;

    public function __construct(public int $branchId) {}

    public function uniqueId(): string
    {
        return "keeta-catalog:{$this->branchId}";
    }

    /** Aviso automático depois de uma alteração: espera um minuto juntando as próximas. */
    public static function debounce(int $branchId): void
    {
        static::dispatch($branchId)->delay(now()->addSeconds(60));
    }

    /** Filiais da empresa com Keeta ativa e cardápio publicado. */
    public static function debounceForCompany(int $companyId, ?array $branchIds = null): void
    {
        if (! config('keeta.enabled')) {
            return;
        }

        KeetaIntegration::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', KeetaIntegration::STATUS_ACTIVE)
            ->whereNotNull('keeta_merchant_id')
            ->whereNotNull('catalog_published_at')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->pluck('branch_id')
            ->each(fn (int $branchId) => static::debounce($branchId));
    }

    public function handle(KeetaCatalogSyncService $catalog): void
    {
        $integration = KeetaIntegration::withoutGlobalScopes()
            ->with('company')
            ->where('branch_id', $this->branchId)
            ->where('status', KeetaIntegration::STATUS_ACTIVE)
            ->whereNotNull('keeta_merchant_id')
            ->first();

        if (! $integration || ! $integration->isOperational() || ! $integration->hasPublishedCatalog()) {
            return;
        }

        try {
            $catalog->notifyMenuChanged($integration);
        } catch (KeetaRateLimitedException $e) {
            $this->release(max(1, $e->retryAfter));
        }
    }
}
