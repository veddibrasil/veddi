<?php

namespace App\Jobs;

use App\Models\KeetaIntegration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Roda a cada 5 min. Marca webhook_status='degraded' em integrações que estavam saudáveis mas
 * não recebem webhook há STALE_AFTER_MINUTES — isso as inclui no PollKeetaEventsJob (mesma
 * coluna, sem lógica duplicada). O primeiro webhook que chegar volta a integração pra 'healthy'.
 * A Keeta só manda evento quando há pedido: loja parada também entra no polling, que é barato
 * (uma chamada pra até 100 lojas) e não duplica nada (eventId único).
 */
class MonitorKeetaWebhookHealthJob implements ShouldQueue
{
    use Queueable;

    public const STALE_AFTER_MINUTES = 30;

    public function handle(): void
    {
        $threshold = now()->subMinutes(self::STALE_AFTER_MINUTES);

        $stale = KeetaIntegration::withoutGlobalScopes()
            ->whereIn('status', [KeetaIntegration::STATUS_ACTIVE, KeetaIntegration::STATUS_PAUSED])
            ->where('webhook_status', 'healthy')
            ->where(fn ($query) => $query->whereNull('last_webhook_received_at')->orWhere('last_webhook_received_at', '<', $threshold))
            ->get();

        foreach ($stale as $integration) {
            $integration->update(['webhook_status' => 'degraded']);

            Log::channel('keeta')->info('Keeta: webhook sem eventos, polling acionado', [
                'keeta_integration_id' => $integration->id,
                'company_id' => $integration->company_id,
            ]);
        }
    }
}
