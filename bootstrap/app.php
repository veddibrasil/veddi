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
        $csrfExcept = ['webhooks/*'];
        if (filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN)) {
            $csrfExcept[] = 'dev/simulate/*';
        }
        $middleware->validateCsrfTokens(except: $csrfExcept);
        // Resolve tenant on every web request (including Livewire AJAX calls)
        $middleware->web(append: [
            \App\Http\Middleware\IdentifyCompany::class,
        ]);
        // Sem isso, IdentifyCompany rodava DEPOIS de SubstituteBindings (registro no grupo
        // 'web' só garante posição relativa, não prioridade de execução) — toda rota com
        // model binding implícito (ex.: {product}, {branch}) resolvia o model sem
        // CompanyScope, porque app('current.company') ainda não existia. Isso permitia
        // IDOR cross-tenant: um company_admin de uma empresa acessando/editando registro
        // de outra só trocando o ID na URL. current.company precisa existir antes do bind.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\IdentifyCompany::class,
        );
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
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
