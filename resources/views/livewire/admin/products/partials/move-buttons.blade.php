{{-- Alternativa ao arrastar (teclado/toque) no modo reordenar. Espera: $method, $id, $label, $first, $last. --}}
<button type="button" wire:click="{{ $method }}({{ $id }}, 'up')" @disabled($first)
    aria-label="Subir {{ $label }}"
    class="inline-flex items-center justify-center p-2 rounded text-neutral-500 hover:text-amber-600 hover:bg-amber-50 disabled:opacity-30 disabled:pointer-events-none dark:text-neutral-400 dark:hover:bg-amber-900/20 transition-colors">
    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" /></svg>
</button>
<button type="button" wire:click="{{ $method }}({{ $id }}, 'down')" @disabled($last)
    aria-label="Descer {{ $label }}"
    class="inline-flex items-center justify-center p-2 rounded text-neutral-500 hover:text-amber-600 hover:bg-amber-50 disabled:opacity-30 disabled:pointer-events-none dark:text-neutral-400 dark:hover:bg-amber-900/20 transition-colors">
    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
</button>
