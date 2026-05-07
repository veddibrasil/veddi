@props(['title' => null, 'padding' => 'p-6 space-y-4'])

<div class="bg-white border rounded-xl shadow-sm {{ $padding }} dark:bg-zinc-800 dark:border-zinc-700">
    @if ($title)
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">{{ $title }}</h2>
    @endif

    {{ $slot }}
</div>
