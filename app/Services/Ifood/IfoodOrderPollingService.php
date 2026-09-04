<?php

namespace App\Services\Ifood;

use App\Contracts\IfoodGatewayContract;
use App\Jobs\ProcessIfoodOrderJob;
use App\Models\IfoodIntegration;
use App\Models\IfoodOrderEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

class IfoodOrderPollingService
{
    public function __construct(private readonly IfoodGatewayContract $gateway) {}

    /**
     * Busca eventos pendentes via polling, processa cada um sincronamente (pra
     * poder dar ACK só depois de sucesso — ver Fase 3.6 do plano) e confirma
     * recebimento junto ao iFood. Falha transitória num evento não impede ACK
     * dos demais nem interrompe a integração seguinte (isso é responsabilidade
     * de quem chama pollFor por integração, ver PollIfoodEventsJob).
     */
    public function pollFor(IfoodIntegration $integration): void
    {
        $rawEvents = $this->gateway->pollEvents($integration);
        $ackableIds = [];

        foreach ($rawEvents as $raw) {
            $eventId = $raw['id'] ?? null;
            $eventType = $raw['code'] ?? $raw['fullCode'] ?? null;

            if (! $eventId || ! $eventType) {
                Log::channel('ifood')->warning('iFood polling: evento sem id/code, ignorado', ['raw' => $raw]);

                continue;
            }

            try {
                $event = IfoodOrderEvent::create([
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'source' => 'polling',
                    'ifood_integration_id' => $integration->id,
                    'payload' => $raw,
                    'status' => 'pending',
                ]);
            } catch (UniqueConstraintViolationException) {
                // Evento já existe (chegou via webhook antes, ou de um ciclo de polling
                // anterior que persistiu mas não conseguiu confirmar ACK) — mesmo assim
                // precisa de ACK, senão o iFood devolve esse evento pra sempre.
                $ackableIds[] = $eventId;

                continue;
            }

            try {
                ProcessIfoodOrderJob::dispatchSync($event->id);
                $ackableIds[] = $eventId;
            } catch (Throwable $e) {
                // Falha transitória — não confirma ACK; o próximo ciclo de polling
                // busca esse evento de novo (iFood ainda não recebeu confirmação).
                Log::channel('ifood')->error('iFood polling: falha ao processar evento, ACK não confirmado', [
                    'event_id' => $eventId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($ackableIds !== []) {
            $this->gateway->acknowledgeEvents($integration, $ackableIds);
        }

        $integration->update(['last_synced_at' => now()]);
    }
}
