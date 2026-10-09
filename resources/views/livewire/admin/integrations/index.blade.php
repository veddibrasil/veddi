<div class="w-full space-y-6">
    <x-admin.page-header title="Integrações" description="Conecte o painel a outras plataformas." />

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($integrations as $integration)
            <a href="{{ $integration['route'] }}" wire:navigate
                class="flex flex-col gap-3 p-5 rounded-xl border border-gray-100 bg-white hover:border-amber-300 transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-500 dark:bg-zinc-800 dark:border-zinc-700 dark:hover:border-amber-700/60">
                <div class="flex items-center justify-between">
                    <div class="w-10 h-10 rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 flex items-center justify-center shrink-0">
                        <flux:icon :name="$integration['icon']" class="size-5" />
                    </div>
                    @switch($integration['state'])
                        @case('attention')
                            <flux:badge size="sm" color="yellow" icon="exclamation-triangle">Precisa de atenção</flux:badge>
                            @break
                        @case('connected')
                            <flux:badge size="sm" color="green">Conectado</flux:badge>
                            @break
                        @default
                            <flux:badge size="sm" color="zinc">Não conectado</flux:badge>
                    @endswitch
                </div>
                <div>
                    <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100">{{ $integration['name'] }}</p>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">{{ $integration['description'] }}</p>
                </div>
                <p class="mt-auto flex items-center justify-between gap-2 pt-2 text-xs text-neutral-600 dark:text-neutral-400">
                    <span>{{ $integration['status'] }}</span>
                    <span class="font-medium text-amber-700 dark:text-amber-400" aria-hidden="true">Configurar →</span>
                </p>
            </a>
        @endforeach

        {{-- Espaço reservado pra próximas integrações --}}
        <div class="flex flex-col items-center justify-center gap-2 p-5 rounded-xl border border-dashed border-gray-200 text-center dark:border-zinc-700">
            <p class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Mais integrações em breve</p>
        </div>
    </div>
</div>
