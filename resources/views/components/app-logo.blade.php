@props([
    'sidebar' => false,
])

@php
    $logoSrc   = isset($currentCompany) && $currentCompany->logo_path
        ? $currentCompany->logo_url
        : asset('logo.jpg');
    $brandName = isset($currentCompany) ? $currentCompany->name : config('app.name');
@endphp

@if($sidebar)
    <flux:sidebar.brand name="{{ $brandName }}" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md overflow-hidden">
            <img src="{{ $logoSrc }}" alt="{{ $brandName }}" class="size-8 object-contain" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="{{ $brandName }}" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md overflow-hidden">
            <img src="{{ $logoSrc }}" alt="{{ $brandName }}" class="size-8 object-contain" />
        </x-slot>
    </flux:brand>
@endif
