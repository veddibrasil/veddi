<?php

return [

    'focus_nfe' => [
        'base_url' => env('FOCUS_NFE_BASE_URL', 'https://homologacao.focusnfe.com.br'),
        'token' => env('FOCUS_NFE_TOKEN'),
        'webhook_token' => env('FOCUS_NFE_WEBHOOK_TOKEN'),
    ],

    'environment' => env('FISCAL_ENVIRONMENT', 'homologacao'),
    'crt' => (int) env('FISCAL_CRT', 1),
    'nfce_serie' => (int) env('FISCAL_NFCE_SERIE', 1),

    'certificate' => [
        'path' => env('FISCAL_CERT_PATH'),
        'password' => env('FISCAL_CERT_PASSWORD'),
    ],

    'addon_monthly_price' => (float) env('FISCAL_ADDON_PRICE', 49.90),

];
