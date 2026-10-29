<?php

return [
    'code_channel' => env('CHAT_CODE_CHANNEL', 'email'), // email | whatsapp | none

    // Master switch for WhatsApp verification, independent of the sending provider.
    'whatsapp_auth_enabled' => env('CHAT_WHATSAPP_AUTH_ENABLED', false),
    'whatsapp_meta_enabled' => env('CHAT_WHATSAPP_META_ENABLED', true),
    'whatsapp_wuzapi_fallback_enabled' => env('CHAT_WHATSAPP_WUZAPI_FALLBACK_ENABLED', false),
    'whatsapp_auth_template' => env('CHAT_WHATSAPP_AUTH_TEMPLATE', 'veddi_confirmar_telefone'),
    'whatsapp_auth_language' => env('CHAT_WHATSAPP_AUTH_LANGUAGE', 'pt_BR'),
];
