@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="Laravel Starter Kit" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md overflow-hidden">
            <img src="{{ asset('logo.jpg') }}" alt="Logo" class="size-8 object-contain" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="Laravel Starter Kit" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md overflow-hidden">
            <img src="{{ asset('logo.jpg') }}" alt="Logo" class="size-8 object-contain" />
        </x-slot>
    </flux:brand>
@endif
