{{-- Horários: salvar substitui a semana inteira na 99Food (1 alteração por minuto). --}}
<x-admin.form-card padding="p-6 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-semibold text-neutral-800 dark:text-neutral-100">Horários de funcionamento na 99Food</h2>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                Salvar substitui a semana inteira na 99Food; dia sem turno fica fechado. Turno que passa da meia-noite precisa ser dividido em dois (até 23:59 e a partir de 00:00).
            </p>
        </div>
        <flux:button size="sm" icon="arrow-path" wire:click="refreshStore" wire:target="refreshStore">Consultar horários</flux:button>
    </div>

    <div class="divide-y divide-neutral-100 dark:divide-zinc-700">
        @foreach($weekDays as $day => $dayLabel)
            @php $dayShifts = collect($openingShifts)->filter(fn ($shift) => (int) $shift['day'] === $day); @endphp
            <div class="flex flex-col gap-3 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-start" wire:key="food99-hours-{{ $day }}">
                <div class="w-24 shrink-0 pt-2 text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ $dayLabel }}</div>
                <div class="flex flex-1 flex-wrap items-end gap-3">
                    @forelse($dayShifts as $index => $shift)
                        <div class="flex items-end gap-2 rounded-lg border border-neutral-200 p-2 dark:border-zinc-700" wire:key="food99-shift-{{ $index }}">
                            <flux:input wire:model="openingShifts.{{ $index }}.start" type="time" size="sm" class="w-28" label="Abre" />
                            <flux:input wire:model="openingShifts.{{ $index }}.end" type="time" size="sm" class="w-28" label="Fecha" />
                            <flux:button wire:click="removeOpeningShift({{ $index }})" size="sm" variant="ghost" icon="x-mark"
                                aria-label="Remover turno de {{ $dayLabel }} das {{ $shift['start'] }}" />
                        </div>
                    @empty
                        <span class="pt-2 text-sm text-neutral-500 dark:text-neutral-400">Fechado</span>
                    @endforelse
                    <flux:button wire:click="addOpeningShift({{ $day }})" size="sm" variant="ghost" icon="plus" aria-label="Adicionar turno em {{ $dayLabel }}">Turno</flux:button>
                </div>
            </div>
        @endforeach
    </div>

    <div class="flex flex-wrap gap-3 border-t border-neutral-100 pt-4 dark:border-zinc-700">
        <flux:button wire:click="fillHoursFromBranch" wire:target="fillHoursFromBranch" icon="arrow-down-tray">Usar horários da filial</flux:button>
        <flux:button wire:click="saveOpeningHours" wire:target="saveOpeningHours" variant="primary">Salvar horários na 99Food</flux:button>
    </div>
</x-admin.form-card>
