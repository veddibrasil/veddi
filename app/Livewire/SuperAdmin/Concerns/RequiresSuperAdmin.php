<?php

namespace App\Livewire\SuperAdmin\Concerns;

/**
 * Gate em toda requisição do componente (mount e /livewire/update), não só na rota:
 * um super admin rebaixado com a aba aberta não pode continuar agindo.
 */
trait RequiresSuperAdmin
{
    public function bootRequiresSuperAdmin(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
    }
}
