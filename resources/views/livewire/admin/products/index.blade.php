<div class="space-y-4"
    x-data="{}"
    x-init="$watch(() => $wire.deletingId, val => val ? $flux.modal('confirm-delete-product').show() : $flux.modal('confirm-delete-product').close())">
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
        {{-- Cabeçalho de colunas --}}
        <div class="flex items-center gap-4 px-4 py-2 border-b bg-neutral-50 dark:bg-zinc-700/50 dark:border-zinc-700">
            <div class="w-12 shrink-0"></div>
            <span class="flex-1 text-xs font-medium text-neutral-400 uppercase tracking-wide">Produto</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide w-24 text-right shrink-0">Preço</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide w-16 text-center shrink-0">Status</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide w-28 text-right shrink-0">Ações</span>
        </div>
        <div class="divide-y dark:divide-zinc-700">
            @forelse ($products as $product)
                <div class="flex items-center gap-4 px-4 py-3">
                    @if ($product->image_path)
                        <img src="{{ $product->image_url }}"
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
                    <div class="w-24 text-right shrink-0">
                        <p class="font-bold text-sm text-amber-600 dark:text-amber-400">R$ {{ number_format($product->price, 2, ',', '.') }}</p>
                    </div>
                    <div class="w-16 text-center shrink-0">
                        <span class="text-xs px-2 py-0.5 rounded-full
                            {{ $product->active ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400' : 'bg-neutral-100 text-neutral-500 dark:bg-zinc-700 dark:text-neutral-400' }}">
                            {{ $product->active ? 'Ativo' : 'Inativo' }}
                        </span>
                    </div>
                    <div class="w-28 flex items-center justify-end gap-2 shrink-0">
                        <a href="{{ route('admin.products.edit', $product) }}"
                            class="text-xs text-amber-600 hover:underline dark:text-amber-400">Editar</a>
                        <button wire:click="confirmDelete({{ $product->id }})"
                            class="text-xs text-neutral-400 hover:text-red-600 dark:hover:text-red-300">Excluir</button>
                    </div>
                </div>
            @empty
                <div class="px-4 py-12 text-center">
                    <div class="text-3xl mb-2">🛍️</div>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhum produto encontrado.</p>
                    @if ($search || $categoryFilter)
                        <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-1">Tente remover os filtros aplicados.</p>
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    {{-- Modal de confirmação de exclusão --}}
    <flux:modal name="confirm-delete-product" class="max-w-sm">
        <div class="space-y-5">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                </div>
                <div>
                    <flux:heading size="lg">Excluir produto?</flux:heading>
                    <flux:subheading class="mt-1">Esta ação não pode ser desfeita. O produto será removido permanentemente.</flux:subheading>
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-1">
                <flux:modal.close>
                    <flux:button wire:click="cancelDelete" variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:modal.close>
                    <flux:button wire:click="delete" variant="danger">Excluir</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
