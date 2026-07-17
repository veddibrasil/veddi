<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            // 'nightwatch' sempre incluso para que todo log, independente do
            // canal ou nivel, chegue ao dashboard do Nightwatch.
            'channels' => array_unique([...explode(',', (string) env('LOG_STACK', 'single')), 'nightwatch']),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'discord' => [
            'driver' => 'stack',
            'channels' => ['discord_target', 'nightwatch'],
            'ignore_exceptions' => false,
        ],

        'discord_target' => [
            'driver' => 'monolog',
            'handler' => \App\Logging\DiscordWebhookHandler::class,
            'handler_with' => [
                'webhookUrl' => env('DISCORD_LOG_WEBHOOK_URL', ''),
                'level' => env('DISCORD_LOG_LEVEL', 'critical'),
            ],
            'url' => env('DISCORD_LOG_WEBHOOK_URL', ''),
            'level' => env('DISCORD_LOG_LEVEL', 'critical'),
        ],

        // Canal dedicado ao fluxo de geração de PIX (Vindi), do início ao
        // sucesso/falha. Threshold mais baixo que o canal 'discord' (que fica
        // em 'error' em produção) para que o log de sucesso também apareça.
        'discord_pix' => [
            'driver' => 'stack',
            'channels' => ['discord_pix_target', 'nightwatch'],
            'ignore_exceptions' => false,
        ],

        'discord_pix_target' => [
            'driver' => 'monolog',
            'handler' => \App\Logging\DiscordWebhookHandler::class,
            'handler_with' => [
                'webhookUrl' => env('DISCORD_PIX_LOG_WEBHOOK_URL', env('DISCORD_LOG_WEBHOOK_URL', '')),
                'level' => env('DISCORD_PIX_LOG_LEVEL', 'info'),
            ],
            'url' => env('DISCORD_PIX_LOG_WEBHOOK_URL', env('DISCORD_LOG_WEBHOOK_URL', '')),
            'level' => env('DISCORD_PIX_LOG_LEVEL', 'info'),
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        'orders' => [
            'driver' => 'stack',
            'channels' => ['orders_target', 'nightwatch'],
            'ignore_exceptions' => false,
            'tap' => [\App\Logging\DiscordWebhookTap::class],
        ],

        'orders_target' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => 'debug',
            'replace_placeholders' => true,
        ],

        'payments' => [
            'driver' => 'stack',
            'channels' => ['payments_target', 'nightwatch'],
            'ignore_exceptions' => false,
            'tap' => [\App\Logging\DiscordWebhookTap::class],
        ],

        'payments_target' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => 'debug',
            'replace_placeholders' => true,
        ],

        'chat' => [
            'driver' => 'stack',
            'channels' => ['chat_target', 'nightwatch'],
            'ignore_exceptions' => false,
            'tap' => [\App\Logging\DiscordWebhookTap::class],
        ],

        'chat_target' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => 'debug',
            'replace_placeholders' => true,
        ],

        'webhook' => [
            'driver' => 'stack',
            'channels' => ['webhook_target', 'nightwatch'],
            'ignore_exceptions' => false,
            'tap' => [\App\Logging\DiscordWebhookTap::class],
        ],

        'webhook_target' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => 'debug',
            'replace_placeholders' => true,
        ],

        'fiscal' => [
            'driver' => 'stack',
            'channels' => ['fiscal_target', 'nightwatch'],
            'ignore_exceptions' => false,
        ],

        'fiscal_target' => [
            'driver' => 'daily',
            'path' => storage_path('logs/fiscal.log'),
            'level' => 'debug',
            'days' => 90,
            'replace_placeholders' => true,
        ],

        'audit' => [
            'driver' => 'stack',
            'channels' => ['audit_target', 'nightwatch'],
            'ignore_exceptions' => false,
        ],

        'audit_target' => [
            'driver' => 'daily',
            'path' => storage_path('logs/audit.log'),
            'level' => 'info',
            'days' => 90,
            'replace_placeholders' => true,
        ],

        'whatsapp' => [
            'driver' => 'stack',
            'channels' => ['whatsapp_target', 'nightwatch'],
            'ignore_exceptions' => false,
        ],

        'whatsapp_target' => [
            'driver' => 'daily',
            'path' => storage_path('logs/whatsapp.log'),
            'level' => 'debug',
            'days' => 14,
            'replace_placeholders' => true,
        ],

    ],

];
