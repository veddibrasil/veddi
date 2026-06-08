<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVindiWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VindiWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Payload chega como form-data POST
        $data = $request->all();

        $tokenAccount = $data['token_account'] ?? '';
        if (! hash_equals((string) config('payments.vindi_token_account'), $tokenAccount)) {
            Log::channel('webhook')->warning('Vindi webhook: token_account inválido', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $transactionToken = $data['transaction']['token'] ?? null;
        $status = $data['transaction']['status_name'] ?? null;

        if (! $transactionToken || ! $status) {
            Log::channel('webhook')->warning('Vindi webhook: dados ausentes', ['payload' => $data]);

            return response()->json(['error' => 'Missing data'], 422);
        }

        Log::channel('webhook')->info('Vindi webhook recebido', [
            'transaction_token' => $transactionToken,
            'status' => $status,
        ]);

        ProcessVindiWebhook::dispatch($transactionToken, $status, $data);

        return response()->json(['status' => 'queued']);
    }
}
