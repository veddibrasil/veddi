<?php

return [

    'focus_nfe' => [
        'base_url_homologacao' => env('FOCUS_NFE_BASE_URL_HOMOLOGACAO', 'https://homologacao.focusnfe.com.br'),
        'base_url_producao' => env('FOCUS_NFE_BASE_URL_PRODUCAO', 'https://api.focusnfe.com.br'),
        'token' => env('FOCUS_NFE_TOKEN'),
        'webhook_token' => env('FOCUS_NFE_WEBHOOK_TOKEN'),
    ],

    // Ambiente fiscal padrão (SEFAZ homologação/produção). Segue o APP_ENV automaticamente
    // (produção só quando APP_ENV=production); FISCAL_ENVIRONMENT no .env sobrescreve se
    // precisar forçar homologação numa empresa mesmo rodando em produção.
    // Atenção: se usar config:cache, rode o cache dentro do próprio ambiente de deploy —
    // o valor calculado aqui fica congelado no cache.
    'environment' => env('FISCAL_ENVIRONMENT', env('APP_ENV') === 'production' ? 'producao' : 'homologacao'),
    'crt' => (int) env('FISCAL_CRT', 1),
    'nfce_serie' => (int) env('FISCAL_NFCE_SERIE', 1),

    'certificate' => [
        'path' => env('FISCAL_CERT_PATH'),
        'password' => env('FISCAL_CERT_PASSWORD'),
    ],

    'addon_monthly_price' => (float) env('FISCAL_ADDON_PRICE', 149.00),

];
