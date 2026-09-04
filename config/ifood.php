<?php

return [
    /*
    |--------------------------------------------------------------------------
    | iFood — hub distribuído (uma única aplicação parceira pra toda a plataforma)
    |--------------------------------------------------------------------------
    | partner_client_id/partner_client_secret são as credenciais ÚNICAS do app
    | parceiro Mister Coxinha cadastrado no portal do iFood — compartilhadas
    | por todas as empresas/filiais. Cada IfoodIntegration guarda apenas o que
    | é específico daquela loja: merchant_id, tokens (access/refresh) e o
    | user_code/verifier do fluxo de autorização em andamento.
    */
    'partner_client_id' => env('IFOOD_PARTNER_CLIENT_ID'),
    'partner_client_secret' => env('IFOOD_PARTNER_CLIENT_SECRET'),
    'api_base_url' => env('IFOOD_API_BASE_URL', 'https://merchant-api.ifood.com.br'),
    'webhook_enabled' => (bool) env('IFOOD_WEBHOOK_ENABLED', true),
    'polling_fallback_interval' => (int) env('IFOOD_POLLING_FALLBACK_INTERVAL', 30),
];
