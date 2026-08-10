@props(['dayIndex', 'dayLabel', 'isActive', 'slots' => []])

<div class="flex flex-col sm:flex-row sm:items-start gap-3 py-3 first:pt-0 last:pb-0" x-data="{ adding: false }">
    <div class="w-28 shrink-0 pt-2 text-sm font-medium text-neutral-600 dark:text-neutral-300">
        {{ $dayLabel }}
    </div>

    <div class="flex-1 flex flex-wrap items-center gap-2 {{ $isActive ? '' : 'opacity-40 pointer-events-none' }}">
        @foreach ($slots as $index => $slot)
            <span class="inline-flex items-center gap-1.5 rounded-full border border-neutral-200 dark:border-zinc-600 bg-neutral-50 dark:bg-zinc-800 px-3 py-1 text-xs text-neutral-700 dark:text-neutral-200">
                {{ $slot['opens_at'] }} - {{ $slot['closes_at'] }}
                <button
                    type="button"
                    wire:click="removeSchedulingSlot({{ $dayIndex }}, {{ $index }})"
                    class="text-neutral-400 hover:text-red-500"
                    aria-label="Remover horário"
                >&times;</button>
            </span>
        @endforeach

        <button
            type="button"
            x-show="! adding"
            x-on:click="adding = true"
            class="inline-flex items-center gap-1 rounded-full border border-dashed border-neutral-300 dark:border-zinc-600 px-3 py-1 text-xs text-neutral-500 dark:text-neutral-400 hover:border-neutral-400"
        >
            + Horário
        </button>

        <div x-show="adding" x-cloak class="inline-flex items-end gap-2">
            <flux:input wire:model="newSlotOpensAt.{{ $dayIndex }}" type="time" size="sm" class="w-28" />
            <span class="text-neutral-400 dark:text-neutral-500 text-sm mb-2">até</span>
            <flux:input wire:model="newSlotClosesAt.{{ $dayIndex }}" type="time" size="sm" class="w-28" />
            <button
                type="button"
                wire:click="addSchedulingSlot({{ $dayIndex }})"
                x-on:click="adding = false"
                class="text-xs font-medium text-primary-600 dark:text-primary-400 mb-2"
            >Adicionar</button>
            <button
                type="button"
                x-on:click="adding = false"
                class="text-xs text-neutral-400 mb-2"
            >Cancelar</button>
        </div>
    </div>

    @error("newSlotOpensAt.{$dayIndex}")
        <p class="text-red-500 text-xs mt-1 w-full">{{ $message }}</p>
    @enderror

    @error("newSlotClosesAt.{$dayIndex}")
        <p class="text-red-500 text-xs mt-1 w-full">{{ $message }}</p>
    @enderror
</div>
