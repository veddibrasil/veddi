<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyWalletEntry;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class WalletService
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

        // Para cartão: usar o valor original do pedido (antes das taxas de cartão).
        // As taxas de cartão já foram cobradas do cliente e vão direto ao Asaas.
        $baseAmount      = (float) ($payment->original_amount ?? $payment->amount);
        $feeRate         = $company->plan?->feePercentage() ?? 0.0;
        $isCardPayment   = $payment->original_amount !== null;
        $pixFeeAbsorbed  = $company->pix_fee_absorbed_by_company ?? false;
        $cardFeeAbsorbed = $company->card_fee_absorbed_by_company ?? false;

        $pixFee  = (! $isCardPayment && $pixFeeAbsorbed)
            ? (float) config('payments.pix_payment_fee', 1.99)
            : 0.0;
        $cardFee = ($isCardPayment && $cardFeeAbsorbed && $payment->card_fee)
            ? (float) $payment->card_fee
            : 0.0;

        $feeAmount    = round(($baseAmount - $pixFee - $cardFee) * $feeRate, 2);
        $creditAmount = round(($baseAmount - $pixFee - $cardFee) * (1 - $feeRate), 2);

        CompanyWalletEntry::create([
            'company_id'  => $company->id,
            'order_id'    => $order->id,
            'type'        => 'credit',
            'amount'      => $creditAmount,
            'description' => "Pedido #{$order->order_number}",
            'reference'   => $payment->asaas_payment_id,
        ]);

        if ($feeAmount > 0) {
            CompanyWalletEntry::create([
                'company_id'  => $company->id,
                'order_id'    => $order->id,
                'type'        => 'fee',
                'amount'      => $feeAmount,
                'description' => "Taxa plataforma - Pedido #{$order->order_number}",
                'reference'   => $payment->asaas_payment_id,
            ]);
        }

        Log::channel('payments')->info('Carteira da empresa creditada', [
            'company_id'      => $company->id,
            'order_id'        => $order->id,
            'base_amount'     => $baseAmount,
            'fee_amount'      => $feeAmount,
            'credit_amount'   => $creditAmount,
            'is_card_payment' => $payment->original_amount !== null,
        ]);
    }

    /**
     * Debit the company wallet to reverse a refunded order payment.
     * Creates a negative entry that offsets the original credit.
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

        $orderAmount  = (float) $payment->amount;
        $feeRate      = $company->plan?->feePercentage() ?? 0.0;
        $feeAmount    = round($orderAmount * $feeRate, 2);
        $creditAmount = round($orderAmount - $feeAmount, 2);

        CompanyWalletEntry::create([
            'company_id'  => $company->id,
            'order_id'    => $order->id,
            'type'        => 'refund',
            'amount'      => -$creditAmount,
            'description' => "Reembolso - Pedido #{$order->order_number}",
            'reference'   => $payment->asaas_payment_id,
        ]);

        Log::channel('payments')->info('Carteira da empresa debitada por reembolso', [
            'company_id'   => $company->id,
            'order_id'     => $order->id,
            'debit_amount' => $creditAmount,
        ]);
    }
}
