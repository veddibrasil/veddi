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

        // Yapay sends account token as transaction.seller_token (not root token_account)
        $sellerToken = $data['transaction']['seller_token']
            ?? $data['transaction']['company']['token']
            ?? $data['token_account']
            ?? '';

        $expected = (string) config('payments.vindi_token_account');

        // Sem token configurado, hash_equals('', '') aceitaria qualquer POST sem token —
        // falha fechada, igual ao webhook do Asaas.
        if ($expected === '' || ! hash_equals($expected, (string) $sellerToken)) {
            // Nunca logar o payload completo de uma requisição ainda não autenticada —
            // só metadados, senão qualquer POST não autenticado a este endpoint público
            // grava o corpo bruto (potencialmente forjado) no log/Nightwatch. Nem prefixo
            // do token esperado: é segredo da conta.
            Log::channel('webhook')->warning('Vindi webhook: token_account inválido ou não configurado', [
                'ip' => $request->ip(),
                'token_missing' => $expected === '',
                'received_prefix' => $sellerToken !== '' ? substr((string) $sellerToken, 0, 4).'…' : '(vazio)',
                'content_type' => $request->header('Content-Type'),
                'keys_recebidos' => array_keys($data),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Nunca logar o payload completo mesmo já autenticado: a Vindi/Yapay pode trazer
        // dado de cartão e do pagador no corpo. Só as chaves, pra depurar formato sem vazar valor.
        Log::channel('webhook')->debug('Vindi webhook recebido', ['keys_recebidos' => array_keys($data)]);

        // Yapay sends token as transaction.transaction_token (also mirrored at root token_transaction)
        $transactionToken = $data['transaction']['transaction_token']
            ?? $data['token_transaction']
            ?? null;
        $status = $data['transaction']['status_name'] ?? null;

        if (! $transactionToken || ! $status) {
            Log::channel('webhook')->warning('Vindi webhook: dados ausentes', ['keys_recebidos' => array_keys($data)]);

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
