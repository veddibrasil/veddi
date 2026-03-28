<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAsaasWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AsaasWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token    = $request->header('asaas-access-token', '');
        $expected = config('services.asaas.webhook_token');

        if ($expected && ! hash_equals((string) $expected, (string) $token)) {
            Log::channel('webhook')->warning('Asaas webhook: token inválido', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data  = $request->json()->all();
        $event = $data['event'] ?? null;
        Log::channel('webhook')->info('data Asaas webhook recebido', ['data' => $data]);

        Log::channel('webhook')->info('Asaas webhook recebido', ['event' => $event]);

        if (! $event) {
            return response()->json(['error' => 'Missing event'], 422);
        }

        ProcessAsaasWebhook::dispatch($event, $data);

        return response()->json(['status' => 'queued']);
    }
}
