@props(['branch', 'current'])

@php
    // Impressora, taxa de serviço e mesas só têm efeito no PDV.
    $showPdv = (bool) $branch->company?->pdv_module_enabled;

    $items = [
        'edit' => ['Dados e horários', route('admin.branches.edit', $branch), 'pencil-square'],
        'delivery' => ['Entrega', route('admin.branches.delivery', $branch), 'truck'],
        'pauses' => ['Pausas e feriados', route('admin.branches.pauses', $branch), 'calendar-days'],
    ];

    // Mesmo rótulo da sidebar: quem só enxerga a própria filial (gerente) não tem uma "lista de filiais".
    $listLabel = $branch->company && auth()->user()?->isBranchScoped($branch->company) ? 'Minha filial' : 'Filiais';

    if ($showPdv) {
        $items['printer'] = ['Impressoras', route('admin.branches.printer', $branch), 'printer'];
        $items['service-charges'] = ['Taxa de serviço e couvert', route('admin.branches.service-charges', $branch), 'currency-dollar'];
        $items['tables'] = ['Mesas e comandas', route('admin.branches.tables', $branch), 'table-cells'];
    }
@endphp

<div class="space-y-2">
    <nav aria-label="Localização">
        <ol class="flex items-center gap-1.5 text-sm text-neutral-500 dark:text-neutral-400">
            <li>
                <a href="{{ route('admin.branches.index') }}" class="rounded underline-offset-2 hover:text-neutral-800 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-500 dark:hover:text-neutral-200">{{ $listLabel }}</a>
            </li>
            <li aria-hidden="true">›</li>
            <li aria-current="page" class="truncate font-medium text-neutral-700 dark:text-neutral-200">{{ $branch->name }}</li>
        </ol>
    </nav>

    {{-- No celular a barra rola: centraliza a aba atual para ela não ficar cortada na borda. --}}
    <nav
        aria-label="Configurações da filial"
        x-data
        x-init="$nextTick(() => {
            const active = $el.querySelector('[aria-current=page]');
            if (! active) return;
            $el.scrollLeft += active.getBoundingClientRect().left - $el.getBoundingClientRect().left - ($el.clientWidth - active.clientWidth) / 2;
        })"
        class="-mx-1 overflow-x-auto border-b border-neutral-200 px-1 dark:border-zinc-700"
    >
        <ul class="flex min-w-max gap-1">
            @foreach ($items as $key => [$label, $href, $icon])
                @php $isCurrent = $key === $current; @endphp
                <li>
                    <a
                        href="{{ $href }}"
                        @if ($isCurrent) aria-current="page" @endif
                        class="-mb-px inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-500 {{ $isCurrent
                            ? 'border-amber-500 text-amber-700 dark:text-amber-400'
                            : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900 dark:text-neutral-400 dark:hover:border-zinc-500 dark:hover:text-neutral-100' }}"
                    >
                        <flux:icon :name="$icon" class="size-4" />
                        {{ $label }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
</div>
