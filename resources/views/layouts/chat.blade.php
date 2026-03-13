<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($currentCompany) ? $currentCompany->name . ($currentCompany->tagline ? ' — ' . $currentCompany->tagline : '') : config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if(isset($currentCompany))
        <x-company-theme :company="$currentCompany" />
    @endif
    @livewireStyles
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="mc-chat-bg h-full overflow-x-hidden font-['Inter',_sans-serif]">

    <div class="h-full flex">

        {{-- ═══════ Painel esquerdo — branding (somente desktop) ═══════ --}}
        <div
            class="hidden lg:flex flex-1 flex-col items-center justify-center relative overflow-hidden px-16 py-12 select-none"
            style="background: linear-gradient(145deg, color-mix(in srgb, var(--mc-red-dark) 60%, black) 0%, var(--mc-red-dark) 35%, var(--mc-red) 70%, color-mix(in srgb, var(--mc-red) 70%, orange) 100%);"
        >
            {{-- Círculos decorativos de fundo --}}
            <div class="absolute -top-24 -left-24 w-80 h-80 rounded-full bg-white/5"></div>
            <div class="absolute top-1/3 -right-16 w-56 h-56 rounded-full bg-white/5"></div>
            <div class="absolute -bottom-20 left-1/4 w-72 h-72 rounded-full bg-black/10"></div>

            {{-- Conteúdo central --}}
            <div class="relative z-10 text-center max-w-md">

                {{-- Logo / Avatar --}}
                <div style="background: var(--mc-red-light)" class="w-28 h-28 rounded-full border-4 border-white/30 flex items-center justify-center mx-auto mb-8 shadow-2xl overflow-hidden">
                    @if(isset($currentCompany) && $currentCompany->logo_path)
                        <img src="{{ $currentCompany->logo_url }}" alt="{{ $currentCompany->name }}" class="w-full h-full object-cover">
                    @else
                        <img src="{{ '/logo.png' }}" alt="{{ $currentCompany->name ?? config('app.name') }}" class="w-full h-full object-cover">
                    @endif
                </div>

                {{-- Marca --}}
                <h1 class="text-white font-black text-5xl leading-tight mb-2">
                    {{ isset($currentCompany) ? $currentCompany->name : config('app.name') }}
                </h1>
                <p class="text-white/70 text-lg font-medium mb-10">
                    {{ isset($currentCompany) && $currentCompany->tagline ? $currentCompany->tagline : '' }}
                </p>

                {{-- Diferenciais --}}
                <div class="space-y-4 text-left">
                    @foreach ([
                        ['🥟', 'Salgados fresquinhos', 'Feitos na hora, com ingredientes selecionados'],
                        ['⚡', 'Pedido rápido', 'Faça seu pedido em poucos cliques pelo chat'],
                        ['💳', 'Pague por PIX', 'Confirmação automática e entrega ágil'],
                    ] as [$icon, $title, $desc])
                        <div class="flex items-start gap-4 bg-white/10 rounded-2xl px-5 py-4">
                            <span class="text-2xl shrink-0 mt-0.5">{{ $icon }}</span>
                            <div>
                                <p class="text-white font-bold text-sm leading-tight">{{ $title }}</p>
                                <p class="text-white/60 text-xs mt-0.5">{{ $desc }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Rodapé branding --}}
                <p class="text-white/30 text-xs mt-10">{{ isset($currentCompany) && $currentCompany->footer_text ? $currentCompany->footer_text : '© ' . date('Y') . ' ' . config('app.name') }}</p>
            </div>
        </div>

        {{-- ═══════ Painel do chat (mobile: tela cheia | desktop: coluna direita) ═══════ --}}
        <div class="flex-1 lg:flex-none lg:w-125 flex items-center justify-center lg:bg-gray-100/80 lg:px-8 lg:py-8">
            {{ $slot }}
        </div>

    </div>

    @livewireScripts
    @fluxScripts
</body>
</html>
