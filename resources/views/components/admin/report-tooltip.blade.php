@props(['heading', 'description' => null, 'rows' => [], 'note' => null, 'label' => null, 'triggerClass' => '', 'tooltipKey' => null])
{{-- Mantém a associação acessível estável durante os updates do Livewire. --}}
@php $tooltipId = 'report-tooltip-'.hash('xxh128', $tooltipKey ?? $heading); @endphp

<div x-data="reportTooltip" wire:key="{{ $tooltipId }}" {{ $attributes->class('min-w-0') }} data-report-tooltip
    @pointerenter="if ($event.pointerType === 'mouse') show()" @pointerleave="if ($event.pointerType === 'mouse') closeSoon()"
    @focusout="pointerActivation = false; if (! $el.contains($event.relatedTarget) && ! $el.matches(':hover')) hide()"
    @resize.window="if (open) position()" @scroll.window.capture="if (open && ! $refs.panel.contains($event.target)) hide()">
    <button x-ref="trigger" type="button" aria-label="{{ $label ?? 'Detalhes de '.$heading }}" aria-describedby="{{ $tooltipId }}" aria-controls="{{ $tooltipId }}" :aria-expanded="open.toString()" popovertarget="{{ $tooltipId }}"
        @pointerdown="pointerActivation = true" @pointercancel="pointerActivation = false" @click="queueMicrotask(() => pointerActivation = false)"
        @focus="if (!pointerActivation) show()"
        class="group w-full cursor-help text-left outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-zinc-800 {{ $triggerClass }}">
        {{ $slot }}
    </button>
    <div x-ref="panel" id="{{ $tooltipId }}" popover="auto" role="tooltip" @beforetoggle="if ($event.newState === 'open') $el.style.visibility = 'hidden'" @toggle="open = $event.newState === 'open'; if (open) position()"
        class="fixed inset-auto z-50 m-0 w-72 max-w-[calc(100vw-2rem)] overflow-y-auto whitespace-normal break-words rounded-md border border-white/10 bg-zinc-800 p-3 text-left text-xs font-medium text-white shadow-lg dark:bg-zinc-700">
        <p class="font-semibold text-white">{{ $heading }}</p>
        @if ($description)
            <p class="mt-1 leading-relaxed text-zinc-200">{{ $description }}</p>
        @endif
        @if ($rows)
            <dl class="mt-2 space-y-1.5 border-t border-white/15 pt-2">
                @foreach ($rows as $name => $value)
                    <div class="flex items-start justify-between gap-3">
                        <dt class="min-w-0 text-zinc-300">{{ $name }}</dt>
                        <dd class="shrink-0 text-right font-semibold tabular-nums text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
        @if ($note)
            <p class="mt-2 border-t border-white/15 pt-2 leading-relaxed text-zinc-300">{{ $note }}</p>
        @endif
    </div>
</div>
