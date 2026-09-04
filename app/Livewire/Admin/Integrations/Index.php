<?php

namespace App\Livewire\Admin\Integrations;

use App\Models\IfoodIntegration;
use Livewire\Component;

class Index extends Component
{
    /** @var array<int, array{key: string, name: string, description: string, icon: string, status: string, route: string|null}> */
    public array $integrations = [];

    public function mount(): void
    {
        $company = app('current.company');

        $connectedBranches = IfoodIntegration::where('company_id', $company->id)
            ->where('status', 'active')
            ->whereNotNull('merchant_id')
            ->count();

        $this->integrations = [
            [
                'key' => 'ifood',
                'name' => 'iFood',
                'description' => 'Receba pedidos do iFood direto no painel, com cardápio e status sincronizados automaticamente.',
                'icon' => 'link',
                'status' => $connectedBranches > 0
                    ? "{$connectedBranches} ".($connectedBranches === 1 ? 'filial conectada' : 'filiais conectadas')
                    : 'Não conectado',
                'connected' => $connectedBranches > 0,
                'route' => route('admin.settings.ifood'),
            ],
        ];
    }

    public function render()
    {
        return view('livewire.admin.integrations.index')
            ->layout('layouts.app', ['title' => 'Integrações']);
    }
}
