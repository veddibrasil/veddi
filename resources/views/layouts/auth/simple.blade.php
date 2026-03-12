<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen antialiased" style="background: linear-gradient(145deg, #ffc72d 0%, #f5bc28 40%, #fff8e1 100%);">
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
                <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-xl shadow-amber-200/60 border border-white/70 p-6">
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
