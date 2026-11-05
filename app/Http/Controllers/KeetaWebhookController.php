<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessKeetaEventJob;
use App\Models\KeetaIntegration;
use App\Models\KeetaWebhookEvent;
use App\Services\Keeta\KeetaSigner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de eventos de pedido da Keeta (ordersWebhookURL do onboarding, o mesmo pra todas as
 * lojas). Sem 2xx a Keeta repete em 1, 2 e 3 min e desiste, então aqui só valida, persiste
 * (eventId único: reentrega e o mesmo evento vindo pelo polling viram um só) e enfileira — o
 * processamento é do ProcessKeetaEventJob. Responde 204 vazio.
 */
class KeetaWebhookController extends Controller
{
    /** Pedido novo só entra em loja ativa; os demais eventos valem com a integração suspensa. */
    public const NEW_ORDER_TYPES = ['CREATED', 'PLACED'];

    public function __invoke(Request $request, KeetaSigner $signer): Response
    {
        $raw = $request->getContent();

        if (! self::validSignature($request, $signer, $raw)) {
            Log::channel('keeta')->warning('Keeta webhook: assinatura inválida', ['ip' => $request->ip()]);

            return response()->noContent(401);
        }

        $appId = (string) config('keeta.app_id');
        if ($appId !== '' && (string) $request->header('X-App-Id') !== $appId) {
            Log::channel('keeta')->warning('Keeta webhook: X-App-Id diferente do configurado');

            return response()->noContent(403);
        }

        $payload = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        $eventId = is_array($payload) ? (string) ($payload['eventId'] ?? '') : '';
        $type = is_array($payload) ? strtoupper((string) ($payload['eventType'] ?? '')) : '';
        $orderId = is_array($payload) ? (string) ($payload['orderId'] ?? '') : '';

        if ($eventId === '' || strlen($eventId) > 191 || $type === '' || strlen($type) > 40 || $orderId === '' || strlen($orderId) > 40) {
            Log::channel('keeta')->warning('Keeta webhook: payload sem eventId, eventType ou orderId', ['ip' => $request->ip()]);

            return response()->noContent(400);
        }

        $merchantRef = (string) $request->header('X-App-MerchantId', '');
        $includePaused = ! in_array($type, self::NEW_ORDER_TYPES, true);

        $matches = $merchantRef === '' ? collect() : KeetaIntegration::operational($includePaused)
            ->with('company')
            ->where('merchant_ref', $merchantRef)
            ->get();

        if ($matches->count() > 1) {
            // O índice único de merchant_ref impede isso; aqui é só a última barreira contra
            // entregar pedido de uma empresa na outra.
            Log::channel('keeta')->critical('Keeta webhook: merchantId ligado a mais de uma integração ativa, evento recusado', [
                'event_type' => $type,
                'keeta_integration_ids' => $matches->modelKeys(),
            ]);

            return response()->noContent(409);
        }

        $integration = $matches->first(fn (KeetaIntegration $candidate) => $candidate->isOperational($includePaused));

        if (! $integration) {
            // 2xx pra loja desconhecida/desconectada: erro faria a Keeta reenviar um evento que
            // ninguém vai processar.
            Log::channel('keeta')->warning('Keeta webhook: integração não encontrada ou inativa', [
                'event_type' => $type,
                'keeta_order_id' => $orderId,
            ]);

            return response()->noContent();
        }

        $integration->forceFill([
            'last_webhook_received_at' => now(),
            'webhook_status' => 'healthy',
        ])->saveQuietly();

        self::store($payload, $eventId, $type, $orderId, $integration, KeetaWebhookEvent::SOURCE_WEBHOOK);

        return response()->noContent();
    }

    /** Persiste e enfileira; null quando o eventId já foi visto (reentrega ou polling). */
    public static function store(array $payload, string $eventId, string $type, string $orderId, ?KeetaIntegration $integration, string $source): ?KeetaWebhookEvent
    {
        try {
            $event = KeetaWebhookEvent::create([
                'event_id' => $eventId,
                'keeta_integration_id' => $integration?->id,
                'event_type' => $type,
                'external_order_id' => $orderId !== '' ? $orderId : null,
                'source' => $source,
                'payload' => $payload,
                'status' => KeetaWebhookEvent::STATUS_PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            Log::channel('keeta')->info('Keeta: evento duplicado, ignorado', ['event_type' => $type, 'source' => $source]);

            return null;
        }

        ProcessKeetaEventJob::dispatch($event->id);

        return $event;
    }

    /** A URL assinada é a que a Keeta chamou; atrás de proxy o esquema pode chegar trocado. */
    public static function validSignature(Request $request, KeetaSigner $signer, string $raw): bool
    {
        $signature = $request->header('X-App-Signature');
        $query = $request->query();
        $urls = array_unique([$request->url(), preg_replace('#^http://#', 'https://', $request->url())]);

        foreach ($urls as $url) {
            if ($signer->validWebhookSign($raw, $signature, $url, is_array($query) ? $query : [])) {
                return true;
            }
        }

        return false;
    }
}
