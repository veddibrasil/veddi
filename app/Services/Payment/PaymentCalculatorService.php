<?php

namespace App\Services\Payment;

use App\Models\PaymentSettings;

class PaymentCalculatorService
{
    /**
     * Calcula o valor final a ser cobrado do cliente, embutindo as taxas de cartão,
     * para que a empresa receba exatamente o valor original do pedido.
     *
     * Fórmula: valor_cobrado = valor_pedido / (1 - taxa_total)
     * Onde:    taxa_total = taxa_cartao + taxa_antecipacao + taxa_sistema
     *
     * @param  float  $orderAmount  Valor original do pedido (sem taxas)
     * @param  int  $anticipationDays  Prazo de recebimento em dias (2, 7, 15 ou 30)
     * @param  PaymentSettings|null  $settings  Configurações da empresa (null = usa config global)
     * @return array{
     *   original_amount: float,
     *   final_amount: float,
     *   fee_amount: float,
     *   total_rate: float,
     *   card_rate: float,
     *   anticipation_rate: float,
     *   system_rate: float,
     *   anticipation_days: int,
     * }
     */
    public function calculate(
        float $orderAmount,
        int $anticipationDays,
        ?PaymentSettings $settings = null
    ): array {
        $cardRate = (float) ($settings?->card_rate_1x ?? config('payments.credit_card.rate_1x'));
        $anticipationRate = $this->anticipationRate($anticipationDays, $settings);
        $systemRate = (float) ($settings?->system_fee_rate ?? 0.0);

        $totalRate = $cardRate + $anticipationRate + $systemRate;
        $finalAmount = round($orderAmount / (1 - $totalRate), 2);
        $feeAmount = round($finalAmount - $orderAmount, 2);

        return [
            'original_amount' => $orderAmount,
            'final_amount' => $finalAmount,
            'fee_amount' => $feeAmount,
            'total_rate' => $totalRate,
            'card_rate' => $cardRate,
            'anticipation_rate' => $anticipationRate,
            'system_rate' => $systemRate,
            'anticipation_days' => $anticipationDays,
        ];
    }

    private function anticipationRate(int $days, ?PaymentSettings $s): float
    {
        return match (true) {
            $days <= 2 => (float) ($s?->anticipation_rate_d2 ?? config('payments.credit_card.anticipation_d2')),
            $days <= 7 => (float) ($s?->anticipation_rate_d7 ?? config('payments.credit_card.anticipation_d7')),
            $days <= 15 => (float) ($s?->anticipation_rate_d15 ?? config('payments.credit_card.anticipation_d15')),
            default => (float) ($s?->anticipation_rate_d30 ?? config('payments.credit_card.anticipation_d30')),
        };
    }
}
