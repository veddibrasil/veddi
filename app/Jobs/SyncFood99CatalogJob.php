<?php

namespace App\Jobs;

use App\Exceptions\Food99ApiException;
use App\Exceptions\Food99RateLimitedException;
use App\Models\Food99Integration;
use App\Services\Food99\Food99CatalogSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Upload completo do cardápio da filial na 99Food. A 99 aceita 1 upload por minuto e cada um
 * sobrescreve o cardápio inteiro: alterações em sequência viram um envio só (debounce() + unique).
 *
 * Publicação é opt-in: enquanto o restaurante não publica, nenhum envio automático acontece
 * (evita apagar o cardápio de quem já vendia na 99 antes de conectar).
 */
class SyncFood99CatalogJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [60, 120, 300, 600];

    public int $uniqueFor = 120;

    public function __construct(public int $branchId, public bool $publish = false) {}

    public function uniqueId(): string
    {
        return "food99-catalog:{$this->branchId}";
    }

    /** Envio automático depois de uma alteração: espera o minuto do limite juntando as próximas. */
    public static function debounce(int $branchId): void
    {
        static::dispatch($branchId)->delay(now()->addSeconds(60));
    }

    public function handle(Food99CatalogSyncService $catalog): void
    {
        $integration = Food99Integration::withoutGlobalScopes()
            ->where('branch_id', $this->branchId)
            ->where('status', Food99Integration::STATUS_ACTIVE)
            ->first();

        if (! $integration || ! $integration->isOperational()) {
            Food99Integration::withoutGlobalScopes()->where('branch_id', $this->branchId)
                ->whereIn('catalog_sync_status', ['queued'])
                ->update(['catalog_sync_status' => 'failed', 'catalog_sync_error' => 'O envio foi cancelado porque a integração não está ativa. Retome a integração e envie de novo.']);

            return;
        }

        if (! $this->publish && ! $integration->hasPublishedCatalog()) {
            return;
        }

        $previousCompany = app()->bound('current.company') ? app('current.company') : null;

        try {
            app()->instance('current.company', $integration->company);

            if ($this->publish && ! $integration->hasPublishedCatalog()) {
                $integration->update(['catalog_published_at' => now()]);
            }

            $catalog->upload($integration);
        } catch (Food99RateLimitedException $e) {
            $this->release(max(60, $e->retryAfter));
        } finally {
            $previousCompany ? app()->instance('current.company', $previousCompany) : app()->forgetInstance('current.company');
        }
    }

    public function failed(?Throwable $e): void
    {
        Food99Integration::withoutGlobalScopes()->where('branch_id', $this->branchId)->update([
            'catalog_sync_status' => 'failed',
            'catalog_sync_error' => match (true) {
                $e instanceof Food99ApiException => 'O cardápio não foi enviado. '.$e->userMessage(),
                $e instanceof \RuntimeException => $e->getMessage(),
                default => 'O cardápio não foi enviado. Confira categorias, fotos e a conexão com a 99Food antes de tentar de novo.',
            },
        ]);
    }
}
