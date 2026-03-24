<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />

        <title>
            {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
        </title>

        <link rel="icon" type="image/png" href="/favicon/favicon-96x96.png" sizes="96x96" />
        <link rel="icon" type="image/svg+xml" href="/favicon/favicon.svg" />
        <link rel="shortcut icon" href="/favicon/favicon.ico" />
        <link rel="apple-touch-icon" sizes="180x180" href="/favicon/apple-touch-icon.png" />
        <meta name="apple-mobile-web-app-title" content="MyWebSite" />
        <link rel="manifest" href="/favicon/site.webmanifest" />

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=fredoka:400,500,600,700|instrument-sans:400,500,600" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen antialiased bg-linear-to-br from-amber-400 via-yellow-300 to-amber-50">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-4">

                {{-- Logo / marca --}}
                <a href="{{ route('admin.dashboard') }}" class="flex flex-col items-center gap-3" wire:navigate>
                    <span class="flex h-16 w-16 items-center justify-center rounded-2xl bg-[#7A00A3] shadow-lg shadow-purple-300">
                        <img src="{{ asset('logo_branca.png') }}" alt="{{ config('app.name') }}" class="size-12 object-contain" />
                    </span>
                    <span class="text-2xl font-bold text-[#5c0079]" style="font-family: 'Fredoka', sans-serif; letter-spacing: -0.5px;">
                        {{ config('app.name') }}
                    </span>
                </a>

                {{-- Card de formulário --}}
                <div class="bg-white rounded-2xl shadow-xl shadow-amber-200/60 border border-white/70 p-6">
                    <div class="flex flex-col gap-6">
                        {{ $slot }}
                    </div>
                </div>

                <p class="text-center text-xs text-[#a81f14]/70">
                    Seu pedido, rápido e sem complicação
                </p>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
