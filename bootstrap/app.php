<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', 'REMOTE_ADDR'),
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'dev/simulate/*',
        ]);
        // Resolve tenant on every web request (including Livewire AJAX calls)
        $middleware->web(append: [
            \App\Http\Middleware\IdentifyCompany::class,
        ]);
        $middleware->alias([
            'identify.company' => \App\Http\Middleware\IdentifyCompany::class,
            'company.role' => \App\Http\Middleware\CheckCompanyRole::class,
            'super.admin' => \App\Http\Middleware\RequireSuperAdmin::class,
            'company.active' => \App\Http\Middleware\EnsureCompanyIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
