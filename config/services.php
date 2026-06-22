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

    'asaas' => [
        'api_key' => env('ASAAS_API_KEY'),
        'sandbox' => env('ASAAS_SANDBOX', true),
        'allow_transfers_in_non_production' => env('ASAAS_ALLOW_TRANSFERS_IN_NON_PROD', false),
        'webhook_token' => env('ASAAS_WEBHOOK_TOKEN'),
        'pro_monthly_value' => env('ASAAS_PRO_MONTHLY_VALUE', 49.90),
        'veddi_wallet_id' => env('ASAAS_VEDDI_WALLET_ID'),
        'base_url' => env('ASAAS_SANDBOX', true)
            ? 'https://sandbox.asaas.com/api/v3'
            : 'https://api.asaas.com/v3',

        'reserve_ratio' => (float) env('ASAAS_RESERVE_RATIO', 0.05),

        'platform_asaas_pix_key' => env('PLATFORM_ASAAS_PIX_KEY'),
        'platform_asaas_pix_key_type' => env('PLATFORM_ASAAS_PIX_KEY_TYPE', 'CNPJ'),

        'platform_company_name' => env('PLATFORM_COMPANY_NAME'),
        'platform_company_cnpj' => env('PLATFORM_COMPANY_CNPJ'),
    ],

];
