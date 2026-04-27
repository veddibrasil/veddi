<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ReservedSlug implements ValidationRule
{
    /**
     * Slugs reservados pelas rotas do sistema.
     * Nenhuma empresa pode usar estes valores como slug.
     */
    private const RESERVED = [
        // Rotas públicas
        'cadastro',
        'dev',
        'webhooks',
        'api',
        'payment',
        // Painéis
        'admin',
        'superadmin',
        'settings',
        // Autenticação (Fortify / Laravel)
        'login',
        'logout',
        'register',
        'password',
        'email',
        'two-factor',
        'user',
        'profile',
        // Comuns para prevenção
        'app',
        'www',
        'mail',
        'ftp',
        'blog',
        'support',
        'help',
        'status',
        'cdn',
        'static',
        'docs',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (in_array(strtolower($value), self::RESERVED, true)) {
            $fail('Este endereço é reservado pelo sistema e não pode ser utilizado.');
        }
    }
}
