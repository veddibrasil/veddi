<?php

namespace App\Livewire\Admin\Pdv;

use Livewire\Component;

/** Landing do PDV: escolhe entre venda direta (Terminal) e mesa/comanda (TabTerminal). */
class Selector extends Component
{
    public function mount(): void
    {
        $company = app()->bound('current.company') ? app('current.company') : null;
        $user = auth()->user();

        abort_unless($company, 403);
        abort_unless($company->pdv_module_enabled, 403, 'Módulo PDV não está habilitado para esta empresa.');

        $canFullyOperate = (bool) $user?->hasPermission('pdv.operate', $company);
        $canWaiterOperate = (bool) $user?->hasPermission('pdv.waiter_operate', $company)
            && $company->waiter_module_enabled;

        $canSell = (bool) $user?->hasPermission('pdv.sell', $company);

        abort_unless($canFullyOperate || $canWaiterOperate || $canSell, 403, 'Módulo Garçom não está habilitado para esta empresa.');

        // Só venda direta (ex.: cozinha): a única opção é o Terminal, então vai direto pra ele.
        if (! $canFullyOperate && ! $canWaiterOperate && $canSell) {
            $this->redirect(route('admin.pdv.checkout'));

            return;
        }

        // Garçom só tem uma opção válida — não faz sentido mostrar o seletor pra ele.
        if (! $canFullyOperate && $canWaiterOperate) {
            $this->redirect(route('admin.pdv.tabs'));
        }
    }

    public function render()
    {
        return view('livewire.admin.pdv.selector')
            ->layout('layouts.app.pdv');
    }
}
