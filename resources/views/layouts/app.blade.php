<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        {{-- Margens negativas: o banner ocupa a largura toda do grid-area:main,
             ignorando o padding do flux:main (que só se aplica ao conteúdo da página). --}}
        @include('partials.overdue-banner', ['wrapperClass' => '-mx-6 lg:-mx-8 -mt-6 lg:-mt-8 mb-6 lg:mb-8'])

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
