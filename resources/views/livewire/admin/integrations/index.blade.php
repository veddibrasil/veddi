<div class="w-full space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Integrações</h1>
        <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Conecte o Mister Coxinha a outras plataformas.</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($integrations as $integration)
            <a href="{{ $integration['route'] }}" wire:navigate
                class="flex flex-col gap-3 p-5 rounded-xl border border-gray-100 bg-white hover:border-purple-200 transition-all dark:bg-zinc-800 dark:border-zinc-700 dark:hover:border-purple-800/50">
                <div class="flex items-center justify-between">
                    <div class="w-10 h-10 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0 text-lg">
                        🛵
                    </div>
                    @if($integration['connected'])
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">
                            Conectado
                        </span>
                    @else
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-neutral-100 text-neutral-500 dark:bg-zinc-700 dark:text-neutral-400">
                            Não conectado
                        </span>
                    @endif
                </div>
                <div>
                    <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100">{{ $integration['name'] }}</p>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">{{ $integration['description'] }}</p>
                </div>
                <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-auto pt-2">{{ $integration['status'] }}</p>
            </a>
        @endforeach

        {{-- Espaço reservado pra próximas integrações --}}
        <div class="flex flex-col items-center justify-center gap-2 p-5 rounded-xl border border-dashed border-gray-200 text-center dark:border-zinc-700">
            <p class="text-sm font-medium text-neutral-400 dark:text-neutral-500">Mais integrações em breve</p>
        </div>
    </div>
</div>
