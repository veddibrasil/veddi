<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Criar conta — {{ config('app.name') }}</title>

    <link rel="icon" type="image/png" href="/favicon/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon/favicon.svg" />
    <link rel="shortcut icon" href="/favicon/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/favicon/apple-touch-icon.png" />

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:opsz,wght@6..12,400;6..12,500;6..12,600;6..12,700&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen antialiased">

<div class="flex min-h-screen">

    {{-- ───── LEFT PANEL (branding) ───── --}}
    <div class="hidden lg:flex lg:w-[48%] xl:w-[44%] flex-col relative overflow-hidden"
         style="background: linear-gradient(145deg, #3d0066 0%, #5c0079 35%, #7A00A3 70%, #9b00cc 100%);">

        {{-- Decorative blobs --}}
        <div class="absolute -top-24 -left-24 w-80 h-80 rounded-full opacity-10"
             style="background: radial-gradient(circle, #e879f9, transparent);"></div>
        <div class="absolute bottom-0 right-0 w-96 h-96 rounded-full opacity-10"
             style="background: radial-gradient(circle, #a855f7, transparent); transform: translate(30%, 30%);"></div>
        <div class="absolute top-1/2 left-1/2 w-64 h-64 rounded-full opacity-5"
             style="background: radial-gradient(circle, #ffffff, transparent); transform: translate(-50%, -50%);"></div>

        {{-- Content --}}
        <div class="relative z-10 flex flex-col h-full px-12 py-10">

            {{-- Logo --}}
            <a href="/" class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/10 backdrop-blur-sm border border-white/20 shadow-lg">
                    <img src="{{ asset('logo_branca.png') }}" alt="{{ config('app.name') }}" class="size-7 object-contain" />
                </span>
                <span class="text-xl font-bold text-white tracking-tight" style="font-family: 'Montserrat', sans-serif;">
                    {{ config('app.name') }}
                </span>
            </a>

            {{-- Headline --}}
            <div class="mt-16 flex-1">
                <h1 class="text-4xl xl:text-5xl font-extrabold text-white leading-tight" style="font-family: 'Montserrat', sans-serif;">
                    Seu negócio<br/>
                    <span style="color: #f0abfc;">online em<br/>minutos.</span>
                </h1>
                <p class="mt-5 text-lg text-purple-200 leading-relaxed max-w-xs">
                    Configure sua loja, receba pedidos e gerencie tudo em um só lugar.
                </p>

                {{-- Features --}}
                <ul class="mt-10 space-y-4">
                    @foreach([
                        ['icon' => 'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z', 'text' => 'Cardápio digital com pedidos em tempo real'],
                        ['icon' => 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z', 'text' => 'PIX, cartão de crédito e transferência com confirmação automática'],
                        ['icon' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z', 'text' => 'PDV completo: balcão, comandas de mesa e fechamento de caixa'],
                        ['icon' => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z', 'text' => 'Emissão fiscal automática de NFC-e/NF-e a cada venda'],
                    ] as $feature)
                        <li class="flex items-start gap-3">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/15 mt-0.5">
                                <svg class="h-3.5 w-3.5 text-fuchsia-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $feature['icon'] }}" />
                                </svg>
                            </span>
                            <span class="text-sm text-purple-100">{{ $feature['text'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Footer --}}
            <div class="mt-auto pt-8 border-t border-white/10">
                <p class="text-xs text-purple-300">
                    Já tem uma conta?
                    <a href="{{ route('login') }}" class="text-white font-medium hover:underline">Fazer login →</a>
                </p>
            </div>
        </div>
    </div>

    {{-- ───── RIGHT PANEL (form) ───── --}}
    <div class="flex-1 flex flex-col bg-white overflow-y-auto">

        {{-- Mobile header --}}
        <div class="lg:hidden flex items-center justify-between px-6 py-5 border-b border-zinc-100">
            <a href="/" class="flex items-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#5c0079]">
                    <img src="{{ asset('logo_branca.png') }}" alt="{{ config('app.name') }}" class="size-6 object-contain" />
                </span>
                <span class="font-bold text-[#5c0079]" style="font-family: 'Montserrat', sans-serif;">{{ config('app.name') }}</span>
            </a>
            <a href="{{ route('login') }}" class="text-sm text-zinc-500 hover:text-zinc-800">Entrar</a>
        </div>

        {{-- Form area --}}
        <div class="flex-1 flex items-start justify-center px-6 py-10 lg:py-16">
            <div class="w-full max-w-lg">
                <livewire:onboarding.register-form />
            </div>
        </div>

        {{-- Right panel footer --}}
        <div class="px-6 py-4 border-t border-zinc-100 text-center">
          <p class="text-center text-xs text-zinc-400">
                Ao criar sua conta, você concorda com nossos
                <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener" class="text-[#7A00A3] hover:underline font-medium">Termos de Uso</a>
                e
                <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener" class="text-[#7A00A3] hover:underline font-medium">Política de Privacidade</a>.
            </p>
        </div>
    </div>

</div>

@fluxScripts
</body>
</html>
