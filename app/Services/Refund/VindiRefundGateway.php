<?php

namespace App\Services\Refund;

use App\Contracts\PaymentRefundGatewayInterface;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VindiRefundGateway implements PaymentRefundGatewayInterface
{
    private string $baseUrl;

    public function __construct()
    {
        $sandbox = config('app.env') !== 'production';
        $this->baseUrl = $sandbox
            ? 'https://api.intermediador.sandbox.yapay.com.br/api/v3'
            : 'https://api.intermediador.yapay.com.br/api/v3';
    }

    public function requestRefund(Payment $payment, float $amount, ?string $reason = null): array
    {
        $token = $payment->vindi_transaction_token;

        $response = Http::asForm()->post("{$this->baseUrl}/transactions/cancel", [
            'token_account' => config('payments.vindi_token_account'),
            'reseller_token' => config('payments.vindi_reseller_token'),
            'transaction_token' => $token,
            'amount' => number_format($amount, 2, '.', ''),
        ]);

        $data = $response->json();
        $statusName = $data['data_response']['transaction']['status_name'] ?? null;

        if ($response->failed() && ! $statusName) {
            Log::channel('payments')->error('Vindi estorno falhou', [
                'vindi_transaction_token' => $token,
                'amount' => $amount,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['external_refund_id' => null, 'status' => 'failed', 'raw' => $data ?? []];
        }

        // Vindi confirma sincronamente para PIX e alguns cartões
        $succeeded = in_array($statusName, ['Cancelada', 'Estornada']);

        Log::channel('payments')->info('Vindi estorno solicitado', [
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
