<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    protected function context(): array
    {
        $context = parent::context();

        if (app()->bound('request')) {
            $request = request();
            $context['url'] = $request->fullUrl();
            $context['method'] = $request->method();
            $context['ip'] = $request->ip();
            $context['input'] = $request->except(['password', 'password_confirmation', 'token']);
        }

        try {
            if (auth()->check()) {
                $user = auth()->user();
                $context['user_id'] = $user->id;
                $context['user_email'] = $user->email ?? null;
            }
        } catch (\Throwable) {
            // Guard not available during early boot exceptions
        }

        $context['environment'] = app()->environment();

        return $context;
    }
}
