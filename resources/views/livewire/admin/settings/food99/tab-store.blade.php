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

    <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Abertura</dt>
            <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ match($data['auto_switch'] ?? null) { 1 => 'Manual', 2 => 'Automática, nos horários da loja', default => 'Não informada' } }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Preparo médio</dt>
            <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ ($data['prepare_minutes'] ?? null) ? $data['prepare_minutes'].' min' : 'Não informado' }}</dd>
        </div>
    </dl>

    <p class="text-xs text-neutral-500 dark:text-neutral-400">A 99Food aceita uma mudança de status por minuto e confirma em instantes. Fechar a loja vale até reabrir manualmente, mesmo nos dias seguintes (aqui ou no app da 99Food). Para sair só por hoje, use a aba Pausas.</p>

    <div class="flex flex-wrap gap-2">
        <flux:button wire:click="storeAction('open')" wire:target="storeAction" variant="primary">Abrir loja</flux:button>
        <flux:button wire:click="storeAction('close')" wire:target="storeAction" variant="danger">Fechar loja</flux:button>
        <flux:button wire:click="storeAction('revoke')" wire:target="storeAction">Desfazer fechamento</flux:button>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 sm:items-end">
        <flux:input type="number" min="1" max="180" wire:model="busyMinutes" label="Modo ocupado por (min)" />
        <flux:input type="number" min="1" max="30" wire:model="busyDelay" label="Atraso no preparo (min)" />
        @if($data['busy'] ?? false)
            <flux:button wire:click="storeAction('busy-off')" wire:target="storeAction">Encerrar modo ocupado</flux:button>
        @else
            <flux:button wire:click="storeAction('busy')" wire:target="storeAction">Ativar modo ocupado</flux:button>
        @endif
    </div>
</x-admin.form-card>
