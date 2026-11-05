<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Keeta (Open Delivery) — hub único (um app parceiro pra toda a plataforma)
    |--------------------------------------------------------------------------
    | client_id/client_secret são as credenciais ÚNICAS do app VEDDI no Dev Portal da Keeta,
    | compartilhadas por todas as empresas/filiais. O token é do app (grant app_level_token) e
    | vale para todas as lojas autorizadas; cada KeetaIntegration guarda só o que é da loja:
    | o merchant_ref (nosso merchantId), o keeta_merchant_id e a chave do endpoint de cardápio.
    |
    | Desligado (padrão), card, tela de configuração e agendamentos ficam escondidos até a
    | homologação (SIT) com a Keeta.
    */
    'enabled' => (bool) env('KEETA_ENABLED', false),

    /*
    | Empresas que veem a tela e podem conectar (ids separados por vírgula). Vazio = todas as
    | que têm o módulo de integrações. Loja já conectada continua operando fora da lista.
    */
    'company_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('KEETA_COMPANY_IDS', ''))))),

    'client_id' => env('KEETA_CLIENT_ID'),
    'client_secret' => env('KEETA_CLIENT_SECRET'),

    /* AppId da Keeta (header X-App-Id dos webhooks de pedido). Vazio = não confere. */
    'app_id' => env('KEETA_APP_ID'),

    'api_base_url' => rtrim((string) env('KEETA_API_BASE_URL', 'https://open.mykeeta.com/api/open/opendelivery'), '/'),
    'timezone' => env('KEETA_TIMEZONE', 'America/Sao_Paulo'),

    /*
    | Ferramentas de homologação na tela da integração (respostas cruas da API).
    | Ligar só no ambiente usado no roteiro da loja de teste.
    */
    'homologation_mode' => (bool) env('KEETA_HOMOLOGATION_MODE', false),

    /*
    | Aceite automático padrão das filiais novas. A Keeta cancela o pedido sem confirmação em
    | 5 min e fecha a loja temporariamente; com o aceite automático o VEDDI confirma assim que o
    | pedido entra no painel. Cada filial pode mudar na tela da integração.
    */
    'auto_accept_default' => (bool) env('KEETA_AUTO_ACCEPT', true),

    /* Renova o token do app quando faltar menos que isso pra expirar. */
    'token_refresh_margin_seconds' => (int) env('KEETA_TOKEN_REFRESH_MARGIN', 300),

    /*
    | Polling de eventos (fallback do webhook): no máximo 2 chamadas por minuto por token e até
    | 100 merchantIds por chamada. Só entra quem está com o webhook sem sinal.
    */
    'polling_batch_size' => 100,
];
