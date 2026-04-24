<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>404 — Página não encontrada | {{ config('app.name', 'Laravel') }}</title>

    <link rel="icon" type="image/png" href="/favicon/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon/favicon.svg" />
    <link rel="shortcut icon" href="/favicon/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/favicon/apple-touch-icon.png" />

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,opsz,wght@0,6..12,300;0,6..12,400;0,6..12,500;0,6..12,600;0,6..12,700&family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-[#f8f8fb] dark:bg-[#0d1825] flex items-center justify-center px-4">

    <div class="w-full max-w-lg text-center">

        {{-- Ilustração 404 --}}
        <div class="relative mx-auto mb-8 w-48 h-48 flex items-center justify-center">
            <div class="absolute inset-0 rounded-full bg-[#7A00A3]/10 dark:bg-[#7A00A3]/20 animate-pulse"></div>
            <div class="relative z-10 flex flex-col items-center">
 
                <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12 text-[#7A00A3]/60 dark:text-[#c45ef0]/60 -mt-2" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.182 16.318A4.486 4.486 0 0 0 12.016 15a4.486 4.486 0 0 0-3.198 1.318M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75Zm-.375 0h.008v.015h-.008V9.75Zm5.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75Zm-.375 0h.008v.015h-.008V9.75Z" />
                </svg>
            </div>
        </div>

        {{-- Mensagem --}}
        <h1 class="text-2xl font-bold text-zinc-800 dark:text-zinc-100 mb-2">
            Página não encontrada
        </h1>
        <p class="text-zinc-500 dark:text-zinc-400 text-base mb-8 leading-relaxed">
            Oops! A página que você está procurando não existe ou foi movida.<br>
            Verifique o endereço ou volte para o início.
        </p>

        {{-- Ações --}}
        <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
            @auth
                <a
                    href="{{ route('admin.dashboard') }}"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-[#7A00A3] hover:bg-[#5c0079] text-white font-semibold text-sm transition-colors shadow"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                    </svg>
                    Ir para o Dashboard
                </a>
            @else
                <a
                    href="{{ route('login') }}"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-[#7A00A3] hover:bg-[#5c0079] text-white font-semibold text-sm transition-colors shadow"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
                    </svg>
                    Fazer Login
                </a>
            @endauth

            <button
                onclick="history.back()"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg border border-zinc-300 dark:border-zinc-600 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 font-semibold text-sm transition-colors"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
                Voltar
            </button>
        </div>


    </div>

</body>
</html>
