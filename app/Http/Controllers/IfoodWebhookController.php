<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIfoodWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IfoodWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $secret = (string) config('services.ifood.client_secret');
        $expected = hash_hmac('sha256', $raw, $secret);
        $signature = (string) $request->header('X-IFood-Signature', '');

        if ($secret === '' || ! hash_equals($expected, $signature)) {
            Log::channel('webhook')->warning('iFood webhook: assinatura inválida', [
                'ip' => $request->ip(),
                'secret_missing' => $secret === '',
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $body = $request->json()->all();
        $events = array_is_list($body) ? $body : [$body];

        foreach ($events as $event) {
            $fullCode = $event['fullCode'] ?? $event['code'] ?? null;

            // Presença/keepalive: só confirma que o endpoint está de pé, sem trabalho assíncrono.
            if ($fullCode === 'KEEPALIVE' || ! isset($event['id'])) {
                continue;
            }

            ProcessIfoodWebhook::dispatch($event);
        }

        Log::channel('webhook')->info('iFood webhook recebido', ['count' => count($events)]);

        // iFood exige 202 dentro de 5s — job pesado fica todo na fila.
        return response()->json(['status' => 'received'], 202);
    }
}
