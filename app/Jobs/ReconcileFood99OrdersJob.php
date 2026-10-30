<?php

namespace App\Jobs;

use App\Models\Food99Integration;
use App\Models\Food99WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * O 99Food Protocol não tem polling de eventos: callback perdido deixaria o pedido parado.
 * Dispara a releitura dos pedidos em aberto de cada loja (ReconcileFood99IntegrationJob).
 *
 * Também reenfileira callback preso: evento 'pending' que nenhum worker pegou ou 'processing'
 * de um worker que morreu no meio (timeout, deploy).
 */
class ReconcileFood99OrdersJob implements ShouldQueue
{
    use Queueable;

    /** Processamento que passa disso é de worker que morreu (o job leva segundos). */
    public const STUCK_MINUTES = 5;

    public function handle(): void
    {
        $this->requeueStuckEvents();

        // Um job por loja: uma loja lenta ou fora do ar não atrasa as das outras empresas.
        Food99Integration::operational(includePaused: true)
            ->with('company')
            ->get()
            ->filter(fn (Food99Integration $integration) => $integration->isOperational(includePaused: true))
            ->each(fn (Food99Integration $integration) => ReconcileFood99IntegrationJob::dispatch($integration->id));
    }

    private function requeueStuckEvents(): void
    {
        Food99WebhookEvent::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', Food99WebhookEvent::STATUS_PENDING)->where('updated_at', '<', now()->subMinutes(2)))
                ->orWhere(fn ($q) => $q->where('status', Food99WebhookEvent::STATUS_PROCESSING)->where('updated_at', '<', now()->subMinutes(self::STUCK_MINUTES))))
            ->where('created_at', '>=', now()->subDay())
            ->limit(100)
            ->get()
            ->each(function (Food99WebhookEvent $event) {
                if ($event->status === Food99WebhookEvent::STATUS_PROCESSING) {
                    $event->update(['status' => Food99WebhookEvent::STATUS_PENDING]);
                }
                ProcessFood99EventJob::dispatch($event->id);
            });
    }
}
