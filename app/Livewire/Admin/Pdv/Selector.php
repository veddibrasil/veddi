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
        $canWaiterOperate = (bool) $user?->hasPermission('pdv.waiter_operate', $company);

        abort_unless($canFullyOperate || $canWaiterOperate, 403);

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
