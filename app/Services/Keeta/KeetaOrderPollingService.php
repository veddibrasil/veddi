<?php

namespace App\Services\Keeta;

use App\Contracts\KeetaGatewayContract;
use App\Http\Controllers\KeetaWebhookController;
use App\Models\KeetaIntegration;
use App\Models\KeetaWebhookEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Fallback do webhook: busca eventos pendentes na Keeta (GET /v1/events:polling), grava no
 * mesmo keeta_webhook_events (eventId único: o que já veio pelo webhook não duplica) e só
 * depois confirma (ack) — evento que não foi gravado volta no próximo polling.
 *
 * A Keeta aceita 2 chamadas por minuto por token (o token é do app inteiro) e até 100 lojas
 * por chamada; entram só as lojas sem webhook saudável, as que esperam há mais tempo primeiro.
 */
class KeetaOrderPollingService
{
    private const RATE_LIMIT_KEY = 'keeta:polling';

    private const MAX_CALLS_PER_MINUTE = 2;

    public function __construct(private readonly KeetaGatewayContract $gateway) {}

    /** @return int eventos novos gravados */
    public function poll(): int
    {
        $integrations = KeetaIntegration::operational(includePaused: true)
            ->with('company')
            ->whereIn('webhook_status', ['unknown', 'degraded'])
            ->orderByRaw('last_polled_at IS NOT NULL')
            ->orderBy('last_polled_at')
            ->limit((int) config('keeta.polling_batch_size', 100))
            ->get()
            ->filter(fn (KeetaIntegration $integration) => $integration->isOperational(includePaused: true))
            ->values();

        if ($integrations->isEmpty()) {
            return 0;
        }

        if (RateLimiter::tooManyAttempts(self::RATE_LIMIT_KEY, self::MAX_CALLS_PER_MINUTE)) {
            return 0;
        }
        RateLimiter::hit(self::RATE_LIMIT_KEY, 60);

        $events = $this->gateway->pollEvents($integrations->pluck('merchant_ref')->all());

        KeetaIntegration::withoutGlobalScopes()->whereIn('id', $integrations->modelKeys())->update(['last_polled_at' => now()]);

        if ($events === []) {
            return 0;
        }

        usort($events, fn (array $a, array $b) => strcmp((string) ($a['createdAt'] ?? ''), (string) ($b['createdAt'] ?? '')));

        // Uma loja só no lote: o evento é dela. Com várias, a loja sai do pedido (ProcessKeetaEventJob).
        $single = $integrations->count() === 1 ? $integrations->first() : null;
        $created = 0;
        $ack = [];

        foreach ($events as $payload) {
            $eventId = (string) ($payload['eventId'] ?? '');
            $type = strtoupper((string) ($payload['eventType'] ?? ''));
            $orderId = (string) ($payload['orderId'] ?? '');

            if ($eventId === '' || $type === '' || strlen($eventId) > 191 || strlen($orderId) > 40) {
                continue;
            }

            try {
                $event = KeetaWebhookController::store($payload, $eventId, $type, $orderId, $single, KeetaWebhookEvent::SOURCE_POLLING);
            } catch (Throwable $e) {
                // Não grava, não confirma: volta no próximo polling.
                report($e);

                continue;
            }

            $created += $event ? 1 : 0;
            $ack[] = ['id' => $eventId, 'orderId' => $orderId, 'eventType' => $type];
        }

        if ($ack !== []) {
            $this->gateway->acknowledgeEvents($ack);
            KeetaWebhookEvent::whereIn('event_id', array_column($ack, 'id'))->whereNull('acknowledged_at')->update(['acknowledged_at' => now()]);
        }

        Log::channel('keeta')->info('Keeta: polling de eventos', [
            'lojas' => $integrations->count(),
            'eventos' => count($events),
            'novos' => $created,
        ]);

        return $created;
    }
}
