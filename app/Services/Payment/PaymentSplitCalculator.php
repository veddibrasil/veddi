<?php

namespace App\Services\Payment;

use App\DTOs\PaymentSplitDTO;
use App\Models\Company;
use App\Models\Order;
use App\Models\Payment;

/**
 * Fonte única da divisão do dinheiro de um pedido pago no gateway.
 *
 * O PaymentOrchestrator usa pix()/card() para montar o split da Vindi e grava
 * platform_fee / company_net_amount no Payment; carteira (WalletService) e
 * transações (TransactionService) leem o que foi gravado via forPayment(), então
 * o que a empresa vê é o que o gateway efetivamente repassou.
 *
 * Regra: a comissão do plano (feePercentageForOrder) incide sobre o que sobra
 * depois da taxa do gateway, sem a taxa de entrega.
 */
class PaymentSplitCalculator
{
    public function pix(Order $order, Company $company, float $chargeAmount): PaymentSplitDTO
    {
        $gatewayRate = (float) config('payments.vindi_pix_rate', 0.0085);
        $platformRate = (float) config('payments.vindi_pix_platform_rate', 0.0014);
        $planExtraRate = $company->feePercentageForOrder($order);

        $netAfterGateway = round($chargeAmount * (1.0 - $gatewayRate - $platformRate), 3);
        $platformFeeBase = round($netAfterGateway - (float) ($order->delivery_fee ?? 0), 3);
        $commissionAmount = round($platformFeeBase * $planExtraRate, 3);
        $affiliateAmount = round($netAfterGateway - $commissionAmount, 3);
        $affiliatePercentual = $chargeAmount > 0
            ? round($affiliateAmount / $chargeAmount * 100, 4)
            : round((1.0 - $gatewayRate - $platformRate - $planExtraRate) * 100, 4);

        $platformFee = round($chargeAmount * $platformRate + $commissionAmount, 2);
        $companyNet = round($affiliateAmount, 2);

        return new PaymentSplitDTO(
            grossAmount: round($chargeAmount, 2),
            gatewayFee: round($chargeAmount - $platformFee - $companyNet, 2),
            platformFee: $platformFee,
            companyNet: $companyNet,
            affiliatePercentual: $affiliatePercentual,
        );
    }

    /**
     * $chargeAmount já vem inflado pela taxa do cartão quando a empresa não a absorve.
     */
    public function card(Order $order, Company $company, float $chargeAmount, float $cardRate): PaymentSplitDTO
    {
        $cardFee = round($chargeAmount * $cardRate, 3);
        $planFeeRate = $company->feePercentageForOrder($order);

        $netAfterCard = round($chargeAmount - $cardFee, 3);
        $platformFeeBase = round($netAfterCard - (float) ($order->delivery_fee ?? 0), 3);
        $platformFeeAmount = round($platformFeeBase * $planFeeRate, 3);
        $targetCompanyNet = round($netAfterCard - $platformFeeAmount, 3);
        $affiliatePercentual = $chargeAmount > 0
            ? round($targetCompanyNet / $chargeAmount * 100, 4)
            : round((1.0 - $planFeeRate) * 100, 4);

        $grossAmount = round((float) $order->total, 2);
        $platformFee = round($platformFeeAmount, 2);
        $companyNet = round($targetCompanyNet, 2);

        return new PaymentSplitDTO(
            grossAmount: $grossAmount,
            gatewayFee: max(0.0, round($grossAmount - $platformFee - $companyNet, 2)),
            platformFee: $platformFee,
            companyNet: $companyNet,
            affiliatePercentual: $affiliatePercentual,
        );
    }

    /**
     * Split de um pagamento já criado. Usa o que o orchestrator gravou; pagamentos
     * anteriores a essas colunas (ou de outro gateway) são recalculados pela mesma regra.
     */
    public function forPayment(Order $order, Payment $payment): PaymentSplitDTO
    {
        $isCard = $payment->original_amount !== null;
        $grossAmount = round((float) ($payment->original_amount ?? $payment->amount), 2);

        if ($payment->company_net_amount !== null && $payment->platform_fee !== null) {
            $platformFee = (float) $payment->platform_fee;
            $companyNet = (float) $payment->company_net_amount;

            return new PaymentSplitDTO(
                grossAmount: $grossAmount,
                gatewayFee: max(0.0, round($grossAmount - $platformFee - $companyNet, 2)),
                platformFee: $platformFee,
                companyNet: $companyNet,
            );
        }

        $company = $order->company ?? Company::findOrFail($order->company_id);

        if ($payment->payment_gateway === 'vindi') {
            return $isCard
                ? $this->card($order, $company, (float) $payment->amount, (float) ($payment->card_fee_rate ?? 0))
                : $this->pix($order, $company, (float) $payment->amount);
        }

        // Asaas (legado): a taxa PIX só sai da empresa quando ela absorve; senão o
        // cliente pagou por cima e ela não faz parte da venda da empresa.
        $absorbed = (bool) ($company->pix_fee_absorbed_by_company ?? false);
        $gatewayFee = $absorbed ? (float) $payment->pix_fee : 0.0;
        $grossAmount = $absorbed ? $grossAmount : round($grossAmount - (float) $payment->pix_fee, 2);
        $platformFee = round(
            max(0.0, $grossAmount - $gatewayFee - (float) ($order->delivery_fee ?? 0)) * $company->feePercentageForOrder($order),
            2,
        );

        return new PaymentSplitDTO(
            grossAmount: $grossAmount,
            gatewayFee: round($gatewayFee, 2),
            platformFee: $platformFee,
            companyNet: round($grossAmount - $gatewayFee - $platformFee, 2),
        );
    }
}
