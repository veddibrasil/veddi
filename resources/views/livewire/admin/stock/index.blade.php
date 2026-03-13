<div class="space-y-4"
    x-data="{}"
    x-init="$watch(() => $wire.adjustingProductId, val => val ? $flux.modal('adjust-stock').show() : $flux.modal('adjust-stock').close())">

    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Controle de Estoque</h1>
            @if ($lowStockCount > 0)
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300">
                    {{ $lowStockCount }} {{ $lowStockCount === 1 ? 'produto' : 'produtos' }} com estoque baixo
                </span>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    {{-- Filtros --}}
    <div class="bg-white border rounded-xl shadow-sm p-4 dark:bg-zinc-800 dark:border-zinc-700">
        <div class="flex flex-wrap gap-3 items-end">
            {{-- Filial --}}
            <div class="flex-1 min-w-40">
                <label class="block text-xs font-medium text-neutral-500 mb-1 dark:text-neutral-400">Filial</label>
                <flux:select wire:model.live="branchFilter" placeholder="Todas as filiais">
                    <flux:select.option value="">Todas as filiais</flux:select.option>
                    @foreach ($branches as $branch)
                        <flux:select.option value="{{ $branch->id }}">{{ $branch->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            {{-- Busca --}}
            <div class="flex-1 min-w-40">
                <label class="block text-xs font-medium text-neutral-500 mb-1 dark:text-neutral-400">Buscar produto</label>
                <flux:input wire:model.live.debounce.300ms="search" placeholder="Nome do produto..." />
            </div>

            {{-- Apenas estoque baixo --}}
            <div class="flex items-center gap-2 pb-1">
                <flux:checkbox wire:model.live="showLowStockOnly" id="low-stock-filter" />
                <label for="low-stock-filter" class="text-sm text-neutral-600 dark:text-neutral-400 cursor-pointer">
                    Apenas estoque baixo
                </label>
            </div>
        </div>
    </div>

    {{-- Tabela --}}
    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
        <div class="grid grid-cols-12 px-4 py-2 border-b bg-neutral-50 dark:bg-zinc-700/50 dark:border-zinc-700 text-xs font-medium text-neutral-400 uppercase tracking-wide">
            <div class="col-span-4">Produto</div>
            <div class="col-span-2 text-center">Rastrear</div>
            <div class="col-span-2 text-center">Quantidade</div>
            <div class="col-span-2 text-center">Mínimo</div>
            <div class="col-span-1 text-center">Status</div>
            <div class="col-span-1 text-center">Ações</div>
        </div>

        <div class="divide-y dark:divide-zinc-700">
            @forelse ($items as $item)
                @php
                    $isLow = $item->track_stock && $item->quantity <= $item->min_quantity && $item->quantity > 0;
                    $isEmpty = $item->track_stock && $item->quantity === 0;
                @endphp
                <div class="grid grid-cols-12 items-center px-4 py-3 @if($isLow) bg-amber-50 dark:bg-amber-900/10 @elseif($isEmpty) bg-red-50 dark:bg-red-900/10 @endif">
                    {{-- Produto --}}
                    <div class="col-span-4">
                        <p class="font-semibold text-sm text-neutral-800 dark:text-neutral-100">{{ $item->product_name }}</p>
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $item->category_name }}</p>
                        @if (! $branchFilter)
                            <p class="text-xs text-amber-600 dark:text-amber-400">Filial #{{ $item->branch_id }}</p>
                        @endif
                    </div>

                    {{-- Toggle rastrear --}}
                    <div class="col-span-2 flex justify-center">
                        <button
                            wire:click="toggleTracking({{ $item->product_id }}, {{ $item->branch_id }})"
                            wire:loading.attr="disabled"
                            class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none
                                {{ $item->track_stock ? 'bg-amber-500' : 'bg-neutral-300 dark:bg-zinc-600' }}">
                            <span class="inline-block h-3.5 w-3.5 rounded-full bg-white shadow transform transition-transform
                                {{ $item->track_stock ? 'translate-x-4.5' : 'translate-x-0.5' }}">
                            </span>
                        </button>
                    </div>

                    {{-- Quantidade --}}
                    <div class="col-span-2 text-center">
                        @if ($item->track_stock)
                            <span class="font-bold text-sm @if($isEmpty) text-red-600 dark:text-red-400 @elseif($isLow) text-amber-600 dark:text-amber-400 @else text-neutral-800 dark:text-neutral-100 @endif">
                                {{ $item->quantity }}
                            </span>
                        @else
                            <span class="text-xs text-neutral-400 dark:text-neutral-500">—</span>
                        @endif
                    </div>

                    {{-- Mínimo --}}
                    <div class="col-span-2 text-center">
                        @if ($item->track_stock)
                            <span class="text-sm text-neutral-600 dark:text-neutral-400">{{ $item->min_quantity }}</span>
                        @else
                            <span class="text-xs text-neutral-400 dark:text-neutral-500">—</span>
                        @endif
                    </div>

                    {{-- Status --}}
                    <div class="col-span-1 flex justify-center">
                        @if (! $item->track_stock)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-neutral-100 text-neutral-500 dark:bg-zinc-700 dark:text-neutral-400">
                                Sem rastreio
                            </span>
                        @elseif ($isEmpty)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400">
                                Esgotado
                            </span>
                        @elseif ($isLow)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400">
                                Baixo
                            </span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400">
                                OK
                            </span>
                        @endif
                    </div>

                    {{-- Ações --}}
                    <div class="col-span-1 flex justify-center">
                        @if ($item->track_stock)
                            <div class="relative group">
                                <button
                                    wire:click="openAdjustModal({{ $item->product_id }}, {{ $item->branch_id }})"
                                    class="inline-flex items-center justify-center p-1.5 rounded text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-900/20 transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                <span class="pointer-events-none absolute bottom-full right-0 mb-1.5 px-2 py-1 rounded bg-neutral-800 text-white text-xs whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 dark:bg-zinc-600">Ajustar estoque</span>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-4 py-10 text-center">
                    <div class="text-3xl mb-2">📦</div>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhum produto encontrado.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Paginação --}}
    @if ($items->hasPages())
        <div class="mt-2">
            {{ $items->links() }}
        </div>
    @endif

    {{-- Modal de ajuste de estoque --}}
    <flux:modal name="adjust-stock" class="max-w-md">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Ajustar Estoque</flux:heading>
                <flux:subheading class="mt-1">{{ $adjustProductName }}</flux:subheading>
            </div>

            <div class="bg-neutral-50 dark:bg-zinc-700/50 rounded-lg px-4 py-3 text-center">
                <p class="text-xs text-neutral-500 dark:text-neutral-400 mb-1">Quantidade atual</p>
                <p class="text-3xl font-bold text-neutral-800 dark:text-neutral-100">{{ $currentQuantity }}</p>
            </div>

            <div>
                <label class="block text-xs font-medium text-neutral-500 mb-1 dark:text-neutral-400">Modo de ajuste</label>
                <flux:select wire:model.live="adjustMode">
                    <flux:select.option value="add">Adicionar ao estoque</flux:select.option>
                    <flux:select.option value="subtract">Retirar do estoque</flux:select.option>
                    <flux:select.option value="set">Definir quantidade</flux:select.option>
                </flux:select>
            </div>

            <div>
                <label class="block text-xs font-medium text-neutral-500 mb-1 dark:text-neutral-400">
                    @if ($adjustMode === 'add') Quantidade a adicionar
                    @elseif ($adjustMode === 'subtract') Quantidade a retirar
                    @else Nova quantidade
                    @endif
                </label>
                <flux:input wire:model="adjustQuantity" type="number" min="0" placeholder="0" />
                @error('adjustQuantity') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-neutral-500 mb-1 dark:text-neutral-400">Observação <span class="text-red-500">*</span></label>
                <flux:textarea wire:model="adjustNotes" rows="2" placeholder="Ex: Reposição de estoque, contagem física..." />
                @error('adjustNotes') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end gap-3 pt-1">
                <flux:modal.close>
                    <flux:button variant="ghost"
                        wire:click="$set('adjustingProductId', null)">
                        Cancelar
                    </flux:button>
                </flux:modal.close>
                <flux:button
                    wire:click="applyAdjustment"
                    wire:loading.attr="disabled"
                    class="!bg-amber-500 !text-white hover:!bg-amber-600">
                    <span wire:loading.remove wire:target="applyAdjustment">Confirmar</span>
                    <span wire:loading wire:target="applyAdjustment">Salvando...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
