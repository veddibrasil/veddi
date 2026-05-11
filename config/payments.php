<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Taxa PIX no pagamento do pedido
    |--------------------------------------------------------------------------
    | Valor fixo cobrado por transação PIX.
    | Quem paga é definido pela flag pix_fee_absorbed_by_company na empresa:
    |   false (padrão) → cliente paga (adicionado ao valor do charge no Asaas)
    |   true           → empresa absorve (deduzido do net_value do repasse)
    */
    'pix_payment_fee' => (float) env('PIX_PAYMENT_FEE', 0.50),

    /*
    |--------------------------------------------------------------------------
    | Taxa PIX via Stark Bank
    |--------------------------------------------------------------------------
    | Taxa fixa por transação PIX processada via Stark Bank (R$ 0,50).
    */
    'stark_pix_fee' => (float) env('STARK_PIX_FEE', 0.50),

    /*
    |--------------------------------------------------------------------------
    | Taxa PIX no saque da carteira
    |--------------------------------------------------------------------------
    | Valor fixo descontado quando a empresa solicita saque via PIX.
    */
    'pix_withdrawal_fee' => (float) env('PIX_WITHDRAWAL_FEE', 0.50),

    /*
    |--------------------------------------------------------------------------
    | Prazo de liberação (dias corridos)
    |--------------------------------------------------------------------------
    */
    'release_days' => [
        'pix' => (int) env('PIX_RELEASE_DAYS', 2),
        'boleto' => 2,
        'cartao' => 32,
    ],

    /*
    |--------------------------------------------------------------------------
    | Taxas de cartão de crédito (padrão do sistema, sobrescrito por empresa)
    |--------------------------------------------------------------------------
    | Taxas por faixa de parcelas (percentual decimal, ex: 0.0299 = 2,99%)
    | Taxas de antecipação baseadas no prazo de recebimento.
    |
    | Fórmula: valor_cobrado = valor_pedido / (1 - taxa_total)
    | Onde taxa_total = taxa_cartao + taxa_antecipacao + taxa_sistema
    */
    'credit_card' => [
        // Taxas por número de parcelas
        'rate_1x' => (float) env('CARD_RATE_1X', 0.0299), // 2,99%
        'rate_2_6x' => (float) env('CARD_RATE_2_6X', 0.0349), // 3,49%
        'rate_7_12x' => (float) env('CARD_RATE_7_12X', 0.0399), // 3,99%

        // Taxas de antecipação por prazo de recebimento
        'anticipation_d2' => (float) env('CARD_ANT_D2', 0.0299), // 2,99% → D+2
        'anticipation_d7' => (float) env('CARD_ANT_D7', 0.0249), // 2,49% → D+7
        'anticipation_d15' => (float) env('CARD_ANT_D15', 0.0199), // 1,99% → D+15
        'anticipation_d30' => (float) env('CARD_ANT_D30', 0.0000), // 0,00% → D+30 (sem antecipação)
    ],
];
