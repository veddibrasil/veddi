<?php

namespace App\Http\Controllers;

use App\Models\KeetaIntegration;
use App\Models\KeetaWebhookEvent;
use App\Services\Keeta\KeetaSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de autorização do Dev Portal da Keeta: 1301 (nova autorização) e 1302 (revogação),
 * um por loja. Valida, persiste (idempotente por authId + opType + loja, como a doc pede) e
 * enfileira; o KeetaAuthorizationHandler decide o que fazer.
 */
class KeetaAuthorizationWebhookController extends Controller
{
    public const TYPE_GRANTED = 'AUTHORIZATION_GRANTED';

    public const TYPE_REVOKED = 'AUTHORIZATION_REVOKED';

    public function __invoke(Request $request, KeetaSigner $signer): JsonResponse
    {
        $raw = $request->getContent();

        if (! KeetaWebhookController::validSignature($request, $signer, $raw)) {
            Log::channel('keeta')->warning('Keeta autorização: assinatura inválida', ['ip' => $request->ip()]);

            return response()->json(['status' => 401, 'title' => 'invalid signature'], 401);
        }

        $payload = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        $authId = is_array($payload) ? (string) ($payload['authId'] ?? '') : '';
        $shopId = is_array($payload) ? (string) ($payload['shopId'] ?? '') : '';
        $opType = is_array($payload) ? (int) ($payload['opType'] ?? 0) : 0;

        if ($authId === '' || $shopId === '' || ! in_array($opType, [1, 2], true) || strlen($authId) > 64 || strlen($shopId) > 32) {
            return response()->json(['status' => 400, 'title' => 'Invalid data format'], 400);
        }

        $clientId = (string) config('keeta.client_id');
        if ($clientId !== '' && isset($payload['clientId']) && (string) $payload['clientId'] !== $clientId) {
            Log::channel('keeta')->warning('Keeta autorização: clientId diferente do configurado');

            return response()->json(['status' => 403, 'title' => 'invalid clientId'], 403);
        }

        $integration = KeetaIntegration::withoutGlobalScopes()->where('keeta_merchant_id', $shopId)->first();

        KeetaWebhookController::store(
            $payload,
            "auth|{$authId}|{$opType}|{$shopId}",
            $opType === 1 ? self::TYPE_GRANTED : self::TYPE_REVOKED,
            '',
            $integration,
            KeetaWebhookEvent::SOURCE_AUTHORIZATION,
        );

        return response()->json(['code' => 0, 'message' => 'success']);
    }
}
