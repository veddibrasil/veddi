<x-admin.form-card padding="p-6 space-y-4">
    <div>
        <h2 class="font-semibold text-neutral-800 dark:text-neutral-100">Fechar a loja no iFood temporariamente</h2>
        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
            Durante a pausa a loja aparece fechada no app do iFood (até 7 dias). Horários no fuso da loja.
            Para pausar também o chat e o agendamento, cadastre a pausa em Filiais › Pausas e feriados e marque “Fechar também a loja no iFood”.
        </p>
    </div>

    <flux:input wire:model="interruptionDescription" label="Motivo da pausa" maxlength="255" placeholder="Ex: falta de gás, manutenção" />
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <flux:input wire:model="interruptionStart" type="datetime-local" label="Início da pausa" />
        <flux:input wire:model="interruptionEnd" type="datetime-local" label="Fim da pausa" />
    </div>
    <div class="flex flex-wrap gap-3">
        <flux:button wire:click="createStoreInterruption" wire:target="createStoreInterruption" variant="primary">Criar pausa no iFood</flux:button>
        <flux:button wire:click="consultInterruptions" wire:target="consultInterruptions" icon="arrow-path">Consultar pausas</flux:button>
    </div>
</x-admin.form-card>

<x-admin.form-card title="Pausas ativas e agendadas">
    @if($interruptions)
        <ul class="divide-y divide-neutral-100 dark:divide-zinc-700">
            @foreach($interruptions as $interruption)
                <li class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0" wire:key="pause-{{ $interruption['id'] }}">
                    <div class="min-w-0">
                        <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $interruption['description'] ?? 'Pausa' }}</p>
                        <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ $this->interruptionPeriod($interruption) }}</p>
                    </div>
                    <flux:button wire:click="confirmRemoveInterruption(@js($interruption['id']))" size="sm" variant="ghost"
                        aria-label="Remover pausa {{ $interruption['description'] ?? '' }}">Remover pausa</flux:button>
                </li>
            @endforeach
        </ul>
    @else
        <p class="text-sm text-neutral-500 dark:text-neutral-400">
            {{ $interruptionsLoaded ? 'Nenhuma pausa ativa ou agendada nesta loja.' : 'Consulte as pausas para ver as interrupções ativas e agendadas.' }}
        </p>
    @endif
</x-admin.form-card>
