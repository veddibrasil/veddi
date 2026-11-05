@php
    $currentBranch = collect($branchOptions)->firstWhere('id', $branchId);
    $data = $integrationData;
@endphp

<div class="w-full space-y-6">
    <x-admin.page-header
        :back-route="route('admin.integrations.index')"
        back-label="Voltar para integrações"
        title="Integração Keeta"
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
            Nenhuma filial cadastrada. Cadastre uma em <a href="{{ route('admin.branches.index') }}" class="underline" wire:navigate>Filiais</a> antes de conectar a Keeta.
        </x-admin.alert>
    @else
        @include('livewire.admin.settings.keeta.connection')

        @if(in_array($connectionState, ['connected', 'error'], true))
            <div class="space-y-4">
                <x-admin.section-tabs :tabs="$tabs" :current="$tab" label="Seções da integração Keeta" />

                <div wire:loading.flex wire:target="tab, branchId" class="items-center gap-2 text-sm text-neutral-500 dark:text-neutral-400">
                    <flux:icon.loading class="size-4" /> Carregando dados da Keeta…
                </div>

                <div role="tabpanel" id="tabpanel-{{ $tab }}" aria-labelledby="tab-{{ $tab }}" wire:key="keeta-panel-{{ $branchId }}-{{ $tab }}" class="space-y-4">
                    @include('livewire.admin.settings.keeta.tab-'.match($tab) {
                        'cardapio' => 'catalog',
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
                <flux:heading size="lg">Desconectar a Keeta desta filial?</flux:heading>
                <flux:subheading class="mt-1">
                    A loja é fechada na Keeta e os pedidos deixam de chegar ao painel. Para encerrar de vez, revogue também a autorização do app VEDDI no portal da Keeta.
                </flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showDisconnectModal', false)" variant="ghost">Cancelar</flux:button>
                <flux:button wire:click="disconnect" wire:target="disconnect" variant="danger">Desconectar</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showSuspendModal" class="max-w-sm">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Suspender a integração?</flux:heading>
                <flux:subheading class="mt-1">
                    A loja fica fechada na Keeta e pedidos novos não chegam. Pedidos já recebidos podem ser concluídos.
                </flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showSuspendModal', false)" variant="ghost">Cancelar</flux:button>
                <flux:button wire:click="suspend" wire:target="suspend" variant="danger">Suspender</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
