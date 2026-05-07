<div class="space-y-4"
    x-data="{}"
    x-init="$watch(() => $wire.deletingId, val => val ? $flux.modal('confirm-delete-product').show() : $flux.modal('confirm-delete-product').close())">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Produtos</h1>
        @if($canCreate)
        <a href="{{ route('admin.products.create') }}"
            class="inline-flex items-center gap-1 bg-amber-500 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-amber-600 transition-colors">
            + Novo produto
        </a>
        @endif
    </div>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm dark:bg-red-900/30 dark:border-red-700 dark:text-red-400">
            {{ session('error') }}
        </div>
    @endif

    <div class="flex gap-2">
        <div class="flex-1">
            <flux:input wire:model.live="search" placeholder="Buscar produto..." />
        </div>
        @if($isSuperAdmin)
        <flux:select wire:model.live="companyFilter" placeholder="Todas as empresas" class="w-48 shrink-0">
            <flux:select.option value="">Todas as empresas</flux:select.option>
            @foreach ($companies as $company)
                <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
            @endforeach
        </flux:select>
        @endif
        <flux:select wire:model.live="categoryFilter" placeholder="Todas as categorias" class="w-48 shrink-0">
            <flux:select.option value="">Todas as categorias</flux:select.option>
            @foreach ($categories as $cat)
                <flux:select.option value="{{ $cat->id }}">{{ $cat->name }}</flux:select.option>
            @endforeach
        </flux:select>
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
                        @if($isSuperAdmin && $product->company)
                            <p class="text-xs font-medium text-amber-600 dark:text-amber-400">{{ $product->company->name }}</p>
                        @endif
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $product->category->name ?? '—' }}</p>
                    </div>
                    <div class="w-24 text-right shrink-0">
                        @if ($product->promo_price_enabled && $product->promo_price_value !== null)
                            <p class="text-[11px] text-neutral-400 dark:text-neutral-500 line-through">
                                R$ {{ number_format($product->price, 2, ',', '.') }}
                            </p>
                            <p class="font-bold text-sm text-amber-600 dark:text-amber-400">
                                R$ {{ number_format($product->effective_price, 2, ',', '.') }}
                            </p>
                        @else
                            <p class="font-bold text-sm text-amber-600 dark:text-amber-400">R$ {{ number_format($product->price, 2, ',', '.') }}</p>
                        @endif
                    </div>
                    <div class="w-16 text-center shrink-0">
                        <span class="text-xs px-2 py-0.5 rounded-full
                            {{ $product->active ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400' : 'bg-neutral-100 text-neutral-500 dark:bg-zinc-700 dark:text-neutral-400' }}">
                            {{ $product->active ? 'Ativo' : 'Inativo' }}
                        </span>
                    </div>
                    <div class="w-28 flex items-center justify-end gap-1 shrink-0">
                        {{-- Estoque --}}
                        <div class="relative group">
                            <a href="{{ route('admin.stock.index', ['search' => $product->name]) }}" wire:navigate
                                class="inline-flex items-center justify-center p-1.5 rounded text-neutral-400 hover:text-amber-600 hover:bg-amber-50 dark:hover:text-amber-400 dark:hover:bg-amber-900/20 transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                            </a>
                            <span class="pointer-events-none absolute bottom-full right-0 mb-1.5 px-2 py-1 rounded bg-neutral-800 text-white text-xs whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 dark:bg-zinc-600">Estoque</span>
                        </div>

                        {{-- Editar --}}
                        @if($canUpdate)
                        <div class="relative group">
                            <a href="{{ route('admin.products.edit', $product) }}"
                                class="inline-flex items-center justify-center p-1.5 rounded text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-900/20 transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </a>
                            <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded bg-neutral-800 text-white text-xs whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 dark:bg-zinc-600">Editar</span>
                        </div>
                        @endif

                        {{-- Excluir --}}
                        @if($canDelete)
                        <div class="relative group">
                            <button wire:click="confirmDelete({{ $product->id }})"
                                class="inline-flex items-center justify-center p-1.5 rounded text-neutral-400 hover:text-red-600 hover:bg-red-50 dark:hover:text-red-400 dark:hover:bg-red-900/20 transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                            <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded bg-neutral-800 text-white text-xs whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 dark:bg-zinc-600">Excluir</span>
                        </div>
                        @endif
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

    {{-- Paginação --}}
    @if ($products->hasPages())
        <div class="mt-2">
            {{ $products->links() }}
        </div>
    @endif

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
                    <flux:subheading class="mt-1">Esta ação não pode ser desfeita. <br> O produto será removido permanentemente.</flux:subheading>
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
