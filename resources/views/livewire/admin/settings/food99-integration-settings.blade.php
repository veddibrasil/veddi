@php
    $currentBranch = collect($branchOptions)->firstWhere('id', $branchId);
    $data = $integrationData;
    $tabItems = $tabs;
    if ($mirroredPauses !== []) {
        $tabItems['pausas'] = ['label' => $tabs['pausas'], 'badge' => count($mirroredPauses)];
    }
@endphp

<div class="w-full space-y-6">
    <x-admin.page-header
        :back-route="route('admin.integrations.index')"
        back-label="Voltar para integrações"
        title="Integração 99Food"
    >
        @if(count($branchOptions) > 1)
            <x-slot:actions>
                <div class="w-full sm:w-72">
                    <flux:select wire:model.live="branchId" aria-label="Filial">
                        @foreach($branchOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }} · {{ $option['state'] }}</option>
                        @endforeach
                    </flux:select>
                </div>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <x-admin.flash-status />
    <x-admin.flash-error />

    @if(empty($branchOptions))
        <x-admin.alert variant="warning" icon="exclamation-triangle">
            Nenhuma filial cadastrada. Cadastre uma em <a href="{{ route('admin.branches.index') }}" class="underline" wire:navigate>Filiais</a> antes de conectar a 99Food.
        </x-admin.alert>
    @else
        @include('livewire.admin.settings.food99.connection')

        @if(in_array($connectionState, ['connected', 'error'], true))
            <div class="space-y-4">
                <x-admin.section-tabs :tabs="$tabItems" :current="$tab" label="Seções da integração 99Food" />

                <div wire:loading.flex wire:target="tab, branchId" class="items-center gap-2 text-sm text-neutral-500 dark:text-neutral-400">
                    <flux:icon.loading class="size-4" /> Carregando dados da 99Food…
                </div>

                <div role="tabpanel" id="tabpanel-{{ $tab }}" aria-labelledby="tab-{{ $tab }}" wire:key="food99-panel-{{ $branchId }}-{{ $tab }}" class="space-y-4">
                    @include('livewire.admin.settings.food99.tab-'.match($tab) {
                        'pausas' => 'pauses',
                        'horarios' => 'hours',
                        'cardapio' => 'catalog',
                        'pedidos' => 'orders',
                        'diagnostico' => 'diagnostics',
                        default => 'store',
                    })
                </div>
            </div>
        @endif
    @endif

    <flux:modal wire:model.self="showDisconnectModal" class="max-w-sm">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Desconectar a 99Food desta filial?</flux:heading>
                <flux:subheading class="mt-1">
                    O vínculo da loja com o VEDDI é desfeito na 99Food e os pedidos deixam de chegar ao painel. Para voltar, será preciso autorizar e informar a loja de novo.
                </flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showDisconnectModal', false)" variant="ghost">Cancelar</flux:button>
                <flux:button wire:click="disconnect" wire:target="disconnect" variant="danger">Desconectar</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showRemovePauseModal" class="max-w-sm">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Remover esta pausa da 99Food?</flux:heading>
                <flux:subheading class="mt-1">A loja volta a abrir na 99Food no horário de funcionamento. A pausa continua cadastrada na filial e valendo para o chat.</flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showRemovePauseModal', false)" variant="ghost">Cancelar</flux:button>
                <flux:button wire:click="removePauseFrom99" wire:target="removePauseFrom99" variant="danger">Remover da 99Food</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showSuspendModal" class="max-w-sm">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Suspender a integração?</flux:heading>
                <flux:subheading class="mt-1">
                    A loja fica fechada na 99Food e pedidos novos não chegam. Pedidos já recebidos podem ser concluídos. Para fechar só por algumas horas, prefira a aba Pausas.
                </flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showSuspendModal', false)" variant="ghost">Cancelar</flux:button>
                <flux:button wire:click="suspend" wire:target="suspend" variant="danger">Suspender</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
