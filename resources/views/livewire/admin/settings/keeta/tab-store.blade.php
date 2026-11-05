{{-- Loja: abrir/fechar na Keeta, aceite automático e horários enviados (vêm do cadastro da filial). --}}
<x-admin.form-card padding="p-6 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="font-semibold text-neutral-800 dark:text-neutral-100">Loja na Keeta</h3>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                {{ ($data['store_open'] ?? true) ? 'Aberta nos horários de funcionamento da filial.' : 'Fechada pelo painel até você abrir de novo.' }}
                @if($data['store_updated_at'] ?? null) <span class="text-neutral-500">· alterado em {{ $data['store_updated_at'] }}</span>@endif
            </p>
        </div>
        @if(($data['status'] ?? null) === 'active')
            @if($data['store_open'] ?? true)
                <flux:button wire:click="setStoreOpen(false)" wire:target="setStoreOpen">Fechar loja agora</flux:button>
            @else
                <flux:button wire:click="setStoreOpen(true)" wire:target="setStoreOpen" variant="primary">Abrir loja</flux:button>
            @endif
        @endif
    </div>
    <p class="text-xs text-neutral-500 dark:text-neutral-400">Para fechar por um período (feriado, reforma), cadastre uma pausa na filial e marque "Fechar também na Keeta".</p>
</x-admin.form-card>

<x-admin.form-card padding="p-6 space-y-3">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="font-semibold text-neutral-800 dark:text-neutral-100">Aceite automático</h3>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                A Keeta cancela o pedido não confirmado em 5 minutos e fecha a loja por um tempo. Com o aceite automático, o VEDDI confirma assim que o pedido chega ao painel.
            </p>
        </div>
        <flux:button wire:click="toggleAutoAccept" wire:target="toggleAutoAccept" :variant="($data['auto_accept'] ?? false) ? 'ghost' : 'primary'">
            {{ ($data['auto_accept'] ?? false) ? 'Desligar aceite automático' : 'Ligar aceite automático' }}
        </flux:button>
    </div>
</x-admin.form-card>
