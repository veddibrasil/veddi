@props(['backRoute', 'title', 'titleClass' => ''])

<div class="flex items-center justify-between gap-3">
    <div class="flex items-center gap-3">
        <a href="{{ $backRoute }}" class="text-neutral-400 hover:text-neutral-600 dark:text-neutral-500 dark:hover:text-neutral-300">←</a>
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 {{ $titleClass }}">{{ $title }}</h1>
    </div>
    @isset($actions)
        {{ $actions }}
    @endisset
</div>
