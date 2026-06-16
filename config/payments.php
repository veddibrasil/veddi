<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Vindi Intermediador (Yapay) — credenciais e taxas de pedido
    |--------------------------------------------------------------------------
    | vindi_pix_rate   : percentual cobrado por transação PIX (0,85%)
    | vindi_boleto_fee : taxa fixa por boleto pago em R$ (R$2,79)
    |
    | PIX: taxa = amount * vindi_pix_rate
    | Liquidação PIX: 2×/dia (10h e 15h), D+1 útil no pior caso.
    */
    'vindi_pix_rate' => (float) env('VINDI_PIX_RATE', 0.0085),         // Vindi gateway fee
    'vindi_pix_platform_rate' => (float) env('VINDI_PIX_PLATFORM_RATE', 0.0014), // platform margin (total = 0.99%)
    'vindi_boleto_fee' => (float) env('VINDI_BOLETO_FEE', 2.79),
    'vindi_token_account' => env('VINDI_TOKEN_ACCOUNT'),
    'vindi_reseller_token' => env('VINDI_RESELLER_TOKEN'),
    'vindi_consumer_key' => env('VINDI_CONSUMER_KEY'),
    'vindi_consumer_secret' => env('VINDI_CONSUMER_SECRET'),
    'vindi_access_token' => env('VINDI_ACCESS_TOKEN'),
    'vindi_authorization_code' => env('VINDI_AUTHORIZATION_CODE'),
    'vindi_refresh_token' => env('VINDI_REFRESH_TOKEN'),

    // Endpoints de saque de afiliado — confirmar com suporte Yapay antes de ativar em produção.
    // Definir via env para trocar sem deploy quando Yapay fornecer URL correta.
    // null = usa o padrão hardcoded em VindiService::createTransfer()
    'vindi_withdrawal_pix_endpoint' => env('VINDI_WITHDRAWAL_PIX_ENDPOINT'),
    'vindi_withdrawal_ted_endpoint' => env('VINDI_WITHDRAWAL_TED_ENDPOINT'),

    // Portal web Yapay — usado na carteira para redirecionar empresa ao painel próprio.
    'vindi_portal_url' => env('VINDI_PORTAL_URL', 'https://intermediador.yapay.com.br'),

    /*
    |--------------------------------------------------------------------------
    | Taxa PIX no saque da carteira
    |--------------------------------------------------------------------------
    | Valor fixo descontado quando a empresa solicita saque via PIX.
    */
    'pix_withdrawal_fee' => (float) env('PIX_WITHDRAWAL_FEE', 0.50),

    // Valor líquido mínimo transferido após desconto da taxa PIX.
    // Se Yapay exigir mínimo diferente, ajustar via env sem redeploy.
    'pix_withdrawal_min_net' => (float) env('PIX_WITHDRAWAL_MIN_NET', 10.00),

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
        // Taxa padrão usada no preview de checkout (brand desconhecida antes de digitar o cartão).
        // Não afeta o cálculo real — este usa CardBrand::rate() por bandeira.
        'default_rate' => (float) env('CARD_RATE_DEFAULT', 0.0280), // Mastercard

        // Taxas de antecipação de recebíveis na carteira (não relacionado à taxa de bandeira).
        'anticipation_d2' => (float) env('CARD_ANT_D2', 0.0145),
        'anticipation_d7' => (float) env('CARD_ANT_D7', 0.0075),
        'anticipation_d15' => (float) env('CARD_ANT_D15', 0.0075),
        'anticipation_d30' => (float) env('CARD_ANT_D30', 0.0000),
    ],
];
