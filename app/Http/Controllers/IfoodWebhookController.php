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

                // Só lojas operacionais seguem "presentes": empresa bloqueada sai do ar no iFood.
                $integrations = IfoodIntegration::operational()
                    ->with('company')
                    ->whereIn('merchant_id', $payload['merchantIds'])
                    ->get()
                    ->filter(fn (IfoodIntegration $integration) => $integration->isOperational());

                // KEEPALIVE também chega pelo webhook: conta como sinal de vida, senão loja sem
                // pedido por 10 min virava "instável" no monitor de saúde.
                if ($integrations->isNotEmpty()) {
                    IfoodIntegration::withoutGlobalScopes()
                        ->whereIn('id', $integrations->modelKeys())
                        ->update(['last_webhook_received_at' => now(), 'webhook_status' => 'healthy']);
                }

                return response()->json(['merchantIds' => $integrations->pluck('merchant_id')->unique()->values()->all()], 202);
            }

            return response()->json(['status' => 'accepted'], 202);
        }

        $merchantId = $payload['merchantId'] ?? null;

        if (! $merchantId) {
            Log::channel('ifood')->warning('iFood webhook: merchantId ausente no payload', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Missing merchantId'], 422);
        }

        // Pedido novo só pra loja ativa; o resto (cancelamento, negociação, conclusão) é de pedido
        // que já está no painel e precisa chegar mesmo com a integração suspensa.
        $eventCode = $payload['code'] ?? $payload['fullCode'] ?? null;
        $includePaused = ! in_array($eventCode, ['PLC', 'PLACED'], true);

        $matches = IfoodIntegration::operational($includePaused)
            ->with('company')
            ->where('merchant_id', $merchantId)
            ->get();

        if ($matches->count() > 1) {
            // Mesma loja ligada a duas integrações: entregar a qualquer uma vazaria pedido entre
            // empresas. O índice único de merchant_id impede isso; aqui é só a última barreira.
            Log::channel('ifood')->critical('iFood webhook: loja ligada a mais de uma integração ativa, evento recusado', [
                'merchant_id' => $merchantId,
                'ifood_integration_ids' => $matches->modelKeys(),
            ]);

            return response()->json(['error' => 'Ambiguous merchant'], 409);
        }

        $integration = $matches->first(fn (IfoodIntegration $candidate) => $candidate->isOperational($includePaused));

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
