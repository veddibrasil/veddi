<?php

namespace App\Services\Finance;

use App\Contracts\TransactionServiceInterface;
use App\Models\Company;
use App\Models\CompanyTransaction;
use App\Models\Order;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionService implements TransactionServiceInterface
{
    /**
     * Cria uma CompanyTransaction quando um pagamento é confirmado.
     * Chamado pelo ProcessAsaasWebhook::handleOrderPayment() após WalletService::creditForOrder().
     */
    public function createForPayment(Order $order, Payment $payment): CompanyTransaction
    {
        $company = Company::find($order->company_id);
        $type = $this->resolvePaymentType($order);
        $paymentDate = now()->toDateString();
        $releaseDate = $this->resolveReleaseDate($type, $paymentDate, $payment->anticipation_days);

        $feeRate = $company->plan?->feePercentage() ?? 0.0;

        // Cartão: value = amount cobrado do cliente (com taxa de cartão embutida);
        //         net_value = original_amount deduzido da card_fee se empresa absorve + taxa plataforma.
        // PIX: value = amount; net_value = amount − pix_fee (se absorvida) − taxa plataforma.
        // Estes net_values são a fonte de verdade para BalanceService::calculateBalance().
        if ($type === 'cartao' && $payment->original_amount !== null) {
            $value = (float) $payment->amount;
            $cardFeeAbsorbed = $company->card_fee_absorbed_by_company ?? false;
            $effectiveAmount = ($cardFeeAbsorbed && $payment->card_fee)
                ? (float) $payment->original_amount - (float) $payment->card_fee
                : (float) $payment->original_amount;
            $netValue = round($effectiveAmount * (1 - $feeRate), 2);
        } else {
            $value = (float) $payment->amount;
            $pixFee = ($type === 'pix' && ($company->pix_fee_absorbed_by_company ?? false))
                ? (float) $payment->pix_fee
                : 0.0;
            $netValue = round(($value - $pixFee) * (1 - $feeRate), 2);
        }

        return DB::transaction(function () use ($order, $payment, $company, $type, $value, $netValue, $paymentDate, $releaseDate) {
            $transaction = CompanyTransaction::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'type' => $type,
                'status' => 'confirmed',
                'value' => $value,
                'net_value' => $netValue,
                'payment_date' => $paymentDate,
                'release_date' => $releaseDate,
                'description' => "Pedido #{$order->order_number}",
                'metadata' => [
                    'vindi_transaction_token' => $payment->vindi_transaction_token,
                    'asaas_payment_id' => $payment->asaas_payment_id,
                    'payment_gateway' => $payment->payment_gateway,
                    'payment_method' => $order->payment_method,
                    'installments' => $payment->installments,
                    'anticipation_days' => $payment->anticipation_days,
                    'card_fee' => $payment->card_fee,
                    'card_fee_rate' => $payment->card_fee_rate,
                    'original_amount' => $payment->original_amount,
                ],
            ]);

            Log::channel('payments')->info('CompanyTransaction criada', [
                'transaction_id' => $transaction->id,
                'company_id' => $company->id,
                'order_id' => $order->id,
                'type' => $type,
                'value' => $value,
                'net_value' => $netValue,
                'release_date' => $releaseDate,
            ]);

            return $transaction;
        });
    }

    /**
     * Determina o tipo de pagamento: 'pix' | 'cartao' | 'boleto'
     * com base no payment_method do pedido.
     */
    private function resolvePaymentType(Order $order): string
    {
        $method = strtolower($order->payment_method ?? '');

        return match (true) {
            str_contains($method, 'pix') => 'pix',
            str_contains($method, 'credit'),
            str_contains($method, 'cartao'),
            str_contains($method, 'card') => 'cartao',
            str_contains($method, 'boleto'),
            str_contains($method, 'bank_slip'),
            str_contains($method, 'bankslip') => 'boleto',
            default => 'pix',
        };
    }

    /**
     * Calcula a data de liberação com base no tipo de pagamento.
     * Para cartão, usa o anticipation_days salvo no payment (prazo real de antecipação).
     */
    private function resolveReleaseDate(string $type, string $paymentDate, ?int $anticipationDays = null): string
    {
        $days = match ($type) {
            'cartao' => $anticipationDays ?? (int) config('payments.release_days.cartao', 15),
            'pix' => (int) config('payments.release_days.pix', 1),
            default => (int) config('payments.release_days.boleto', 2),
        };

        return Carbon::parse($paymentDate)->addDays($days)->toDateString();
    }
}
