<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Vindi Intermediador (Yapay) — credenciais e taxas de pedido
    |--------------------------------------------------------------------------
    | vindi_pix_rate   : percentual cobrado por transação PIX (0,85%)
    | vindi_pix_fee_min: taxa mínima por transação PIX em R$ (R$1,60)
    | vindi_boleto_fee : taxa fixa por boleto pago em R$ (R$2,79)
    |
    | PIX: taxa = max(vindi_pix_fee_min, amount * vindi_pix_rate)
    | Liquidação PIX: 2×/dia (10h e 15h), D+1 útil no pior caso.
    */
    'vindi_pix_rate' => (float) env('VINDI_PIX_RATE', 0.0085),         // Vindi gateway fee
    'vindi_pix_platform_rate' => (float) env('VINDI_PIX_PLATFORM_RATE', 0.0014), // platform margin (total = 0.99%)
    'vindi_pix_fee_min' => (float) env('VINDI_PIX_FEE_MIN', 1.60),
    'vindi_boleto_fee' => (float) env('VINDI_BOLETO_FEE', 2.79),
    'vindi_token_account' => env('VINDI_TOKEN_ACCOUNT'),
    'vindi_reseller_token' => env('VINDI_RESELLER_TOKEN'),
    'vindi_consumer_key' => env('VINDI_CONSUMER_KEY'),
    'vindi_consumer_secret' => env('VINDI_CONSUMER_SECRET'),
    'vindi_access_token' => env('VINDI_ACCESS_TOKEN'),
    'vindi_authorization_code' => env('VINDI_AUTHORIZATION_CODE'),
    'vindi_refresh_token' => env('VINDI_REFRESH_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Taxa PIX legada (Asaas / Vindi) — mantida para dados históricos
    |--------------------------------------------------------------------------
    | Usada apenas quando pix_fee_absorbed_by_company = true e o pagamento
    | não possui pix_fee salvo no modelo (registros anteriores à migração Vindi).
    */
    'pix_payment_fee' => (float) env('PIX_PAYMENT_FEE', 0.50),

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
        'pix' => (int) env('PIX_RELEASE_DAYS', 1),
        'boleto' => 2,
        'cartao' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Taxas de cartão de crédito — Vindi Intermediador (Yapay)
    |--------------------------------------------------------------------------
    | Taxas totais por número de parcelas e prazo de recebimento.
    | Cada tabela (rates_*) é indexada pelo número de parcelas (1–12).
    | As taxas já incluem o custo de antecipação — não some card_rate + ant.
    |
    | Fórmula: valor_cobrado = valor_pedido / (1 - taxa_total)
    | taxa_total = rates_<prazo>[parcelas] + system_rate (margem da plataforma)
    |
    | Prazos disponíveis (proposta Vindi CNPJ 66.539.173/0001-90):
    |   d30  — melhor custo financeiro
    |   d14  — equilíbrio entre taxa e prazo
    |   d2   — mais velocidade
    |   fluxo— receba no fluxo normal da venda (mais barato para múltiplas parcelas)
    */
    'credit_card' => [
        // ── Tabela D+30 — melhor custo financeiro ────────────────────────────
        // Taxas simplificadas por faixa (padrão do sistema para PaymentSettings legado)
        'rate_1x' => (float) env('CARD_RATE_1X', 0.0310),    // 3,10%
        'rate_2_6x' => (float) env('CARD_RATE_2_6X', 0.0371), // 3,71%
        'rate_7_12x' => (float) env('CARD_RATE_7_12X', 0.0407), // 4,07%

        // Custo adicional de antecipação sobre D+30 1x (para PaymentCalculatorService legado)
        'anticipation_d2' => (float) env('CARD_ANT_D2', 0.0145),  // +1,45% → D+2 (4,55% total 1x)
        'anticipation_d7' => (float) env('CARD_ANT_D7', 0.0075),  // +0,75% → D+7 (proxy D+14)
        'anticipation_d15' => (float) env('CARD_ANT_D15', 0.0075), // +0,75% → D+15 (proxy D+14)
        'anticipation_d30' => (float) env('CARD_ANT_D30', 0.0000), // 0,00% → D+30

        // ── Tabelas por parcela × prazo (lookup preciso, todas as marcas) ─────
        // Visa, Mastercard, Elo, American Express: taxas idênticas nesta proposta.

        'rates_d30' => [
            1 => 0.0310, 2 => 0.0371, 3 => 0.0371, 4 => 0.0371,
            5 => 0.0371, 6 => 0.0371, 7 => 0.0407, 8 => 0.0407,
            9 => 0.0407, 10 => 0.0407, 11 => 0.0407, 12 => 0.0407,
        ],

        'rates_d14' => [
            1 => 0.0385, 2 => 0.0528, 3 => 0.0613, 4 => 0.0699,
            5 => 0.0783, 6 => 0.0868, 7 => 0.0983, 8 => 0.1065,
            9 => 0.1144, 10 => 0.1223, 11 => 0.1300, 12 => 0.1376,
        ],

        'rates_d2' => [
            1 => 0.0455, 2 => 0.0598, 3 => 0.0682, 4 => 0.0769,
            5 => 0.0852, 6 => 0.0935, 7 => 0.1051, 8 => 0.1130,
            9 => 0.1209, 10 => 0.1287, 11 => 0.1365, 12 => 0.1440,
        ],

        // Fluxo: receba cada parcela no vencimento (menor custo para parcelado longo)
        'rates_fluxo' => [
            1 => 0.0291, 2 => 0.0434, 3 => 0.0520, 4 => 0.0606,
            5 => 0.0692, 6 => 0.0777, 7 => 0.0894, 8 => 0.0977,
            9 => 0.1056, 10 => 0.1137, 11 => 0.1215, 12 => 0.1293,
        ],
    ],
];
