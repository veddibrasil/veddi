<?php

namespace App\Livewire\Admin\Concerns;

/**
 * Tela exclusiva do company_admin, checada em toda requisição do componente (mount e
 * /livewire/update), não só na rota: um admin rebaixado com a aba aberta não pode continuar
 * agindo. Gerente, papel customizado e super admin (que tem painel próprio) levam 403.
 */
trait RequiresCompanyAdmin
{
    public function bootRequiresCompanyAdmin(): void
    {
        $company = app()->bound('current.company') ? app('current.company') : null;

        abort_unless($company && auth()->user()?->isCompanyAdmin($company), 403);
    }
}
