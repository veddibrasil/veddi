@props(['backRoute' => null, 'title', 'titleClass' => '', 'backLabel' => 'Voltar'])

<div class="flex flex-wrap items-center justify-between gap-3">
    <div class="flex min-w-0 items-center gap-2">
        @if ($backRoute)
            <a
                href="{{ $backRoute }}"
                aria-label="{{ $backLabel }}"
                title="{{ $backLabel }}"
                class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-500 dark:text-neutral-400 dark:hover:bg-zinc-700 dark:hover:text-neutral-200"
            >
                <span aria-hidden="true">←</span>
            </a>
        @endif
        <h1 class="min-w-0 text-2xl font-bold text-neutral-800 dark:text-neutral-100 {{ $titleClass }}">{{ $title }}</h1>
    </div>
    @isset($actions)
        {{ $actions }}
    @endisset
</div>
