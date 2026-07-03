<x-layouts::app.sidebar body-class="h-dvh overflow-hidden">
    <div data-flux-main class="[grid-area:main] overflow-hidden flex flex-col">
        @include('partials.overdue-banner')

        <div class="flex-1 min-h-0 flex flex-col overflow-hidden">
            {{ $slot }}
        </div>
    </div>
</x-layouts::app.sidebar>
