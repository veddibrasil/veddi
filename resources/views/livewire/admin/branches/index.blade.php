<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Filiais</h1>
        <a href="{{ route('admin.branches.create') }}"
            class="inline-flex items-center gap-1 bg-amber-500 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-amber-600 transition-colors">
            + Nova filial
        </a>
    </div>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
        <div class="divide-y dark:divide-zinc-700">
            @forelse ($branches as $branch)
                <div class="flex items-center justify-between px-4 py-4">
                    <div>
                        <p class="font-semibold text-neutral-800 dark:text-neutral-100">{{ $branch->name }}</p>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $branch->address }}, {{ $branch->city }}</p>
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $branch->opens_at }} – {{ $branch->closes_at }}
                            @if ($branch->phone) · {{ $branch->phone }} @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs px-2 py-0.5 rounded-full {{ $branch->active ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400' : 'bg-neutral-100 text-neutral-500 dark:bg-zinc-700 dark:text-neutral-400' }}">
                            {{ $branch->active ? 'Ativa' : 'Inativa' }}
                        </span>
                        <a href="{{ route('admin.branches.edit', $branch) }}"
                            class="text-xs text-amber-600 hover:underline dark:text-amber-400">Editar</a>

                        @if ($deletingId === $branch->id)
                            <span class="text-xs text-neutral-500 dark:text-neutral-400">Confirmar?</span>
                            <button wire:click="delete" class="text-xs text-red-600 hover:underline font-semibold dark:text-red-400">Sim</button>
                            <button wire:click="cancelDelete" class="text-xs text-neutral-500 hover:underline dark:text-neutral-400">Não</button>
                        @else
                            <button wire:click="confirmDelete({{ $branch->id }})"
                                class="text-xs text-red-400 hover:text-red-600 dark:hover:text-red-300">Excluir</button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="px-4 py-8 text-sm text-neutral-500 dark:text-neutral-400 text-center">Nenhuma filial cadastrada.</p>
            @endforelse
        </div>
    </div>
</div>
