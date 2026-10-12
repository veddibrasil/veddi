<?php

namespace App\Services\Finance;

use App\Contracts\TransactionServiceInterface;
use App\Models\Company;
use App\Models\CompanyTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\PaymentSplitCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionService implements TransactionServiceInterface
{
    public function __construct(
        private readonly PaymentSplitCalculator $splits = new PaymentSplitCalculator,
    ) {}

    /**
     * Cria uma CompanyTransaction quando um pagamento é confirmado.
     * Chamado pelos webhooks/orchestrator logo após WalletService::creditForOrder().
     *
     * net_value vem do mesmo split da carteira (PaymentSplitCalculator), e a criação é
     * idempotente por payment_id — um chamador a mais não duplica o saldo.
     */
    public function createForPayment(Order $order, Payment $payment): CompanyTransaction
    {
        $company = Company::find($order->company_id);
        $type = $this->resolvePaymentType($order);
        $paymentDate = now()->toDateString();
        $releaseDate = $this->resolveReleaseDate($type, $paymentDate, $payment->anticipation_days);

        $value = (float) $payment->amount;
        $netValue = $this->splits->forPayment($order, $payment)->companyNet;

        return DB::transaction(function () use ($order, $payment, $company, $type, $value, $netValue, $paymentDate, $releaseDate) {
            $existing = CompanyTransaction::withoutGlobalScopes()
                ->where('payment_id', $payment->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                Log::channel('payments')->info('CompanyTransaction já existe para o pagamento (idempotente)', [
                    'transaction_id' => $existing->id,
                    'payment_id' => $payment->id,
                ]);

                return $existing;
            }

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

            app(BalanceService::class)->broadcastUpdate($company->id);

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
