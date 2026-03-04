<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-neutral-800">Filiais</h1>
        <a href="{{ route('admin.branches.create') }}"
            class="inline-flex items-center gap-1 bg-amber-500 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-amber-600 transition-colors">
            + Nova filial
        </a>
    </div>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm">
            {{ session('status') }}
        </div>
    @endif

    <div class="bg-white border rounded-xl shadow-sm overflow-hidden">
        <div class="divide-y">
            @forelse ($branches as $branch)
                <div class="flex items-center justify-between px-4 py-4">
                    <div>
                        <p class="font-semibold text-neutral-800">{{ $branch->name }}</p>
                        <p class="text-sm text-neutral-500">{{ $branch->address }}, {{ $branch->city }}</p>
                        <p class="text-xs text-neutral-400">{{ $branch->opens_at }} – {{ $branch->closes_at }}
                            @if ($branch->phone) · {{ $branch->phone }} @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs px-2 py-0.5 rounded-full {{ $branch->active ? 'bg-green-100 text-green-700' : 'bg-neutral-100 text-neutral-500' }}">
                            {{ $branch->active ? 'Ativa' : 'Inativa' }}
                        </span>
                        <a href="{{ route('admin.branches.edit', $branch) }}"
                            class="text-xs text-amber-600 hover:underline">Editar</a>

                        @if ($deletingId === $branch->id)
                            <span class="text-xs text-neutral-500">Confirmar?</span>
                            <button wire:click="delete" class="text-xs text-red-600 hover:underline font-semibold">Sim</button>
                            <button wire:click="cancelDelete" class="text-xs text-neutral-500 hover:underline">Não</button>
                        @else
                            <button wire:click="confirmDelete({{ $branch->id }})"
                                class="text-xs text-red-400 hover:text-red-600">Excluir</button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="px-4 py-8 text-sm text-neutral-500 text-center">Nenhuma filial cadastrada.</p>
            @endforelse
        </div>
    </div>
</div>
