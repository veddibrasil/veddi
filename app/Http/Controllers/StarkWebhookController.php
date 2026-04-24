<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessStarkWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StarkWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->header('Authorization', '');
        $expected = config('services.stark.webhook_token');

        // Remove prefixo "Bearer " se presente
        $token = str_starts_with($token, 'Bearer ') ? substr($token, 7) : $token;

        if (empty($expected) || ! hash_equals((string) $expected, (string) $token)) {
            Log::channel('webhook')->warning('Stark webhook: token inválido ou não configurado', [
                'ip' => $request->ip(),
                'token_missing' => empty($expected),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->json()->all();

        // O Stark Bank envia eventos como: {"event": {"log": {"type": "credited", "invoice": {...}}}}
        $event = $data['event']['log']['type'] ?? ($data['event']['type'] ?? null);

        Log::channel('webhook')->info('Stark webhook recebido', [
            'event' => $event,
            'payload' => $data,
        ]);

        if (! $event) {
            return response()->json(['error' => 'Missing event'], 422);
        }

        ProcessStarkWebhook::dispatch($event, $data);

        Log::channel('webhook')->info('Stark webhook enfileirado', [
            'event' => $event,
            'invoice_id' => $data['event']['log']['invoice']['id'] ?? null,
        ]);

        return response()->json(['status' => 'queued']);
    }
}
