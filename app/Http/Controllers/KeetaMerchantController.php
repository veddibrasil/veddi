<?php

namespace App\Http\Controllers;

use App\Models\KeetaIntegration;
use App\Services\Keeta\KeetaCatalogSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GET merchant do Open Delivery: a Keeta busca aqui o cardápio, os horários e o status da loja
 * (uma URL por filial, registrada no onboarding). Protegido pelo X-API-KEY da integração; só
 * responde com o cardápio publicado pelo restaurante (publicação é opt-in).
 */
class KeetaMerchantController extends Controller
{
    public function __invoke(Request $request, string $merchantRef, KeetaCatalogSyncService $catalog): JsonResponse
    {
        $integration = strlen($merchantRef) <= 100
            ? KeetaIntegration::withoutGlobalScopes()->with('company')->where('merchant_ref', $merchantRef)->first()
            : null;

        $apiKey = (string) $request->header('X-API-KEY', '');
        $expected = (string) ($integration?->menu_api_key ?? '');

        if (! $integration || $expected === '' || ! hash_equals($expected, $apiKey)) {
            Log::channel('keeta')->warning('Keeta cardápio: merchantId ou X-API-KEY inválido', ['ip' => $request->ip()]);

            return $this->problem('Merchant not found', 404);
        }

        if (! $integration->isOperational(includePaused: true) || ! $integration->hasPublishedCatalog()) {
            return $this->problem('Merchant not available', 404);
        }

        try {
            $merchant = $catalog->merchantPayload($integration);
        } catch (Throwable $e) {
            report($e);

            return $this->problem('Unexpected error', 503);
        }

        $integration->forceFill(['last_menu_fetched_at' => now()])->saveQuietly();

        return response()->json($merchant, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function problem(string $title, int $status): JsonResponse
    {
        return response()->json(['title' => $title, 'status' => $status], $status, ['Content-Type' => 'application/problem+json']);
    }
}
