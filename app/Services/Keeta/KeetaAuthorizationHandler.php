<?php

namespace App\Services\Keeta;

use App\Contracts\KeetaEventHandler;
use App\Exceptions\KeetaApiException;
use App\Http\Controllers\KeetaAuthorizationWebhookController;
use App\Models\CompanyNotification;
use App\Models\KeetaIntegration;
use App\Models\KeetaWebhookEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhooks de autorização do Dev Portal (um por loja):
 *  - 1302 (revogação): a loja ligada para de receber pedidos; integração vira "revoked" e a
 *    empresa é avisada.
 *  - 1301 (nova autorização): loja já ligada que tinha sido revogada volta a operar
 *    (onboarding reaplicado). Loja sem filial fica só registrada, visível no super admin.
 */
class KeetaAuthorizationHandler implements KeetaEventHandler
{
    public function __construct(private readonly KeetaAuthService $auth) {}

    public function handles(string $type): bool
    {
        return in_array($type, [KeetaAuthorizationWebhookController::TYPE_GRANTED, KeetaAuthorizationWebhookController::TYPE_REVOKED], true);
    }

    public function handle(KeetaWebhookEvent $event): bool
    {
        $integration = $event->keetaIntegration;
        if (! $integration) {
            // Loja ainda sem filial: o super admin vê em Integrações (autorizações sem vínculo).
            return false;
        }

        return $event->event_type === KeetaAuthorizationWebhookController::TYPE_REVOKED
            ? $this->revoked($integration)
            : $this->granted($integration, (string) ($event->payload['authId'] ?? ''));
    }

    private function revoked(KeetaIntegration $integration): bool
    {
        if (! in_array($integration->status, [KeetaIntegration::STATUS_ACTIVE, KeetaIntegration::STATUS_PAUSED, KeetaIntegration::STATUS_ERROR], true)) {
            return false;
        }

        $integration->forceFill(['status' => KeetaIntegration::STATUS_REVOKED])->save();

        CompanyNotification::create([
            'company_id' => $integration->company_id,
            'type' => 'keeta_alert',
            'title' => 'Keeta: autorização revogada'.($integration->shop_name ? " ({$integration->shop_name})" : ''),
            'subtitle' => 'A loja tirou a autorização do app VEDDI no portal da Keeta. Pedidos novos não chegam até autorizar de novo.',
            'link' => route('admin.settings.keeta'),
        ]);

        Log::channel('keeta')->warning('Keeta: autorização revogada pela loja', [
            'keeta_integration_id' => $integration->id,
            'company_id' => $integration->company_id,
        ]);

        return true;
    }

    private function granted(KeetaIntegration $integration, string $authId): bool
    {
        if ($integration->status !== KeetaIntegration::STATUS_REVOKED) {
            return false;
        }

        $integration->forceFill(['auth_id' => $authId !== '' ? $authId : $integration->auth_id])->save();

        try {
            $this->auth->onboard($integration);
        } catch (Throwable $e) {
            Log::channel('keeta')->error('Keeta: falha ao reativar loja autorizada de novo', [
                'keeta_integration_id' => $integration->id,
                'error' => KeetaApiException::forLog($e),
            ]);

            return true;
        }

        CompanyNotification::create([
            'company_id' => $integration->company_id,
            'type' => 'keeta_alert',
            'title' => 'Keeta: loja autorizada de novo',
            'subtitle' => 'Os pedidos da Keeta voltaram a chegar ao painel.',
            'link' => route('admin.settings.keeta'),
        ]);

        return true;
    }
}
