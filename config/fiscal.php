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

];
