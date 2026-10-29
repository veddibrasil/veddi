@php
    $links = [
        ['route' => 'superadmin.marketing.campaigns.index', 'match' => 'superadmin.marketing.campaigns.*', 'label' => 'Campanhas'],
        ['route' => 'superadmin.marketing.templates.index', 'match' => 'superadmin.marketing.templates.*', 'label' => 'Templates'],
        ['route' => 'superadmin.marketing.segments.index', 'match' => 'superadmin.marketing.segments.*', 'label' => 'Segmentos'],
        ['route' => 'superadmin.marketing.automations.index', 'match' => 'superadmin.marketing.automations.*', 'label' => 'Automações'],
        ['route' => 'superadmin.marketing.suppressions.index', 'match' => 'superadmin.marketing.suppressions.*', 'label' => 'Supressões'],
    ];
@endphp

<nav class="flex flex-wrap gap-1 border-b border-neutral-200 dark:border-zinc-700" aria-label="E-mail marketing">
    @foreach($links as $link)
        @php($active = request()->routeIs($link['match']))
        <a href="{{ route($link['route']) }}" wire:navigate
           @class([
               'px-3 py-2 text-sm font-medium -mb-px border-b-2 transition',
               'border-amber-500 text-neutral-900 dark:text-neutral-100' => $active,
               'border-transparent text-neutral-500 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200' => ! $active,
           ])
           @if($active) aria-current="page" @endif>
            {{ $link['label'] }}
        </a>
    @endforeach
</nav>
