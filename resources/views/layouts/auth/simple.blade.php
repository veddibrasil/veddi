<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />

        <title>
            {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
        </title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=fredoka:400,500,600,700|instrument-sans:400,500,600" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen antialiased bg-linear-to-br from-amber-400 via-yellow-300 to-amber-50">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-4">

                {{-- Logo / marca --}}
                <a href="{{ route('admin.dashboard') }}" class="flex flex-col items-center gap-3" wire:navigate>
                    <span class="flex h-16 w-16 items-center justify-center rounded-2xl bg-[#da291c] shadow-lg shadow-red-200">
                        <x-app-logo-icon class="size-10 fill-current text-white" />
                    </span>
                    <span class="text-2xl font-bold text-[#7a1510]" style="font-family: 'Fredoka', sans-serif; letter-spacing: -0.5px;">
                        {{ config('app.name', 'Delivry') }}
                    </span>
                </a>

                {{-- Card de formulário --}}
                <div class="bg-white rounded-2xl shadow-xl shadow-amber-200/60 border border-white/70 p-6">
                    <div class="flex flex-col gap-6">
                        {{ $slot }}
                    </div>
                </div>

                <p class="text-center text-xs text-[#a81f14]/70">
                    Seu pedido, nossa prioridade.
                </p>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
