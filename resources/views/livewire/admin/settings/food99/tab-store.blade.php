{{-- Status da loja na 99Food: abrir, pausar, fechar, reabrir e modo ocupado (1 ação por minuto). --}}
<x-admin.form-card padding="p-6 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="font-semibold text-neutral-800 dark:text-neutral-100">Status da loja</h3>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                {{ $data['store_label'] ?? 'Ainda sem status da 99Food.' }}
                @if($data['store_updated_at'] ?? null) <span class="text-xs text-neutral-500">· atualizado {{ $data['store_updated_at'] }}</span> @endif
            </p>
        </div>
        <flux:button size="sm" wire:click="refreshStore" wire:target="refreshStore" icon="arrow-path">Atualizar</flux:button>
    </div>

    <p class="text-xs text-neutral-500 dark:text-neutral-400">A 99Food aceita uma mudança de status por minuto e confirma em instantes. Fechar a loja só reabre manualmente (aqui ou no app da 99Food).</p>

    <div class="flex flex-wrap gap-2">
        <flux:button wire:click="storeAction('open')" wire:target="storeAction" variant="primary">Abrir loja</flux:button>
        <flux:button wire:click="storeAction('close')" wire:target="storeAction" variant="danger">Fechar hoje</flux:button>
        <flux:button wire:click="storeAction('revoke')" wire:target="storeAction">Desfazer fechamento</flux:button>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 sm:items-end">
        <flux:select wire:model="pauseTime" label="Pausar por">
            @foreach($pauseTimes as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model="pauseReason" label="Motivo">
            @foreach($pauseReasons as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button wire:click="storeAction('pause')" wire:target="storeAction">Pausar loja</flux:button>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 sm:items-end">
        <flux:input type="number" min="1" max="180" wire:model="busyMinutes" label="Modo ocupado por (min)" />
        <flux:input type="number" min="1" max="30" wire:model="busyDelay" label="Atraso no preparo (min)" />
        <flux:button wire:click="storeAction('busy')" wire:target="storeAction">Ativar modo ocupado</flux:button>
    </div>

    <p class="text-xs text-neutral-500 dark:text-neutral-400">
        Pausas e feriados da filial também podem fechar a loja na 99Food (Filiais › Pausas). Diferente do iFood, a 99Food não tem interrupção com horário de início e fim: pausas de até 30 minutos ou até o fim do dia viram "pausar"; as maiores fecham no início e reabrem no fim.
    </p>
</x-admin.form-card>
