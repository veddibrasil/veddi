<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 99Food — hub único (um app parceiro pra toda a plataforma)
    |--------------------------------------------------------------------------
    | app_id/app_secret são as credenciais ÚNICAS do app parceiro VEDDI no portal da
    | 99Food, compartilhadas por todas as empresas/filiais. Cada Food99Integration guarda
    | só o que é da loja: shop_id, app_shop_id e o auth_token.
    |
    | Desligado (padrão), card, tela de configuração e agendamentos ficam escondidos até
    | a homologação com a 99Food.
    */
    'enabled' => (bool) env('FOOD99_ENABLED', false),
    'app_id' => env('FOOD99_APP_ID'),
    'app_secret' => env('FOOD99_APP_SECRET'),
    'api_base_url' => env('FOOD99_API_BASE_URL', 'https://openapi.99food.com'),
    'timezone' => env('FOOD99_TIMEZONE', 'America/Sao_Paulo'),

    /*
    | Ferramentas de homologação na tela da integração (respostas cruas da API).
    | Ligar só no ambiente usado no roteiro da loja de teste.
    */
    'homologation_mode' => (bool) env('FOOD99_HOMOLOGATION_MODE', false),

    /*
    | Quem confirma o pedido: 1 = app 99Food Loja e API, 2 = só API.
    | [VALIDAR 99Food] se lojas que ainda usam o app 99 Loja preferem 1.
    */
    'order_confirm_method' => (int) env('FOOD99_ORDER_CONFIRM_METHOD', 2),

    /* Renova o auth_token quando faltar menos que isso pra expirar (a expiração da 99 é aleatória). */
    'token_refresh_margin_seconds' => (int) env('FOOD99_TOKEN_REFRESH_MARGIN', 300),
];
