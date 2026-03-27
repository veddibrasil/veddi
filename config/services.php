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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mapbox' => [
        'token' => env('MAPBOX_TOKEN'),
    ],

    'asaas' => [
        'api_key'           => env('ASAAS_API_KEY'),
        'sandbox'           => env('ASAAS_SANDBOX', true),
        'webhook_token'     => env('ASAAS_WEBHOOK_TOKEN'),
        'pro_monthly_value' => env('ASAAS_PRO_MONTHLY_VALUE', 49.90),
        'veddi_wallet_id'   => env('ASAAS_VEDDI_WALLET_ID'),
        'base_url'          => env('ASAAS_SANDBOX', true)
            ? 'https://sandbox.asaas.com/api/v3'
            : 'https://api.asaas.com/v3',
    ],

];
