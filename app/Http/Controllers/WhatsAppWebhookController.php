<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * Verificação do webhook (GET). A Meta manda hub.mode/hub.verify_token/hub.challenge e o
     * PHP converte os pontos em "_" na query; aceita as duas grafias por garantia.
     */
    public function verify(Request $request): Response
    {
        $query = $request->query->all();
        $mode = $query['hub_mode'] ?? $query['hub.mode'] ?? null;
        $token = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = $query['hub_challenge'] ?? $query['hub.challenge'] ?? null;
        $expected = (string) config('services.whatsapp.webhook_verify_token');

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token) && is_scalar($challenge)) {
            // O challenge volta puro (sem JSON nem HTML), como text/plain.
            return response((string) $challenge, 200, ['Content-Type' => 'text/plain']);
        }

        Log::channel('whatsapp')->warning('Webhook WhatsApp: verificação recusada', [
            'ip' => $request->ip(),
            'verify_token_configured' => $expected !== '',
        ]);

        return response('Forbidden', 403, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            Log::channel('whatsapp')->warning('Webhook WhatsApp: assinatura inválida ou ausente', [
                'ip' => $request->ip(),
                'app_secret_configured' => filled(config('services.meta.app_secret')),
                'has_signature_header' => $request->hasHeader('X-Hub-Signature-256'),
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->json()->all();

        // Só metadados: o payload traz telefone e texto de clientes.
        Log::channel('whatsapp')->info('Webhook WhatsApp recebido', [
            'object' => $payload['object'] ?? null,
            'entries' => is_array($payload['entry'] ?? null) ? count($payload['entry']) : 0,
        ]);

        if (($payload['object'] ?? null) === 'whatsapp_business_account') {
            ProcessWhatsAppWebhook::dispatch($payload);
        }

        // A Meta só precisa de 200 rápido; ela reenvia se demorar ou falhar.
        return response()->json(['status' => 'queued']);
    }

    /**
     * X-Hub-Signature-256 = "sha256=" + HMAC-SHA256 do corpo BRUTO com o app secret.
     * Sem app secret configurado, recusa tudo (fail-closed).
     */
    private function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('services.meta.app_secret');
        $header = (string) $request->header('X-Hub-Signature-256', '');

        if ($secret === '' || $header === '') {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $header);
    }
}
