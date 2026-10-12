@props(['tabs', 'current', 'model' => 'tab', 'label' => 'Seções'])

{{--
    Abas dentro de uma mesma tela Livewire (troca a propriedade $model sem navegar).
    Mesmo visual do x-admin.branch-nav; no celular a barra rola e centraliza a aba atual.
    $tabs: ['chave' => 'Rótulo'] ou ['chave' => ['label' => 'Rótulo', 'badge' => 3]].
--}}
<nav
    aria-label="{{ $label }}"
    x-data
    x-init="$nextTick(() => {
        const active = $el.querySelector('[aria-selected=true]');
        if (! active) return;
        $el.scrollLeft += active.getBoundingClientRect().left - $el.getBoundingClientRect().left - ($el.clientWidth - active.clientWidth) / 2;
    })"
    {{ $attributes->merge(['class' => '-mx-1 overflow-x-auto overflow-y-hidden border-b border-neutral-200 px-1 dark:border-zinc-700']) }}
>
    <div role="tablist" class="flex min-w-max gap-1">
        @foreach ($tabs as $key => $tab)
            @php
                $tabLabel = is_array($tab) ? $tab['label'] : $tab;
                $badge = is_array($tab) ? ($tab['badge'] ?? null) : null;
                $isCurrent = $key === $current;
            @endphp
            <button
                type="button"
                role="tab"
                id="tab-{{ $key }}"
                aria-controls="tabpanel-{{ $key }}"
                aria-selected="{{ $isCurrent ? 'true' : 'false' }}"
                wire:click="$set('{{ $model }}', '{{ $key }}')"
                class="inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-amber-500 {{ $isCurrent
                    ? 'border-amber-500 text-amber-700 dark:text-amber-400'
                    : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900 dark:text-neutral-400 dark:hover:border-zinc-500 dark:hover:text-neutral-100' }}"
            >
                {{ $tabLabel }}
                @if ($badge)
                    <span class="rounded-full bg-amber-100 px-1.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">{{ $badge }}</span>
                @endif
            </button>
        @endforeach
    </div>
</nav>
