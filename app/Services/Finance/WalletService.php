<?php

namespace App\Services\Finance;

use App\Contracts\WalletServiceInterface;
use App\Models\Company;
use App\Models\CompanyWalletEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\PaymentSplitCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletService implements WalletServiceInterface
{
    public function __construct(
        private readonly PaymentSplitCalculator $splits = new PaymentSplitCalculator,
    ) {}

    /**
     * Credit the company wallet for a confirmed order payment.
     *
     * Lançamentos: crédito bruto do pedido + taxa do gateway absorvida pela empresa
     * + taxa da plataforma. Os valores vêm do split gravado no Payment na criação da
     * cobrança (PaymentSplitCalculator), o mesmo enviado ao gateway — então o saldo
     * líquido do histórico bate com o que a empresa recebe.
     */
    public function creditForOrder(Order $order, Payment $payment): void
    {
        $company = $order->company ?? Company::find($order->company_id);

        if (! $company) {
            Log::channel('payments')->warning('Empresa do pedido não encontrada para crédito na carteira', [
                'order_id' => $order->id,
            ]);

            return;
        }

        $reference = $payment->external_id;
        if (! $reference) {
            Log::channel('payments')->warning('Pagamento sem external_id nem vindi_transaction_token — evitando crédito indeterminado na carteira', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
            ]);

            return;
        }

        $split = $this->splits->forPayment($order, $payment);
        $isCardPayment = $payment->original_amount !== null;
        $gatewayFeeType = $isCardPayment ? 'card_fee' : 'pix_fee';

        $alreadyCredited = false;

        // Idempotência dentro da transaction com lock para evitar race condition em webhooks concorrentes.
        DB::transaction(function () use ($company, $order, $reference, $split, $gatewayFeeType, $isCardPayment, &$alreadyCredited) {
            if (CompanyWalletEntry::where('order_id', $order->id)->where('reference', $reference)->where('type', 'credit')->lockForUpdate()->exists()) {
                $alreadyCredited = true;

                return;
            }

            // Crédito bruto: valor cheio do pedido. Taxas entram como lançamentos separados.
            CompanyWalletEntry::create([
                'company_id' => $company->id,
                'order_id' => $order->id,
                'type' => 'credit',
                'amount' => $split->grossAmount,
                'description' => "Pedido #{$order->order_number}",
                'reference' => $reference,
            ]);

            if ($split->gatewayFee > 0) {
                CompanyWalletEntry::create([
                    'company_id' => $company->id,
                    'order_id' => $order->id,
                    'type' => $gatewayFeeType,
                    'amount' => $split->gatewayFee,
                    'description' => ($isCardPayment ? 'Taxa cartão' : 'Taxa PIX')." - Pedido #{$order->order_number}",
                    'reference' => $reference,
                ]);
            }

            if ($split->platformFee > 0) {
                CompanyWalletEntry::create([
                    'company_id' => $company->id,
                    'order_id' => $order->id,
                    'type' => 'fee',
                    'amount' => $split->platformFee,
                    'description' => "Taxa plataforma - Pedido #{$order->order_number}",
                    'reference' => $reference,
                ]);
            }
        });

        if ($alreadyCredited) {
            Log::channel('payments')->info('Crédito na carteira já existe (idempotente)', [
                'company_id' => $company->id,
                'order_id' => $order->id,
                'reference' => $reference,
            ]);

            return;
        }

        Log::channel('payments')->info('Carteira da empresa creditada', [
            'company_id' => $company->id,
            'order_id' => $order->id,
            'gross_amount' => $split->grossAmount,
            'gateway_fee' => $split->gatewayFee,
            'platform_fee' => $split->platformFee,
            'company_net' => $split->companyNet,
            'is_card_payment' => $isCardPayment,
        ]);

        Log::channel('audit')->info('wallet.credit', [
            'company_id' => $company->id,
            'order_id' => $order->id,
            'payment_external_id' => $reference,
            'gross_amount' => $split->grossAmount,
            'gateway_fee' => $split->gatewayFee,
            'platform_fee' => $split->platformFee,
        ]);
    }

    /**
     * Debit the company wallet to reverse a refunded order payment.
     *
     * Reverte exatamente os lançamentos gravados no crédito (mesmo pedido + referência),
     * em vez de recalcular taxas: se o plano ou as taxas mudaram entre o pagamento e o
     * estorno, o recálculo deixaria saldo sobrando ou faltando.
     */
    public function debitForRefund(Order $order, Payment $payment): void
    {
        $company = $order->company ?? Company::find($order->company_id);

        if (! $company) {
            Log::channel('payments')->warning('Empresa do pedido não encontrada para débito de reembolso na carteira', [
                'order_id' => $order->id,
            ]);

            return;
        }

        $reference = $payment->external_id;
        if (! $reference) {
            Log::channel('payments')->warning('Pagamento sem external_id nem vindi_transaction_token — evitando débito/estorno indeterminado na carteira', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
            ]);

            return;
        }

        $alreadyRefunded = false;
        $reversed = ['credit' => 0.0, 'fees' => 0.0];

        // Balance semantics em CompanyWalletEntry::balanceFor():
        // - type=credit soma amount
        // - qualquer outro type subtrai amount
        //
        // Para "desfazer" o crédito líquido (+bruto - taxas), criamos lançamentos refund
        // com sinais que resultam em: -bruto + taxas.
        DB::transaction(function () use ($company, $order, $reference, &$alreadyRefunded, &$reversed) {
            $entries = CompanyWalletEntry::where('order_id', $order->id)
                ->where('reference', $reference)
                ->lockForUpdate()
                ->get();

            if ($entries->contains('type', 'refund')) {
                $alreadyRefunded = true;

                return;
            }

            $credit = $entries->firstWhere('type', 'credit');

            if (! $credit) {
                // Nada foi creditado para este pagamento — não há o que estornar na carteira.
                return;
            }

            $reversed['credit'] = (float) $credit->amount;

            CompanyWalletEntry::create([
                'company_id' => $company->id,
                'order_id' => $order->id,
                'type' => 'refund',
                'amount' => (float) $credit->amount,
                'description' => "Estorno pedido (remover crédito) - Pedido #{$order->order_number}",
                'reference' => $reference,
            ]);

            $labels = ['fee' => 'taxa plataforma', 'pix_fee' => 'taxa PIX', 'card_fee' => 'taxa cartão'];

            foreach ($entries->whereIn('type', array_keys($labels)) as $fee) {
                $reversed['fees'] += (float) $fee->amount;

                CompanyWalletEntry::create([
                    'company_id' => $company->id,
                    'order_id' => $order->id,
                    'type' => 'refund',
                    'amount' => -(float) $fee->amount,
                    'description' => "Estorno {$labels[$fee->type]} (reverter desconto) - Pedido #{$order->order_number}",
                    'reference' => $reference,
                ]);
            }
        });

        if ($alreadyRefunded) {
            Log::channel('payments')->info('Débito de reembolso já existe (idempotente)', [
                'company_id' => $company->id,
                'order_id' => $order->id,
                'reference' => $reference,
            ]);

            return;
        }

        $netDebit = round($reversed['credit'] - $reversed['fees'], 2);

        Log::channel('payments')->info('Carteira da empresa debitada por reembolso', [
            'company_id' => $company->id,
            'order_id' => $order->id,
            'credit_reversed' => $reversed['credit'],
            'fees_reversed' => $reversed['fees'],
            'net_debit' => $netDebit,
        ]);

        Log::channel('audit')->info('wallet.refund', [
            'company_id' => $company->id,
            'order_id' => $order->id,
            'payment_external_id' => $reference,
            'credit_reversed' => $reversed['credit'],
            'fees_reversed' => $reversed['fees'],
            'net_debit' => $netDebit,
        ]);
    }
}
