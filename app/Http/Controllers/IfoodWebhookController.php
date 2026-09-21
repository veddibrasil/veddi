<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIfoodOrderJob;
use App\Models\IfoodIntegration;
use App\Models\IfoodOrderEvent;
use App\Services\Ifood\IfoodWebhookSignatureValidator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * NOTA: assume um evento por POST, conforme o payload documentado pelo iFood
 * pra integrações diretas. Se o sandbox (Fase 8) revelar que o iFood também
 * envia lotes de eventos (array) no mesmo POST, este controller precisa
 * iterar sobre o payload — não assumir isso sem confirmar.
 */
class IfoodWebhookController extends Controller
{
    public function __invoke(Request $request, IfoodWebhookSignatureValidator $validator): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = $request->json()->all();

        if (! $validator->isValid($rawBody, $request->header('X-IFood-Signature'))) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Presença tem seu próprio payload, sem merchantId/orderId. Nunca
        // persistir KEEPALIVE como pedido nem enfileirar seu processamento.
        if (($payload['code'] ?? $payload['fullCode'] ?? null) === 'KEEPALIVE') {
            if (array_key_exists('merchantIds', $payload)) {
                if (! is_array($payload['merchantIds'])
                    || count($payload['merchantIds']) > 1000
                    || collect($payload['merchantIds'])->contains(fn ($id) => ! is_string($id))) {
                    return response()->json(['error' => 'Invalid merchantIds'], 422);
                }

                $merchantIds = IfoodIntegration::withoutGlobalScopes()
                    ->where('status', 'active')
                    ->whereIn('merchant_id', $payload['merchantIds'])
                    ->pluck('merchant_id')->unique()->values()->all();

                return response()->json(['merchantIds' => $merchantIds], 202);
            }

            return response()->json(['status' => 'accepted'], 202);
        }

        $merchantId = $payload['merchantId'] ?? null;

        if (! $merchantId) {
            Log::channel('ifood')->warning('iFood webhook: merchantId ausente no payload', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Missing merchantId'], 422);
        }

        $integration = IfoodIntegration::withoutGlobalScopes()
            ->where('merchant_id', $merchantId)
            ->where('status', 'active')
            ->first();

        if (! $integration) {
            Log::channel('ifood')->warning('iFood webhook: integração não encontrada ou inativa', ['merchant_id' => $merchantId]);

            return response()->json(['error' => 'Unknown merchant'], 404);
        }

        $eventId = $payload['id'] ?? null;
        $eventType = $payload['code'] ?? $payload['fullCode'] ?? null;

        if (! $eventId || ! $eventType) {
            Log::channel('ifood')->warning('iFood webhook: evento sem id ou code', ['payload' => $payload]);

            return response()->json(['error' => 'Missing event id/code'], 422);
        }

        try {
            $event = IfoodOrderEvent::create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'source' => 'webhook',
                'ifood_integration_id' => $integration->id,
                'payload' => $payload,
                'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            // Webhook do iFood é at-least-once — reentrega do mesmo event_id é esperada.
            // Já processado (ou em processamento) antes; não redespacha.
            Log::channel('ifood')->info('iFood webhook: evento duplicado, ignorado', ['event_id' => $eventId]);

            return response()->json(['status' => 'duplicate'], 202);
        }

        $integration->update([
            'last_webhook_received_at' => now(),
            'webhook_status' => 'healthy',
        ]);

        ProcessIfoodOrderJob::dispatch($event->id);

        return response()->json(['status' => 'queued'], 202);
    }
}
