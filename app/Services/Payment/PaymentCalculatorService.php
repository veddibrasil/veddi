<?php

namespace App\Services\Payment;

class PaymentCalculatorService
{
    /**
     * Calcula o valor final a ser cobrado do cliente, embutindo a taxa de cartão,
     * para que a empresa receba exatamente o valor original do pedido.
     *
     * Fórmula: valor_cobrado = valor_pedido / (1 - taxa_cartao)
     * A taxa da plataforma ($platformFeeRate) é debitada do líquido da empresa via split,
     * não é repassada ao cliente — retornada separadamente para exibição.
     *
     * @param  float  $orderAmount  Valor original do pedido (sem taxas)
     * @param  float  $cardRate  Taxa da bandeira (ex: 0.028 para Mastercard)
     * @param  float  $platformFeeRate  Taxa da plataforma (ex: 0.01 ou 0.03) — separada do cartão
     * @return array{
     *   original_amount: float,
     *   final_amount: float,
     *   fee_amount: float,
     *   card_rate: float,
     *   platform_rate: float,
     *   platform_fee_amount: float,
     * }
     */
    public function calculate(
        float $orderAmount,
        float $cardRate,
        float $platformFeeRate = 0.0,
    ): array {
        $finalAmount = $cardRate < 1.0
            ? round($orderAmount / (1 - $cardRate), 2)
            : $orderAmount;

        $feeAmount = round($finalAmount - $orderAmount, 2);
        $platformFeeAmount = round($orderAmount * $platformFeeRate, 2);

        return [
            'original_amount' => $orderAmount,
            'final_amount' => $finalAmount,
            'fee_amount' => $feeAmount,
            'card_rate' => $cardRate,
            'platform_rate' => $platformFeeRate,
            'platform_fee_amount' => $platformFeeAmount,
        ];
    }
}
