@props([
    'saveAction'   => 'save',
    'saveLabel'    => 'Salvar',
    'savingLabel'  => 'Salvando...',
    'cancelRoute',
])

<div class="flex gap-3 pb-8">
    <flux:button
        wire:click="{{ $saveAction }}"
        class="!bg-amber-500 !text-white hover:!bg-amber-600"
        wire:loading.attr="disabled"
    >
        <span wire:loading.remove wire:target="{{ $saveAction }}">{{ $saveLabel }}</span>
        <span wire:loading wire:target="{{ $saveAction }}">{{ $savingLabel }}</span>
    </flux:button>

    <a
        href="{{ $cancelRoute }}"
        class="inline-flex items-center px-4 py-2 text-sm text-neutral-600 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200"
    >
        Cancelar
    </a>
</div>
