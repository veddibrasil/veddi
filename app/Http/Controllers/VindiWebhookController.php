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
        Log::channel('webhook')->debug('Vindi webhook recebido', ['payload' => $data]);

        // Yapay sends account token as transaction.seller_token (not root token_account)
        $sellerToken = $data['transaction']['seller_token']
            ?? $data['transaction']['company']['token']
            ?? $data['token_account']
            ?? '';

        if (! hash_equals((string) config('payments.vindi_token_account'), $sellerToken)) {
            Log::channel('webhook')->warning('Vindi webhook: token_account inválido', [
                'ip' => $request->ip(),
                'received_prefix' => $sellerToken !== '' ? substr($sellerToken, 0, 8).'…' : '(vazio)',
                'expected_prefix' => substr((string) config('payments.vindi_token_account'), 0, 8).'…',
                'content_type' => $request->header('Content-Type'),
                'keys_recebidos' => array_keys($data),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Yapay sends token as transaction.transaction_token (also mirrored at root token_transaction)
        $transactionToken = $data['transaction']['transaction_token']
            ?? $data['token_transaction']
            ?? null;
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
