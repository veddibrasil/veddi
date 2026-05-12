<?php

namespace App\Services\Finance;

use App\Contracts\WalletServiceInterface;
use App\Models\Company;
use App\Models\CompanyWalletEntry;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletService implements WalletServiceInterface
{
    /**
     * Credit the company wallet for a confirmed order payment.
     * Creates a net-credit entry and a separate platform-fee entry.
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
            Log::channel('payments')->warning('Pagamento sem external_id — evitando crédito duplicado/indeterminado na carteira', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
            ]);

            return;
        }

        // Idempotência: webhooks/jobs podem repetir. Se já creditou este pedido para este pagamento, não duplica.
        if (CompanyWalletEntry::where('order_id', $order->id)->where('reference', $reference)->where('type', 'credit')->exists()) {
            Log::channel('payments')->info('Crédito na carteira já existe (idempotente)', [
                'company_id' => $company->id,
                'order_id' => $order->id,
                'reference' => $reference,
            ]);

            return;
        }

        // Para cartão: usar o valor original do pedido (antes das taxas de cartão).
        // As taxas de cartão já foram cobradas do cliente e vão direto ao Asaas.
        $baseAmount = (float) ($payment->original_amount ?? $payment->amount);
        $feeRate = $company->plan?->feePercentage() ?? 0.0;
        $isCardPayment = $payment->original_amount !== null;
        $pixFeeAbsorbed = $company->pix_fee_absorbed_by_company ?? false;
        $cardFeeAbsorbed = $company->card_fee_absorbed_by_company ?? false;

        $pixFee = (! $isCardPayment && $pixFeeAbsorbed)
            ? (float) config('payments.pix_payment_fee', 0.50)
            : 0.0;
        $cardFee = ($isCardPayment && $cardFeeAbsorbed && $payment->card_fee)
            ? (float) $payment->card_fee
            : 0.0;

        $feeAmount = round(($baseAmount - $pixFee - $cardFee) * $feeRate, 2);

        DB::transaction(function () use ($company, $order, $reference, $payment, $baseAmount, $pixFee, $cardFee, $feeAmount) {
            // Crédito bruto: valor cheio do pedido (sem descontar taxas absorvidas).
            // As taxas absorvidas entram como lançamentos separados (subtraídas do saldo).
            CompanyWalletEntry::create([
                'company_id' => $company->id,
                'order_id' => $order->id,
                'type' => 'credit',
                'amount' => $baseAmount,
                'description' => "Pedido #{$order->order_number}",
                'reference' => $reference,
            ]);

            if ($pixFee > 0) {
                CompanyWalletEntry::create([
                    'company_id' => $company->id,
                    'order_id' => $order->id,
                    'type' => 'pix_fee',
                    'amount' => $pixFee,
                    'description' => "Taxa PIX absorvida - Pedido #{$order->order_number}",
                    'reference' => $reference,
                ]);
            }

            if ($cardFee > 0) {
                CompanyWalletEntry::create([
                    'company_id' => $company->id,
                    'order_id' => $order->id,
                    'type' => 'card_fee',
                    'amount' => $cardFee,
                    'description' => "Taxa cartão absorvida - Pedido #{$order->order_number}",
                    'reference' => $reference,
                ]);
            }

            if ($feeAmount > 0) {
                CompanyWalletEntry::create([
                    'company_id' => $company->id,
                    'order_id' => $order->id,
                    'type' => 'fee',
                    'amount' => $feeAmount,
                    'description' => "Taxa plataforma - Pedido #{$order->order_number}",
                    'reference' => $reference,
                ]);
            }
        });

        Log::channel('payments')->info('Carteira da empresa creditada', [
            'company_id' => $company->id,
            'order_id' => $order->id,
            'base_amount' => $baseAmount,
            'pix_fee' => $pixFee,
            'card_fee' => $cardFee,
            'fee_amount' => $feeAmount,
            'is_card_payment' => $payment->original_amount !== null,
        ]);

        Log::channel('audit')->info('wallet.credit', [
            'company_id' => $company->id,
            'order_id' => $order->id,
            'asaas_payment_id' => $payment->external_id,
            'base_amount' => $baseAmount,
            'pix_fee' => $pixFee,
            'card_fee' => $cardFee,
            'platform_fee' => $feeAmount,
        ]);
    }

    /**
     * Debit the company wallet to reverse a refunded order payment.
     * Mirrors exactly the entries created in creditForOrder to avoid phantom balances.
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
            Log::channel('payments')->warning('Pagamento sem external_id — evitando débito/estorno indeterminado na carteira', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
            ]);

            return;
        }

        // Idempotência: evita lançar estorno duas vezes para o mesmo pagamento.
        if (CompanyWalletEntry::where('order_id', $order->id)->where('reference', $reference)->where('type', 'refund')->exists()) {
            Log::channel('payments')->info('Débito de reembolso já existe (idempotente)', [
                'company_id' => $company->id,
                'order_id' => $order->id,
                'reference' => $reference,
            ]);

            return;
        }

        // Reproduce the same amounts used in creditForOrder
        $baseAmount = (float) ($payment->original_amount ?? $payment->amount);
        $feeRate = $company->plan?->feePercentage() ?? 0.0;
        $isCardPayment = $payment->original_amount !== null;
        $pixFeeAbsorbed = $company->pix_fee_absorbed_by_company ?? false;
        $cardFeeAbsorbed = $company->card_fee_absorbed_by_company ?? false;

        $pixFee = (! $isCardPayment && $pixFeeAbsorbed)
            ? (float) config('payments.pix_payment_fee', 0.50)
            : 0.0;
        $cardFee = ($isCardPayment && $cardFeeAbsorbed && $payment->card_fee)
            ? (float) $payment->card_fee
            : 0.0;

        $feeAmount = round(($baseAmount - $pixFee - $cardFee) * $feeRate, 2);

        // Balance semantics em CompanyWalletEntry::balanceFor():
        // - type=credit soma amount
        // - qualquer outro type subtrai amount
        //
        // Para "desfazer" o crédito líquido (+base - pixFee - cardFee - feeAmount),
        // criamos lançamentos refund com sinais que resultam em: -base + pixFee + cardFee + feeAmount.
        DB::transaction(function () use ($company, $order, $reference, $baseAmount, $pixFee, $cardFee, $feeAmount) {
            CompanyWalletEntry::create([
                'company_id' => $company->id,
                'order_id' => $order->id,
                'type' => 'refund',
                'amount' => $baseAmount,
                'description' => "Estorno pedido (remover crédito) - Pedido #{$order->order_number}",
                'reference' => $reference,
            ]);

            if ($pixFee > 0) {
                CompanyWalletEntry::create([
                    'company_id' => $company->id,
                    'order_id' => $order->id,
                    'type' => 'refund',
                    'amount' => -$pixFee,
                    'description' => "Estorno taxa PIX (reverter desconto) - Pedido #{$order->order_number}",
                    'reference' => $reference,
                ]);
            }

            if ($cardFee > 0) {
                CompanyWalletEntry::create([
                    'company_id' => $company->id,
                    'order_id' => $order->id,
                    'type' => 'refund',
                    'amount' => -$cardFee,
                    'description' => "Estorno taxa cartão (reverter desconto) - Pedido #{$order->order_number}",
                    'reference' => $reference,
                ]);
            }

            if ($feeAmount > 0) {
                CompanyWalletEntry::create([
                    'company_id' => $company->id,
                    'order_id' => $order->id,
                    'type' => 'refund',
                    'amount' => -$feeAmount,
                    'description' => "Estorno taxa plataforma (reverter desconto) - Pedido #{$order->order_number}",
                    'reference' => $reference,
                ]);
            }
        });

        $netDebit = $baseAmount - $pixFee - $cardFee - $feeAmount;

        Log::channel('payments')->info('Carteira da empresa debitada por reembolso', [
            'company_id' => $company->id,
            'order_id' => $order->id,
            'base_amount' => $baseAmount,
            'pix_fee_reversed' => $pixFee,
            'card_fee_reversed' => $cardFee,
            'platform_fee_reversed' => $feeAmount,
            'net_debit' => $netDebit,
        ]);

        Log::channel('audit')->info('wallet.refund', [
            'company_id' => $company->id,
            'order_id' => $order->id,
            'payment_external_id' => $payment->external_id,
            'base_amount' => $baseAmount,
            'pix_fee_reversed' => $pixFee,
            'card_fee_reversed' => $cardFee,
            'platform_fee_reversed' => $feeAmount,
            'net_debit' => $netDebit,
        ]);
    }
}
