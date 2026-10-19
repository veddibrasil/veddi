<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    /** Chave com qualquer um destes trechos tem o valor trocado por [redacted] no log, em qualquer nível. */
    private const SENSITIVE_KEY_PATTERN = '/pass|senha|token|secret|key|pin|code|csc|cvv|card|cart|cpf|cnpj|document|phone|telefone|whatsapp|authorization/i';

    protected function context(): array
    {
        $context = parent::context();

        if (app()->bound('request')) {
            $request = request();
            $context['url'] = $request->fullUrl();
            $context['method'] = $request->method();
            $context['ip'] = $request->ip();

            // Requisição Livewire carrega o estado inteiro do componente (snapshot + updates):
            // senha de certificado, token, código de verificação, CPF e telefone de cliente
            // iriam pro log e pro Nightwatch — nada do payload é registrado.
            $context['input'] = ($request->hasHeader('X-Livewire') || $request->routeIs('*livewire.update'))
                ? ['livewire' => '[redacted]']
                : $this->redact($request->all());
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

    private function redact(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
                $input[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $input[$key] = $this->redact($value);
            }
        }

        return $input;
    }
}
