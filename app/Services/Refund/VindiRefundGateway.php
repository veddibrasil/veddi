<?php

namespace App\Services\Refund;

use App\Contracts\PaymentRefundGatewayInterface;
use App\Models\Payment;
use App\Services\Payment\VindiAuthService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VindiRefundGateway implements PaymentRefundGatewayInterface
{
    private string $baseUrl;

    public function __construct(private VindiAuthService $authService)
    {
        $sandbox = config('app.env') !== 'production';
        $this->baseUrl = $sandbox
            ? 'https://api.intermediador.sandbox.yapay.com.br/api/v3'
            : 'https://api.intermediador.yapay.com.br/api/v3';
    }

    public function requestRefund(Payment $payment, float $amount, ?string $reason = null): array
    {
        $transactionId = $payment->vindi_transaction_id;
        $token = $payment->vindi_transaction_token;

        if (! $transactionId) {
            Log::channel('payments')->error('Vindi estorno falhou: vindi_transaction_id ausente', [
                'payment_id' => $payment->id,
                'vindi_transaction_token' => $token,
            ]);

            return ['external_refund_id' => null, 'status' => 'failed', 'raw' => []];
        }

        $accessToken = $this->authService->getAccessToken();

        Log::channel('payments')->info('Solicitando estorno Vindi', [
            'vindi_transaction_id' => $transactionId,
            'vindi_transaction_token' => $token,
            'amount' => $amount,
        ]);

        $response = Http::patch("{$this->baseUrl}/transactions/cancel", [
            'access_token' => $accessToken,
            'transaction_id' => $transactionId,
            'refund_amount' => number_format($amount, 2, '.', ''),
        ]);

        Log::channel('payments')->info('Resposta do estorno Vindi', [
            'vindi_transaction_id' => $transactionId,
            'vindi_transaction_token' => $token,
            'amount' => $amount,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        $data = $response->json();
        $statusName = $data['data_response']['transaction']['status_name'] ?? null;

        if ($response->failed() && ! $statusName) {
            Log::channel('payments')->error('Vindi estorno falhou', [
                'vindi_transaction_id' => $transactionId,
                'vindi_transaction_token' => $token,
                'amount' => $amount,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['external_refund_id' => null, 'status' => 'failed', 'raw' => $data ?? []];
        }

        $succeeded = in_array($statusName, ['Cancelada', 'Estornada']);

        Log::channel('payments')->info('Vindi estorno solicitado', [
            'vindi_transaction_id' => $transactionId,
            'vindi_transaction_token' => $token,
            'amount' => $amount,
            'vindi_status' => $statusName,
            'immediate' => $succeeded,
        ]);

        return [
            'external_refund_id' => $token,
            'status' => $succeeded ? 'succeeded' : 'in_progress',
            'external_status' => $statusName,
            'raw' => $data,
        ];
    }
}
