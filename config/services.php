<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'mapbox' => [
        'token' => env('MAPBOX_TOKEN'),
    ],

    'bacen' => [
        'cdi_rate_fallback_daily' => (float) env('BACEN_CDI_FALLBACK_DAILY', 0.000527),
    ],

    'support' => [
        'whatsapp_number' => env('SUPPORT_WHATSAPP_NUMBER'),
    ],

    // App Meta (Graph API) — usado pela integração oficial com a WhatsApp Cloud API.
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'graph_version' => env('META_GRAPH_VERSION', 'v25.0'),
    ],

    // WhatsApp Cloud API: cada restaurante conecta o próprio número via Embedded Signup.
    // O bloco 'platform' é o número da própria Veddi, usado só para homologação/fallback.
    'whatsapp' => [
        'es_config_id' => env('WHATSAPP_ES_CONFIG_ID'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'platform' => [
            'waba_id' => env('WHATSAPP_PLATFORM_WABA_ID'),
            'phone_number_id' => env('WHATSAPP_PLATFORM_PHONE_NUMBER_ID'),
            'token' => env('WHATSAPP_PLATFORM_TOKEN'),
        ],
        'fallback_to_platform' => env('WHATSAPP_FALLBACK_TO_PLATFORM', false),
    ],

    // Existing self-hosted WuzAPI. Session dedicated to chat verification codes.
    'wuzapi' => [
        'base_url' => env('WUZAPI_BASE_URL'),
        'session_token' => env('WUZAPI_SESSION_TOKEN'),
    ],

    // Par de chaves do QZ Tray em base64 (evita problema de newline em env
    // var). Usado como alternativa aos arquivos em storage/app/private/qz/
    // pra ambientes sem acesso a shell (ex.: Laravel Cloud).
    'qz' => [
        'private_key' => env('QZ_PRIVATE_KEY'),
        'certificate' => env('QZ_CERTIFICATE'),
    ],

    'asaas' => [
        'api_key' => env('ASAAS_API_KEY'),
        'sandbox' => env('ASAAS_SANDBOX', true),
        'webhook_token' => env('ASAAS_WEBHOOK_TOKEN'),
        'base_url' => env('ASAAS_SANDBOX', true)
            ? 'https://sandbox.asaas.com/api/v3'
            : 'https://api.asaas.com/v3',
    ],

];
