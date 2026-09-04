<?php

namespace App\Jobs;

use App\Models\IfoodIntegration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Roda a cada 5min. Marca webhook_status='degraded' em integrações que estavam
 * saudáveis mas pararam de receber webhook — isso automaticamente inclui a
 * integração no próximo PollIfoodEventsJob (acoplamento via a mesma coluna,
 * sem lógica duplicada). Integrações recém-conectadas (webhook_status
 * 'unknown', nunca receberam webhook) já são cobertas pelo polling desde o
 * início, não precisam de detecção aqui.
 */
class MonitorIfoodWebhookHealthJob implements ShouldQueue
{
    use Queueable;

    private const STALE_AFTER_MINUTES = 10;

    public function handle(): void
    {
        $threshold = now()->subMinutes(self::STALE_AFTER_MINUTES);

        $stale = IfoodIntegration::withoutGlobalScopes()
            ->where('status', 'active')
            ->where('webhook_status', 'healthy')
            ->where(function ($query) use ($threshold) {
                $query->whereNull('last_webhook_received_at')
                    ->orWhere('last_webhook_received_at', '<', $threshold);
            })
            ->get();

        foreach ($stale as $integration) {
            $integration->update(['webhook_status' => 'degraded']);

            Log::channel('ifood')->warning('iFood: webhook sem receber eventos há tempo anormal — fallback de polling acionado', [
                'ifood_integration_id' => $integration->id,
                'company_id' => $integration->company_id,
                'last_webhook_received_at' => $integration->last_webhook_received_at,
            ]);
        }
    }
}
