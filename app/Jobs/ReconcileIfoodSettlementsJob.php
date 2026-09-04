<?php

namespace App\Jobs;

use App\Models\IfoodIntegration;
use App\Services\Ifood\IfoodFinancialReconciliationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Comentado em routes/console.php — endpoint da Financial API do iFood ainda não
 * confirmado em sandbox (mesmo racional de VindiReconciliationJob). Reativar
 * quando o formato real de settlement for validado (Fase 8).
 */
class ReconcileIfoodSettlementsJob implements ShouldQueue
{
    use Queueable;

    public function handle(IfoodFinancialReconciliationService $reconciliationService): void
    {
        $integrations = IfoodIntegration::withoutGlobalScopes()
            ->where('status', 'active')
            ->get();

        foreach ($integrations as $integration) {
            try {
                app()->instance('current.company', $integration->company);

                $reconciliationService->reconcile($integration, now()->subDays(7), now());
            } catch (Throwable $e) {
                Log::channel('discord')->error('iFood: falha ao conciliar repasses da integração, seguindo para as demais', [
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
