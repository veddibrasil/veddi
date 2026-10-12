@props(['variant' => 'warning', 'heading' => null, 'icon' => 'exclamation-triangle'])

{{--
    Aviso dentro da tela (erro, atenção, sucesso). Usa utilitários do Tailwind em vez do
    flux:callout: o tema do app não declara as variáveis de cor (--color-red-200...) que o
    callout lê, e ele sairia sem cor.
--}}
@php
    $classes = match ($variant) {
        'danger' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/30 dark:text-red-300',
        'success' => 'border-green-200 bg-green-50 text-green-700 dark:border-green-700 dark:bg-green-900/30 dark:text-green-300',
        'info' => 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
        default => 'border-yellow-200 bg-yellow-50 text-yellow-800 dark:border-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300',
    };
@endphp

<div {{ $attributes->merge(['class' => "flex items-start gap-3 rounded-lg border px-4 py-3 text-sm {$classes}"]) }}>
    @if ($icon)
        <flux:icon :name="$icon" variant="mini" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
    @endif
    <div class="min-w-0 space-y-1">
        @if ($heading)
            <p class="font-semibold">{{ $heading }}</p>
        @endif
        <div>{{ $slot }}</div>
    </div>
</div>
