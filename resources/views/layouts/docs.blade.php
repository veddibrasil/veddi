@props([
    'title' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        @include('partials.head', ['title' => $title, 'disableAppearance' => true])
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-[#f8f8fb] font-sans text-zinc-800">
        {{ $slot }}

        @fluxScripts
    </body>
</html>
