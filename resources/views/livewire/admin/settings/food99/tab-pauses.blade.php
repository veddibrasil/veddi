{{-- Pausas: a pausa curta vai direto pro status da loja; as da filial são replicadas pelo agendador. --}}
<x-admin.form-card padding="p-6 space-y-4">
    <div>
        <h2 class="font-semibold text-neutral-800 dark:text-neutral-100">Pausar a loja na 99Food agora</h2>
        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
            Até o fim do dia a loja volta sozinha no próximo horário de funcionamento. Loja fechada precisa ser reaberta antes de pausar.
        </p>
    </div>

    @if(($data['sub_biz_status'] ?? null) === 2)
        <x-admin.alert variant="warning" icon="pause">Loja pausada na 99Food.</x-admin.alert>
    @endif

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

    @if(($data['sub_biz_status'] ?? null) === 2)
        <flux:button wire:click="storeAction('open')" wire:target="storeAction" variant="primary">Reabrir loja</flux:button>
    @endif
</x-admin.form-card>

<x-admin.form-card title="Pausas agendadas da filial">
    <p class="mb-3 text-sm text-neutral-500 dark:text-neutral-400">
        Para pausas com início e fim, cadastre em
        <a href="{{ route('admin.branches.pauses', $branchId) }}" class="underline" wire:navigate>Filiais › Pausas e feriados</a>
        e marque "Fechar também a loja na 99Food". A 99Food não tem interrupção com horário: a loja fecha no início e reabre no fim.
    </p>

    @if($mirroredPauses)
        <ul class="divide-y divide-neutral-100 dark:divide-zinc-700">
            @foreach($mirroredPauses as $pause)
                <li class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0" wire:key="food99-pause-{{ $pause['id'] }}">
                    <div class="min-w-0">
                        <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $pause['reason'] ?: 'Pausa' }}</p>
                        <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ $pause['period'] }} · {{ $pause['state'] }}</p>
                    </div>
                    <flux:button wire:click="confirmRemovePause({{ $pause['id'] }})" size="sm" variant="ghost"
                        aria-label="Remover da 99Food a pausa {{ $pause['reason'] ?? '' }}">Remover da 99Food</flux:button>
                </li>
            @endforeach
        </ul>
    @else
        <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhuma pausa da filial agendada na 99Food.</p>
    @endif
</x-admin.form-card>
