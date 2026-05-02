<?php

namespace App\Services\Refund;

use App\Contracts\PaymentRefundGatewayInterface;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use StarkBank\Invoice;
use StarkBank\Project;
use StarkBank\Settings;

class StarkRefundGateway implements PaymentRefundGatewayInterface
{
    public function __construct()
    {
        $projectId = config('services.stark.project_id');
        $privateKey = config('services.stark.private_key');
        $environment = config('services.stark.environment', 'sandbox');

        if (! empty($projectId) && ! empty($privateKey)) {
            Settings::setUser(new Project([
                'environment' => $environment,
                'id' => $projectId,
                'privateKey' => $privateKey,
            ]));
        }
    }

    public function requestRefund(Payment $payment, float $amount, ?string $reason = null): array
    {
        $invoiceId = $payment->stark_payment_id;
        $refundCents = (int) round($amount * 100);

        $invoice = Invoice::get($invoiceId);
        $newAmount = max(0, $invoice->amount - $refundCents);

        $updated = Invoice::update($invoiceId, ['amount' => $newAmount]);

        if ($updated->amount !== $newAmount) {
            Log::channel('payments')->error('Stark Invoice update falhou', [
                'stark_payment_id' => $invoiceId,
                'amount' => $amount,
                'expected_amount' => $newAmount,
                'returned_amount' => $updated->amount,
            ]);

            return ['external_refund_id' => null, 'status' => 'failed', 'raw' => []];
        }

        Log::channel('payments')->info('Stark Invoice atualizado para estorno', [
            'stark_payment_id' => $invoiceId,
            'refund_amount' => $amount,
            'new_invoice_amount' => $newAmount,
        ]);

        return [
            'external_refund_id' => $invoiceId,
            'status' => 'in_progress',
            'raw' => [
                'invoice_id' => $updated->id,
                'new_amount' => $updated->amount,
                'transaction_ids' => $updated->transactionIds,
            ],
        ];
    }
}
