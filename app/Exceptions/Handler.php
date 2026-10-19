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
            // senha de certificado, token, CPF e telefone de cliente iriam pro log e pro
            // Nightwatch. Fica só o que ajuda a reproduzir — componente, campos e métodos.
            $context['input'] = $request->hasHeader('X-Livewire')
                ? $this->livewireSummary($request->input('components'))
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

    /** Payload vem do cliente: qualquer formato, nunca pode derrubar o log da exceção. */
    private function livewireSummary(mixed $components): array
    {
        if (! is_array($components)) {
            return [];
        }

        return array_values(array_map(function (mixed $component): array {
            $component = is_array($component) ? $component : [];
            $snapshot = is_string($component['snapshot'] ?? null) ? json_decode($component['snapshot'], true) : null;

            return [
                'component' => is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null,
                'updates' => is_array($component['updates'] ?? null) ? array_keys($component['updates']) : [],
                'calls' => is_array($component['calls'] ?? null)
                    ? array_values(array_filter(array_map(fn ($call) => is_array($call) ? ($call['method'] ?? null) : null, $component['calls'])))
                    : [],
            ];
        }, $components));
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
