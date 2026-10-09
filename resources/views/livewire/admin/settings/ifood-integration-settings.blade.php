@php
    $currentBranch = collect($branchOptions)->firstWhere('id', $branchId);
    $interruptionCount = count($interruptions);
    $tabItems = $tabs;
    if ($interruptionCount > 0) {
        $tabItems['pausas'] = ['label' => $tabs['pausas'], 'badge' => $interruptionCount];
    }
@endphp

<div class="w-full space-y-6"
    x-init="$watch(() => $wire.confirmingInterruptionId, id => id ? $flux.modal('confirm-remove-ifood-pause').show() : $flux.modal('confirm-remove-ifood-pause').close())">
    <x-admin.page-header
        :back-route="route('admin.integrations.index')"
        back-label="Voltar para integrações"
        title="Integração iFood"
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
            Nenhuma filial cadastrada. Cadastre uma em <a href="{{ route('admin.branches.index') }}" class="underline" wire:navigate>Filiais</a> antes de conectar o iFood.
        </x-admin.alert>
    @else
        @error('branchId') <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

        @include('livewire.admin.settings.ifood.connection', ['currentBranch' => $currentBranch])

        @if($connectionState === 'connected')
            <div class="space-y-4">
                <x-admin.section-tabs :tabs="$tabItems" :current="$tab" label="Seções da integração iFood" />

                @error('merchantOperation')
                    <x-admin.alert variant="danger" icon="exclamation-triangle" role="alert">
                        {{ $message }}
                    </x-admin.alert>
                @enderror

                <div wire:loading.flex wire:target="tab, loadCurrentTab, branchId" class="items-center gap-2 text-sm text-neutral-500 dark:text-neutral-400">
                    <flux:icon.loading class="size-4" /> Carregando dados do iFood…
                </div>

                <div role="tabpanel" id="tabpanel-{{ $tab }}" aria-labelledby="tab-{{ $tab }}" wire:key="ifood-panel-{{ $branchId }}-{{ $tab }}-{{ $panelVersion }}" wire:init="loadCurrentTab" class="space-y-4">
                    @include('livewire.admin.settings.ifood.tab-'.match($tab) {
                        'pausas' => 'pauses',
                        'horarios' => 'hours',
                        'cardapio' => 'catalog',
                        'diagnostico' => 'diagnostics',
                        default => 'store',
                    })
                </div>
            </div>
        @endif
    @endif

    {{-- Modal: desconectar --}}
    <flux:modal wire:model.self="showDisconnectModal" class="max-w-sm">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Desconectar o iFood desta filial?</flux:heading>
                <flux:subheading class="mt-1">
                    Os pedidos do iFood deixam de chegar ao painel e a loja fica fechada no app do iFood. Para voltar, será preciso conectar e aprovar o acesso de novo no iFood.
                </flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showDisconnectModal', false)" variant="ghost">Cancelar</flux:button>
                <flux:button wire:click="disconnect" wire:target="disconnect" variant="danger">Desconectar</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Modal: suspender --}}
    <flux:modal wire:model.self="showSuspendModal" class="max-w-sm">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Suspender a integração?</flux:heading>
                <flux:subheading class="mt-1">
                    Enquanto estiver suspensa, a loja fica fechada no iFood e pedidos novos não chegam. Pedidos já recebidos podem ser concluídos normalmente. Para fechar só por algumas horas, prefira uma pausa na aba Pausas.
                </flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showSuspendModal', false)" variant="ghost">Cancelar</flux:button>
                <flux:button wire:click="pause" wire:target="pause" variant="danger">Suspender</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Modal: remover pausa do iFood --}}
    {{-- Fechar (Esc, fora ou Cancelar) só limpa a escolha no navegador: um request a mais consumiria o aviso de sucesso. --}}
    <flux:modal name="confirm-remove-ifood-pause" class="max-w-sm" x-on:close="if ($wire.confirmingInterruptionId) $wire.confirmingInterruptionId = null">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Remover esta pausa?</flux:heading>
                <flux:subheading class="mt-1">A loja volta a abrir no iFood no horário de funcionamento normal.</flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button x-on:click="$flux.modal('confirm-remove-ifood-pause').close()" variant="ghost">Cancelar</flux:button>
                @if($confirmingInterruptionId)
                    <flux:button wire:click="removeStoreInterruption(@js($confirmingInterruptionId))" wire:target="removeStoreInterruption" variant="danger">Remover pausa</flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
</div>
