<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Produtos</h1>
        <a href="{{ route('admin.products.create') }}"
            class="inline-flex items-center gap-1 bg-amber-500 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-amber-600 transition-colors">
            + Novo produto
        </a>
    </div>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    <div class="flex gap-2">
        <div class="flex-1">
            <flux:input wire:model.live="search" placeholder="Buscar produto..." />
        </div>
        <select wire:model.live="categoryFilter"
            class="border rounded-lg px-3 py-2 text-sm text-neutral-700 focus:outline-none focus:ring-2 focus:ring-amber-400 dark:bg-zinc-800 dark:border-zinc-600 dark:text-neutral-200 dark:focus:ring-amber-500">
            <option value="">Todas as categorias</option>
            @foreach ($categories as $cat)
                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
        <div class="divide-y dark:divide-zinc-700">
            @forelse ($products as $product)
                <div class="flex items-center gap-4 px-4 py-3">
                    @if ($product->image_path)
                        <img src="{{ asset('storage/'.$product->image_path) }}"
                            class="w-12 h-12 rounded-lg object-cover shrink-0" />
                    @else
                        <div class="w-12 h-12 rounded-lg bg-amber-50 flex items-center justify-center shrink-0 text-xl dark:bg-zinc-700">
                            🥟
                        </div>
                    @endif
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold text-sm text-neutral-800 dark:text-neutral-100">{{ $product->name }}</p>
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $product->category->name ?? '—' }}</p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="font-bold text-sm text-amber-600 dark:text-amber-400">R$ {{ number_format($product->price, 2, ',', '.') }}</p>
                        <span class="text-xs px-2 py-0.5 rounded-full
                            {{ $product->active ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400' : 'bg-neutral-100 text-neutral-500 dark:bg-zinc-700 dark:text-neutral-400' }}">
                            {{ $product->active ? 'Ativo' : 'Inativo' }}
                        </span>
                    </div>
                    <div class="flex gap-2 shrink-0">
                        <a href="{{ route('admin.products.edit', $product) }}"
                            class="text-xs text-amber-600 hover:underline dark:text-amber-400">Editar</a>
                        @if ($deletingId === $product->id)
                            <span class="text-xs text-neutral-500 dark:text-neutral-400">Confirmar?</span>
                            <button wire:click="delete" class="text-xs text-red-600 font-semibold hover:underline dark:text-red-400">Sim</button>
                            <button wire:click="cancelDelete" class="text-xs text-neutral-500 hover:underline dark:text-neutral-400">Não</button>
                        @else
                            <button wire:click="confirmDelete({{ $product->id }})"
                                class="text-xs text-red-400 hover:text-red-600 dark:hover:text-red-300">Excluir</button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="px-4 py-8 text-sm text-neutral-500 dark:text-neutral-400 text-center">Nenhum produto encontrado.</p>
            @endforelse
        </div>
    </div>
</div>
