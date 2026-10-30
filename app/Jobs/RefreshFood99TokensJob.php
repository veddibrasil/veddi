<?php

namespace App\Jobs;

use App\Exceptions\Food99ApiException;
use App\Models\Food99Integration;
use App\Services\Food99\Food99AuthService;
use App\Services\Food99\Food99ConnectionMonitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renova antes de vencer os tokens das lojas conectadas: o aceite tem prazo de 5 minutos e não
 * pode esbarrar num token expirado. A 99 limita get/refresh a 1 req/30s por loja (o limite é
 * por loja, então uma passada por todas cabe). Loja desvinculada (10101) vira erro com aviso.
 */
class RefreshFood99TokensJob implements ShouldQueue
{
    use Queueable;

    /** Renova o que vence dentro deste intervalo (o job roda a cada 10 min). */
    public const WITHIN_SECONDS = 1800;

    public function handle(Food99AuthService $auth, Food99ConnectionMonitor $monitor): void
    {
        Food99Integration::withoutGlobalScopes()
            ->with('company')
            ->whereNotNull('shop_id')
            ->whereIn('status', [Food99Integration::STATUS_ACTIVE, Food99Integration::STATUS_PAUSED])
            ->each(function (Food99Integration $integration) use ($auth, $monitor) {
                if (! $integration->company?->canUseMarketplaceIntegrations()) {
                    return;
                }

                try {
                    $auth->refreshIfExpiring($integration, self::WITHIN_SECONDS);
                } catch (Food99ApiException $e) {
                    if ($e->errno === 10101) {
                        $integration->update(['status' => Food99Integration::STATUS_ERROR, 'auth_token' => null, 'token_expires_at' => null]);
                        $monitor->process($integration);
                    }

                    Log::channel('food99')->warning('99Food: falha ao renovar token', [
                        'food99_integration_id' => $integration->id,
                        'errno' => $e->errno,
                        'request_id' => $e->requestId,
                    ]);
                } catch (Throwable $e) {
                    report($e);
                }
            });
    }
}
