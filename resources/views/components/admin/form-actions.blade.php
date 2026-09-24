@props([
    'saveAction'   => 'save',
    'saveLabel'    => 'Salvar',
    'savingLabel'  => 'Salvando...',
    'cancelRoute'  => null,
    'cancelLabel'  => 'Cancelar',
    'canSave'      => true,
    'sticky'       => false,
])

<div class="flex flex-wrap items-center gap-3 {{ $sticky
    ? 'sticky bottom-0 z-20 -mx-1 border-t border-neutral-200 bg-[#f8f8fb]/95 px-1 py-3 backdrop-blur dark:border-zinc-700 dark:bg-[#0d1825]/95'
    : 'pb-8' }}">
    @if ($canSave)
        <flux:button
            wire:click="{{ $saveAction }}"
            class="!bg-amber-500 !text-white hover:!bg-amber-600"
            wire:loading.attr="disabled"
        >
            <span wire:loading.remove wire:target="{{ $saveAction }}">{{ $saveLabel }}</span>
            <span wire:loading wire:target="{{ $saveAction }}">{{ $savingLabel }}</span>
        </flux:button>
    @else
        <p class="text-sm text-neutral-600 dark:text-neutral-400">Somente leitura — você não tem permissão para alterar esta configuração.</p>
    @endif

    @if ($cancelRoute)
        <a
            href="{{ $cancelRoute }}"
            class="inline-flex items-center rounded px-4 py-2 text-sm text-neutral-600 hover:text-neutral-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-500 dark:text-neutral-400 dark:hover:text-neutral-200"
        >
            {{ $cancelLabel }}
        </a>
    @endif
</div>
