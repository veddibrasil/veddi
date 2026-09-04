<?php

namespace App\Jobs;

use App\Models\IfoodIntegration;
use App\Services\Ifood\IfoodOrderPollingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fallback de polling — só cobre integrações que não estão saudáveis via
 * webhook (webhook_status 'unknown' logo após conectar, ou 'degraded' quando
 * MonitorIfoodWebhookHealthJob detecta silêncio anormal). Integrações
 * saudáveis via webhook são puladas pra não bater na API do iFood sem
 * necessidade em ambiente multiempresa.
 */
class PollIfoodEventsJob implements ShouldQueue
{
    use Queueable;

    public function handle(IfoodOrderPollingService $pollingService): void
    {
        $integrations = IfoodIntegration::withoutGlobalScopes()
            ->where('status', 'active')
            ->whereIn('webhook_status', ['degraded', 'unknown'])
            ->get();

        foreach ($integrations as $integration) {
            try {
                // Bind explícito por iteração — job roda fora de request HTTP e itera
                // múltiplas empresas na mesma execução. Nunca deixar vazar pra próxima
                // iteração (ver restrição de multi-tenancy do plano de integração).
                app()->instance('current.company', $integration->company);

                $pollingService->pollFor($integration);
            } catch (Throwable $e) {
                Log::channel('discord')->error('iFood polling: falha ao processar integração, seguindo para as demais', [
                    'ifood_integration_id' => $integration->id,
                    'company_id' => $integration->company_id,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                app()->forgetInstance('current.company');
            }
        }
    }
}
