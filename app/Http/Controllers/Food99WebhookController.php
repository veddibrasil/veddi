<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessFood99EventJob;
use App\Models\Food99Integration;
use App\Models\Food99Request;
use App\Models\Food99WebhookEvent;
use App\Services\Food99\Food99Signer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Callback único da 99Food (todos os "type" no mesmo endpoint). A 99 espera
 * {"errno":0,"errmsg":"ok"} em até 6 s; qualquer outra resposta faz ela reenviar. Por isso
 * aqui só valida, persiste (com dedupe_key, já que o payload não traz id de evento) e
 * enfileira — o processamento é do ProcessFood99EventJob.
 */
class Food99WebhookController extends Controller
{
    /** Pedido novo só entra em loja ativa; os demais eventos valem com a integração suspensa. */
    private const NEW_ORDER_TYPE = 'orderNew';

    public function __invoke(Request $request, Food99Signer $signer): JsonResponse
    {
        $raw = $request->getContent();

        if (! $signer->validWebhookSign($raw, $request->header('didi-header-sign'))) {
            Log::channel('food99')->warning('99Food webhook: assinatura inválida', ['ip' => $request->ip()]);

            return $this->reject('invalid sign', 401);
        }

        $payload = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        $type = is_array($payload) ? ($payload['type'] ?? null) : null;

        if (! is_string($type) || $type === '' || strlen($type) > 40) {
            Log::channel('food99')->warning('99Food webhook: payload sem type', ['ip' => $request->ip()]);

            return $this->reject('invalid payload', 400);
        }

        if ((string) ($payload['app_id'] ?? '') !== (string) config('food99.app_id')) {
            Log::channel('food99')->warning('99Food webhook: app_id diferente do configurado', ['type' => $type]);

            return $this->reject('invalid app_id', 403);
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        // Vínculo/desvínculo de lojas: vem sem app_shop_id no envelope, com a lista no data.
        if ($type === 'shopBindStatus') {
            $this->store($payload, $type, $data, $raw, null);

            return $this->ok();
        }

        $appShopId = (string) ($payload['app_shop_id'] ?? '');
        $includePaused = $type !== self::NEW_ORDER_TYPE;

        $matches = $appShopId === '' ? collect() : Food99Integration::operational($includePaused)
            ->with('company')
            ->where('app_shop_id', $appShopId)
            ->get();

        if ($matches->count() > 1) {
            // O índice único de app_shop_id impede isso; aqui é só a última barreira contra
            // entregar pedido de uma empresa na outra.
            Log::channel('food99')->critical('99Food webhook: loja ligada a mais de uma integração ativa, evento recusado', [
                'type' => $type,
                'food99_integration_ids' => $matches->modelKeys(),
            ]);

            return $this->reject('ambiguous shop', 409);
        }

        $integration = $matches->first(fn (Food99Integration $candidate) => $candidate->isOperational($includePaused));

        if (! $integration) {
            // [VALIDAR 99Food] errno 0 pra loja desconhecida/desconectada: errno 1 faria a 99
            // reenviar sem fim um evento que ninguém vai processar.
            Log::channel('food99')->warning('99Food webhook: integração não encontrada ou inativa', [
                'type' => $type,
                'order_id' => isset($data['order_id']) ? (string) $data['order_id'] : null,
            ]);

            return $this->ok();
        }

        $event = $this->store($payload, $type, $data, $raw, $integration);

        $integration->forceFill([
            'last_webhook_received_at' => now(),
            'webhook_status' => 'healthy',
        ])->saveQuietly();

        if ($event === null && in_array($type, ['orderCancelApply', 'orderRefundApply'], true) && isset($data['apply_id'])) {
            // A 99 reenvia a solicitação a cada 2 min (cancelamento) ou de hora em hora (reembolso).
            Food99Request::withoutGlobalScopes()
                ->where('food99_integration_id', $integration->id)
                ->where('apply_id', (string) $data['apply_id'])
                ->update(['last_seen_at' => now()]);
        }

        return $this->ok();
    }

    /** Persiste e enfileira; null quando é reentrega (dedupe_key já vista). */
    private function store(array $payload, string $type, array $data, string $raw, ?Food99Integration $integration): ?Food99WebhookEvent
    {
        $dedupeKey = self::dedupeKey($type, $payload, $data, $raw);

        try {
            $event = Food99WebhookEvent::create([
                'dedupe_key' => $dedupeKey,
                'food99_integration_id' => $integration?->id,
                'event_type' => $type,
                'external_order_id' => isset($data['order_id']) ? (string) $data['order_id'] : null,
                'apply_id' => isset($data['apply_id']) ? (string) $data['apply_id'] : null,
                'payload' => $payload,
                'request_id' => isset($payload['requestId']) ? (string) $payload['requestId'] : null,
                'status' => Food99WebhookEvent::STATUS_PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            Log::channel('food99')->info('99Food webhook: evento duplicado, ignorado', ['type' => $type, 'dedupe_key' => $dedupeKey]);

            return null;
        }

        ProcessFood99EventJob::dispatch($event->id);

        return $event;
    }

    /**
     * Sem id de evento no payload: a chave sai do tipo + o que identifica a ocorrência. Pedido
     * novo e solicitações do cliente são únicos por pedido/apply_id (reentrega cai aqui);
     * mudanças de estado levam o timestamp do envelope.
     */
    public static function dedupeKey(string $type, array $payload, array $data, string $raw): string
    {
        $orderId = isset($data['order_id']) ? (string) $data['order_id'] : null;
        $timestamp = (string) ($payload['timestamp'] ?? '');
        $appShopId = (string) ($payload['app_shop_id'] ?? '');

        $key = match (true) {
            $type === 'orderNew' && $orderId !== null => "orderNew|{$orderId}",
            in_array($type, ['orderCancelApply', 'orderRefundApply'], true) && isset($data['apply_id']) => "{$type}|{$data['apply_id']}",
            $type === 'deliveryStatus' && $orderId !== null => "deliveryStatus|{$orderId}|".($data['delivery_status'] ?? '')."|{$timestamp}",
            $type === 'uploadMenuTaskStatus' && isset($data['taskID']) => "uploadMenuTaskStatus|{$data['taskID']}|".($data['status'] ?? ''),
            $type === 'shopBindStatus' => 'shopBindStatus|'.($data['bindStatus'] ?? '').'|'.md5(json_encode($data['appShopIDList'] ?? []))."|{$timestamp}",
            $timestamp !== '' && ($orderId !== null || $appShopId !== '') => "{$type}|".($orderId ?? $appShopId)."|{$timestamp}",
            default => null,
        };

        return $key !== null && strlen($key) <= 191 ? $key : 'sha256|'.hash('sha256', $raw);
    }

    private function ok(): JsonResponse
    {
        return response()->json(['errno' => 0, 'errmsg' => 'ok']);
    }

    private function reject(string $message, int $status): JsonResponse
    {
        return response()->json(['errno' => 1, 'errmsg' => $message], $status);
    }
}
