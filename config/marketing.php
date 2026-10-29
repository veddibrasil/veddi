<?php

/*
|--------------------------------------------------------------------------
| E-mail marketing (super admin)
|--------------------------------------------------------------------------
|
| Campanhas da plataforma para os usuários das empresas (company_admin e
| branch_manager). Fica separado do transacional: mailer, fila e domínio
| próprios, para uma reclamação de spam não afetar os e-mails de pedido.
| Guia completo: docs/email-marketing.md.
|
*/

return [

    // "mail": envia pelo mailer abaixo (qualquer transport do Laravel: smtp, ses, postmark, log).
    // "resend": API HTTP do Resend, com webhook de entrega/bounce/spam (assinatura Svix).
    'provider' => env('MARKETING_EMAIL_PROVIDER', 'mail'),

    // Mailer usado pelo provider "mail" (definido em config/mail.php).
    'mailer' => env('MARKETING_MAILER', 'marketing'),

    'queue' => env('MARKETING_QUEUE', 'marketing'),

    'from' => [
        'address' => env('MARKETING_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
        'name' => env('MARKETING_FROM_NAME', env('APP_NAME', 'Veddi')),
    ],

    'reply_to' => env('MARKETING_REPLY_TO'),

    // Domínios com SPF/DKIM configurados no provedor. O checklist do disparo só libera
    // remetente de um destes domínios (ex.: news.veddi.com.br).
    'verified_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MARKETING_VERIFIED_DOMAINS', '')),
    ))),

    // LGPD: com true, só recebe quem marcou o aceite (marketing_opt_in_at). Com false, vale o
    // legítimo interesse (todos menos quem saiu). Definir com o jurídico antes do 1º disparo.
    'require_opt_in' => (bool) env('MARKETING_REQUIRE_OPT_IN', true),

    // Papéis da empresa que podem entrar no público (dono = company_admin).
    'roles' => ['company_admin', 'branch_manager'],

    'sending' => [
        // Envios por segundo, somando todos os workers (conforme o plano do provedor).
        'rate_per_second' => (int) env('MARKETING_RATE_PER_SECOND', 10),

        // Aquecimento do domínio: máximo de envios por dia (somando campanhas e automações).
        // 0 = sem limite.
        'daily_limit' => (int) env('MARKETING_DAILY_LIMIT', 300),

        'batch_size' => (int) env('MARKETING_BATCH_SIZE', 500),
        'build_chunk' => 1000,

        // Espera entre falhas temporárias do mesmo destinatário (segundos).
        'backoff' => [60, 300, 900],

        // Destinatário preso em "sending" por mais que isso vira falha (sem reenvio).
        'stale_after_minutes' => 15,

        'test_max_recipients' => 5,
    ],

    // Pausa automática + alerta no Discord quando a campanha passa destes limites.
    'health' => [
        'min_sample' => (int) env('MARKETING_HEALTH_MIN_SAMPLE', 50),
        'max_bounce_rate' => 0.05,
        'max_complaint_rate' => 0.001,
    ],

    'events_retention_months' => 12,

    // Rodapé obrigatório (LGPD / CAN-SPAM). O checklist bloqueia o disparo sem razão social e CNPJ.
    'legal' => [
        'company_name' => env('MARKETING_LEGAL_NAME'),
        'document' => env('MARKETING_LEGAL_DOCUMENT'),
        'address' => env('MARKETING_LEGAL_ADDRESS'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
        'base_url' => env('RESEND_BASE_URL', 'https://api.resend.com'),
    ],

];
