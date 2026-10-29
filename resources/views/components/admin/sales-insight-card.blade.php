@props(['insight', 'inlineDetail' => false, 'showDetail' => true, 'reportParams' => []])
@php
    $priority = match ($insight->priority) {
        1 => ['label' => 'Agir agora', 'classes' => 'border-red-200 dark:border-red-900/60', 'badge' => 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300'],
        2 => ['label' => 'Oportunidade', 'classes' => 'border-amber-200 dark:border-amber-900/60', 'badge' => 'bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300'],
        default => ['label' => 'Ideia', 'classes' => 'border-blue-200 dark:border-blue-900/60', 'badge' => 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'],
    };
@endphp
<article class="min-w-0 rounded-lg border p-4 {{ $priority['classes'] }}" data-sales-insight="{{ $insight->type }}">
    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $priority['badge'] }}">{{ $priority['label'] }}</span>
    <h3 class="mt-2 break-words text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $insight->title }}</h3>
    <p class="mt-1 break-words text-sm text-neutral-600 dark:text-neutral-300">{{ $insight->message }}</p>
    @if ($insight->evidence)
        <ul class="mt-2 flex flex-wrap gap-1.5" aria-label="Dados que embasam a sugestão">
            @foreach ($insight->evidence as $evidence)
                <li class="rounded-full bg-neutral-100 px-2 py-1 text-xs text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300">{{ $evidence }}</li>
            @endforeach
        </ul>
    @endif
    <div class="mt-3 flex flex-wrap gap-2">
        @foreach ($insight->actions as $action)
            <a href="{{ route($action['route'], $action['params']) }}" wire:navigate
               class="inline-flex min-h-11 items-center justify-center rounded-lg border border-amber-300 px-3 py-2 text-xs font-medium text-amber-800 transition-colors hover:bg-amber-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 dark:border-amber-700 dark:text-amber-300 dark:hover:bg-amber-900/30">
                {{ $action['label'] }}
            </a>
        @endforeach
        @if ($showDetail && $insight->productKey)
            @if ($inlineDetail)
                <button type="button" wire:click="openDetail('{{ $insight->productKey }}')" class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-xs font-medium text-neutral-600 hover:bg-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 dark:text-neutral-300 dark:hover:bg-zinc-700">Ver vendas do produto</button>
            @else
                <a href="{{ route('admin.reports.sold-items', array_merge($reportParams, ['produto' => $insight->productKey])) }}" wire:navigate class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-xs font-medium text-neutral-600 hover:bg-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 dark:text-neutral-300 dark:hover:bg-zinc-700">Ver vendas do produto</a>
            @endif
        @endif
    </div>
</article>
