<?php

namespace App\Livewire\Admin\Integrations;

use App\Models\Branch;
use App\Models\Food99Integration;
use App\Models\IfoodIntegration;
use Livewire\Component;

class Index extends Component
{
    /** @var array<int, array{key: string, name: string, description: string, icon: string, state: string, status: string, route: string|null}> */
    public array $integrations = [];

    public function mount(): void
    {
        $company = app('current.company');

        abort_unless($company->canUseMarketplaceIntegrations(), 403, 'Módulo de Integrações não está habilitado para esta empresa.');

        // Pausada ou aguardando reconexão continua sendo uma filial ligada ao iFood: o card mostra
        // que precisa de atenção em vez de "Não conectado".
        $integrations = IfoodIntegration::where('company_id', $company->id)
            ->whereNotNull('merchant_id')
            ->get(['status']);
        $connected = $integrations->where('status', 'active')->count();
        $attention = $integrations->whereIn('status', ['paused', 'reauthorization_required'])->count();
        $branches = Branch::where('company_id', $company->id)->count();

        $this->integrations = [
            [
                'key' => 'ifood',
                'name' => 'iFood',
                'description' => 'Receba pedidos do iFood direto no painel, com cardápio e status sincronizados automaticamente.',
                'icon' => 'link',
                'state' => match (true) {
                    $attention > 0 => 'attention',
                    $connected > 0 => 'connected',
                    default => 'disconnected',
                },
                'status' => match (true) {
                    $attention > 0 => $attention === 1 ? '1 filial precisa de atenção' : "{$attention} filiais precisam de atenção",
                    $connected > 0 => "{$connected} de {$branches} ".($branches === 1 ? 'filial conectada' : 'filiais conectadas'),
                    default => 'Nenhuma filial conectada',
                },
                'route' => route('admin.settings.ifood'),
            ],
        ];

        if (config('food99.enabled')) {
            $this->integrations[] = $this->food99Card($company->id, $branches);
        }
    }

    private function food99Card(int $companyId, int $branches): array
    {
        $integrations = Food99Integration::where('company_id', $companyId)->whereNotNull('shop_id')->get(['status']);
        $connected = $integrations->where('status', Food99Integration::STATUS_ACTIVE)->count();
        $attention = $integrations->whereIn('status', [Food99Integration::STATUS_PAUSED, Food99Integration::STATUS_ERROR])->count();

        return [
            'key' => '99food',
            'name' => '99Food',
            'description' => 'Receba pedidos da 99Food no painel, aceite em até 5 minutos e mantenha cardápio e loja sincronizados.',
            'icon' => 'link',
            'state' => match (true) {
                $attention > 0 => 'attention',
                $connected > 0 => 'connected',
                default => 'disconnected',
            },
            'status' => match (true) {
                $attention > 0 => $attention === 1 ? '1 filial precisa de atenção' : "{$attention} filiais precisam de atenção",
                $connected > 0 => "{$connected} de {$branches} ".($branches === 1 ? 'filial conectada' : 'filiais conectadas'),
                default => 'Nenhuma filial conectada',
            },
            'route' => route('admin.settings.99food'),
        ];
    }

    public function render()
    {
        return view('livewire.admin.integrations.index')
            ->layout('layouts.app', ['title' => 'Integrações']);
    }
}
