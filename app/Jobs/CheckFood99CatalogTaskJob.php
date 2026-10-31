<?php

namespace App\Jobs;

use App\Exceptions\Food99RateLimitedException;
use App\Models\Food99Integration;
use App\Services\Food99\Food99CatalogSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Consulta a task de upload do cardápio de uma loja. O callback uploadMenuTaskStatus chega com
 * assinatura que não bate com o corpo e é recusado, então o resultado depende desta consulta.
 */
class CheckFood99CatalogTaskJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $integrationId) {}

    public function uniqueId(): string
    {
        return (string) $this->integrationId;
    }

    public function handle(Food99CatalogSyncService $catalog): void
    {
        // Fora de request: sem escopo de empresa, a integração define a loja.
        $integration = Food99Integration::withoutGlobalScopes()->with('company')->find($this->integrationId);

        if (! $integration?->isOperational()) {
            return;
        }

        try {
            $catalog->refreshTaskStatus($integration);
        } catch (Food99RateLimitedException) {
            // Limite da 99: a próxima rodada consulta de novo.
        } catch (Throwable $e) {
            report($e);
        }

        $catalog->expireStaleTask($integration);
    }
}
