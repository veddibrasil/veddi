@props(['dayIndex', 'dayLabel', 'isActive'])

<div class="flex flex-col sm:flex-row sm:items-center gap-3 py-3 first:pt-0 last:pb-0">
    <div class="w-28 shrink-0">
        <flux:checkbox wire:model.live="available_days" value="{{ $dayIndex }}" label="{{ $dayLabel }}" />
    </div>

    <div class="flex items-center gap-2 {{ $isActive ? '' : 'opacity-40 pointer-events-none' }}">
        <flux:input
            wire:model="day_hours.{{ $dayIndex }}.opens_at"
            type="time"
            label="Abre"
            :disabled="! $isActive"
            class="w-32"
        />
        <span class="text-neutral-400 dark:text-neutral-500 text-sm mt-5">até</span>
        <flux:input
            wire:model="day_hours.{{ $dayIndex }}.closes_at"
            type="time"
            label="Fecha"
            :disabled="! $isActive"
            class="w-32"
        />
    </div>

    @error("day_hours.{$dayIndex}.opens_at")
        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
    @enderror

    @error("day_hours.{$dayIndex}.closes_at")
        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
    @enderror
</div>
