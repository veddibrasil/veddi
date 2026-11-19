<?php

return [

    'focus_nfe' => [
        'base_url_homologacao' => env('FOCUS_NFE_BASE_URL_HOMOLOGACAO', 'https://homologacao.focusnfe.com.br'),
        'base_url_producao' => env('FOCUS_NFE_BASE_URL_PRODUCAO', 'https://api.focusnfe.com.br'),
        'token' => env('FOCUS_NFE_TOKEN'),
        'webhook_token' => env('FOCUS_NFE_WEBHOOK_TOKEN'),
    ],

    // Ambiente fiscal acompanha o APP_ENV (produção só com APP_ENV=production).
    // Atenção: se usar config:cache, rode o cache dentro do próprio ambiente de deploy —
    // o valor calculado aqui fica congelado no cache.
    'environment' => env('APP_ENV') === 'production' ? 'producao' : 'homologacao',
    'crt' => (int) env('FISCAL_CRT', 1),
    'nfce_serie' => (int) env('FISCAL_NFCE_SERIE', 1),

    'addon_monthly_price' => (float) env('FISCAL_ADDON_PRICE', 149.00),

    // Política de emissão (App\Services\Fiscal\FiscalEmissionPolicy). Limites do Ajuste SINIEF
    // 19/16, cláusula quarta: VII, "a" (identificação a partir de R$ 10 mil) e § 4º (NFC-e
    // vedada a partir de R$ 200 mil). O § 5º permite à UF reduzir os dois valores: conferir a
    // legislação do estado da filial antes de liberar a NFC-e com CNPJ.
    'policy' => [
        'nfce_identification_threshold' => (float) env('FISCAL_NFCE_IDENTIFICATION_THRESHOLD', 10000),
        'nfce_max_total' => (float) env('FISCAL_NFCE_MAX_TOTAL', 200000),
    ],

    // Emissão automática de NFC-e (App\Services\Fiscal\FiscalAutoIssuePolicy), ligada por filial
    // em Fiscal › Configurações. delay_seconds: espera depois do aceite/pagamento, para o PDV e o
    // painel terminarem de gravar o pedido. lookback_days: a varredura (fiscal:auto-issue) só
    // olha pedidos recentes. breaker_threshold: notas automáticas rejeitadas seguidas na mesma
    // filial que pausam a emissão automática dela.
    'auto_issue' => [
        'delay_seconds' => (int) env('FISCAL_AUTO_ISSUE_DELAY', 15),
        'lookback_days' => (int) env('FISCAL_AUTO_ISSUE_LOOKBACK_DAYS', 3),
        'breaker_threshold' => (int) env('FISCAL_AUTO_ISSUE_BREAKER', 3),
    ],

];
