<?php

return [
    // Enable only after the AUTHENTICATION / COPY_CODE template is approved on the sender WABA.
    'whatsapp_auth_enabled' => env('CHAT_WHATSAPP_AUTH_ENABLED', false),
    'whatsapp_auth_template' => env('CHAT_WHATSAPP_AUTH_TEMPLATE', 'veddi_confirmar_telefone'),
    'whatsapp_auth_language' => env('CHAT_WHATSAPP_AUTH_LANGUAGE', 'pt_BR'),
];
