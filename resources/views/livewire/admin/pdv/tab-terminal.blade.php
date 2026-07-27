<div class="flex flex-col flex-1 min-h-0 bg-zinc-50 text-neutral-900 dark:bg-[#0f1926] dark:text-neutral-100" x-data="pdvApp()" x-effect="watchPdvStep($wire.step)">

    {{-- ══ Toast: produto adicionado ══ --}}
    <div
        x-show="toastMessage"
        x-transition
        x-text="toastMessage"
        class="fixed top-4 right-4 z-50 rounded-lg bg-neutral-900 text-white text-sm font-semibold px-4 py-2.5 shadow-lg dark:bg-amber-500 dark:text-white"
        style="display: none;"
    ></div>

    {{-- ══ Header ══ --}}
    <div class="flex items-center justify-between gap-4 px-5 py-3 bg-white border-b border-neutral-200 shadow-sm dark:bg-zinc-900 dark:border-zinc-800 shrink-0">
        <div class="flex items-center gap-3 min-w-0">
            <div class="size-9 rounded-lg bg-amber-500 text-white flex items-center justify-center shadow-sm shadow-amber-500/20 dark:bg-amber-400 dark:text-white">
                <flux:icon.computer-desktop class="size-5" />
            </div>
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h1 class="text-base font-bold text-neutral-900 dark:text-neutral-100">Mesas / Comandas</h1>
                    @if ($cashSessionId)
                        <span class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">
                            <span class="size-1.5 rounded-full bg-green-500"></span>
                            Caixa aberto
                        </span>
                    @endif
                </div>
                <p class="hidden sm:block text-xs text-neutral-500 dark:text-neutral-400 truncate">
                    {{ $this->branches->firstWhere('id', $selectedBranchId)?->name ?? $this->branches->first()?->name ?? 'Filial' }}
                    @if ($cashSessionId && $this->shiftStats['terminal'])
                        · {{ $this->shiftStats['terminal'] }}
                    @endif
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            @if ($this->branches->count() > 1)
                <flux:select wire:model.live="selectedBranchId" class="w-56">
                    @foreach ($this->branches as $branch)
                        <flux:select.option value="{{ $branch->id }}">{{ $branch->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <span class="text-sm text-neutral-500 dark:text-neutral-400">
                    {{ $this->branches->first()?->name ?? '—' }}
                </span>
            @endif

            <button
                @click="toggleSidebar()"
                :title="sidebarHidden ? 'Mostrar menu lateral' : 'Ocultar menu lateral'"
                class="p-2 rounded-lg border border-neutral-200 text-neutral-500 hover:text-neutral-800 hover:bg-neutral-50 dark:border-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-neutral-200 transition-colors"
            >
                <svg x-show="!sidebarHidden" xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7M4 12h16" />
                </svg>
                <svg x-show="sidebarHidden" xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 5l7 7-7 7M20 12H4" />
                </svg>
            </button>
        </div>
    </div>

        <div class="flex flex-col lg:flex-row flex-1 overflow-hidden relative p-3 gap-3">


            @php
                $mesaNotCommitted = ! $openTabOrderId && ! $selectedTableId;
            @endphp

            @if ($mesaNotCommitted)
                {{-- ── Seleção de comanda: painel de cards, um clique entra na mesa/comanda ── --}}
                <div class="flex-1 overflow-y-auto p-4">
                    <div class="w-full space-y-6">
                        <div class="text-center">
                            <div class="mx-auto size-14 rounded-full bg-amber-100 flex items-center justify-center dark:bg-amber-900/40 mb-3">
                                <flux:icon.table-cells class="size-7 text-amber-500 dark:text-amber-400" />
                            </div>
                            <h2 class="text-lg font-bold text-neutral-800 dark:text-neutral-100">Selecione a comanda</h2>
                            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Toque na mesa antes de lançar os produtos.</p>
                        </div>

                        @error('order')
                            <p class="text-xs font-semibold text-red-600 dark:text-red-400 text-center">{{ $message }}</p>
                        @enderror
                        @error('selectedTableId')
                            <p class="text-xs font-semibold text-red-600 dark:text-red-400 text-center">{{ $message }}</p>
                        @enderror

                        @if (! $this->branchUsesRegisteredTables)
                            <div class="rounded-lg border border-amber-300 bg-amber-100/70 px-3 py-2 dark:border-amber-800 dark:bg-amber-900/20">
                                <p class="text-xs font-semibold text-amber-800 dark:text-amber-300">
                                    Esta filial ainda não tem mesas cadastradas.
                                </p>
                                <p class="text-xs text-amber-700 dark:text-amber-400 mt-0.5">
                                    @if ($isWaiter)
                                        Peça para o responsável pelo caixa cadastrar as mesas antes de abrir uma comanda.
                                    @else
                                        Cadastre ao menos uma mesa abaixo para poder abrir uma comanda.
                                    @endif
                                </p>
                            </div>
                        @endif

                        @if ($this->openTabs->isNotEmpty())
                            <div>
                                <p class="flex items-center gap-1.5 text-xs font-bold text-amber-800 dark:text-amber-300 mb-2">
                                    <flux:icon.clipboard-document-list class="size-3.5" />
                                    Comandas abertas
                                    <span class="font-normal text-amber-600 dark:text-amber-500">({{ $this->openTabs->count() }})</span>
                                </p>
                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-3">
                                    @foreach ($this->openTabs as $tab)
                                        <div
                                            role="button"
                                            tabindex="0"
                                            wire:click="selectOpenTab({{ $tab->id }})"
                                            wire:keydown.enter="selectOpenTab({{ $tab->id }})"
                                            class="group text-left rounded-xl border-2 border-amber-200 dark:border-amber-800/60 bg-white dark:bg-zinc-900 p-3 cursor-pointer hover:border-amber-400 hover:shadow-md transition-all flex flex-col gap-1"
                                        >
                                            <span class="font-bold text-sm text-neutral-800 dark:text-neutral-100 truncate">{{ $tab->table_label }}</span>
                                            <span class="text-xs font-semibold text-amber-600 dark:text-amber-400">R$ {{ number_format($tab->total, 2, ',', '.') }}</span>
                                            @unless ($isWaiter)
                                                <button
                                                    type="button"
                                                    wire:click.stop="proceedToCloseTab({{ $tab->id }})"
                                                    class="mt-1 self-start text-xs text-neutral-400 hover:text-red-600 dark:hover:text-red-400"
                                                >
                                                    Fechar
                                                </button>
                                            @endunless
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if ($this->availableTables->isNotEmpty())
                            <div>
                                <p class="flex items-center gap-1.5 text-xs font-bold text-amber-800 dark:text-amber-300 mb-2">
                                    <flux:icon.plus-circle class="size-3.5" />
                                    Nova comanda
                                </p>
                                <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-3">
                                    @foreach ($this->availableTables as $table)
                                        <button
                                            type="button"
                                            wire:click="$set('selectedTableId', {{ $table->id }})"
                                            class="rounded-xl border-2 border-neutral-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-4 text-center hover:border-amber-400 hover:shadow-md transition-all"
                                        >
                                            <span class="block text-[10px] font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">Mesa</span>
                                            <span class="block text-xl font-black text-neutral-800 dark:text-neutral-100">{{ $table->number }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @elseif ($this->branchUsesRegisteredTables)
                            <p class="text-xs text-neutral-500 dark:text-neutral-400 text-center">Nenhuma mesa livre no momento.</p>
                        @endif
                    </div>
                </div>
            @else

            {{-- ── Categorias: chips horizontais no mobile, coluna fixa no desktop ── --}}
            <div class="flex w-full lg:w-48 shrink-0 flex-col rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                <div class="hidden lg:block px-3 py-3 border-b border-neutral-100 dark:border-zinc-800 shrink-0">
                    <p class="text-[11px] font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">Categorias</p>
                </div>
                <div class="flex flex-row lg:flex-col gap-1.5 lg:gap-1 overflow-x-auto lg:overflow-x-visible lg:overflow-y-auto p-2 lg:flex-1">
                    <button
                        wire:click="selectCategory(null)"
                        class="shrink-0 lg:w-full lg:shrink text-left px-3 py-2 lg:py-2.5 rounded-lg text-sm whitespace-nowrap transition-colors {{ $activeCategoryId === null ? 'bg-amber-500 text-white font-bold shadow-sm shadow-amber-500/20 dark:bg-amber-400 dark:text-white' : 'bg-neutral-50 lg:bg-transparent text-neutral-600 hover:bg-amber-50 hover:text-amber-700 dark:bg-zinc-800 dark:lg:bg-transparent dark:text-neutral-300 dark:hover:bg-amber-900/20 dark:hover:text-amber-300' }}"
                    >
                        Todos
                    </button>
                    @foreach ($this->categories as $category)
                        <button
                            wire:click="selectCategory({{ $category->id }})"
                            class="shrink-0 lg:w-full lg:shrink text-left px-3 py-2 lg:py-2.5 rounded-lg text-sm whitespace-nowrap transition-colors {{ $activeCategoryId === $category->id ? 'bg-amber-500 text-white font-bold shadow-sm shadow-amber-500/20 dark:bg-amber-400 dark:text-white' : 'bg-neutral-50 lg:bg-transparent text-neutral-600 hover:bg-amber-50 hover:text-amber-700 dark:bg-zinc-800 dark:lg:bg-transparent dark:text-neutral-300 dark:hover:bg-amber-900/20 dark:hover:text-amber-300' }}"
                        >
                            {{ $category->name }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- ── Coluna central: Produtos ── --}}
            <div class="flex flex-col flex-1 min-h-0 min-w-0 overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 relative">

                {{-- Tipo de venda --}}
                <div class="px-4 py-3 shrink-0 border-b border-neutral-100 bg-white dark:bg-zinc-900 dark:border-zinc-800">
                    <span class="inline-flex items-center gap-1.5 text-sm font-semibold text-amber-700 dark:text-amber-400">
                        <flux:icon.table-cells class="size-4" />
                        Mesa/Comanda
                    </span>
                </div>

                {{-- Busca e código de barras --}}
                <div class="px-4 py-3 shrink-0 border-b border-neutral-100 bg-white dark:bg-zinc-900 dark:border-zinc-800">
                    <div class="flex flex-col lg:flex-row gap-2">
                        <flux:input
                            id="pdv-product-search"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Buscar produto por nome..."
                            icon="magnifying-glass"
                            class="flex-1"
                        />
                    
                    </div>
                </div>

                {{-- Grid de produtos --}}
                <div class="flex-1 overflow-y-auto p-4 {{ !empty($cart) ? 'pb-24 lg:pb-4' : '' }} bg-zinc-50 dark:bg-[#0f1926]/50">
                    @if ($this->products->isEmpty())
                        <div class="text-center py-12 text-neutral-400 dark:text-neutral-500">
                            <flux:icon.shopping-bag class="size-10 mx-auto mb-2 opacity-40" />
                            <p class="text-sm">Nenhum produto disponível</p>
                        </div>
                    @else
                        <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                            <table class="w-full table-fixed text-sm">
                                <thead class="bg-neutral-50 text-left text-[11px] uppercase tracking-wider text-neutral-400 dark:bg-zinc-800/60 dark:text-neutral-500">
                                    <tr>
                                        <th class="w-20 px-3 py-2 font-semibold"></th>
                                        <th class="px-1 py-2 font-semibold">Produto</th>
                                        <th class="w-28 px-3 py-2 font-semibold text-right">Qtd</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-100 dark:divide-zinc-800">
                                    @foreach ($this->products as $product)
                                        @php
                                            $productData = $this->buildProductDataForSidebar($product);
                                            $hasOptions = $productData !== null;
                                            $pdvCartQty = 0;
                                            foreach ($cart as $__key => $__item) {
                                                $__pid = (int) ($__item['product_id'] ?? (int) explode('_', (string) $__key)[0]);
                                                if ($__pid === $product->id) {
                                                    $pdvCartQty += $__item['qty'];
                                                }
                                            }
                                            $stockQty = $this->productStocks[$product->id] ?? null;
                                            $stockOut = $stockQty !== null && $pdvCartQty >= $stockQty;
                                        @endphp
                                        <tr>
                                            <td class="py-3 pl-3 pr-0">
                                                <button
                                                    type="button"
                                                    @if (!$stockOut)
                                                        @if ($hasOptions)
                                                            @click="addOrOpenOptionSelector(@js($productData), $wire)"
                                                        @else
                                                            wire:click="addProduct({{ $product->id }})"
                                                        @endif
                                                    @endif
                                                    {{ $stockOut ? 'disabled' : '' }}
                                                    class="block shrink-0 disabled:cursor-not-allowed"
                                                >
                                                    @if ($product->image_path)
                                                        <img
                                                            src="{{ $product->image_url }}"
                                                            alt="{{ $product->name }}"
                                                            class="size-16 rounded-lg object-cover bg-neutral-100 dark:bg-zinc-800"
                                                        />
                                                    @else
                                                        <div class="size-16 rounded-lg bg-neutral-100 flex items-center justify-center dark:bg-zinc-800">
                                                            <flux:icon.shopping-bag class="size-7 text-neutral-300 dark:text-zinc-500" />
                                                        </div>
                                                    @endif
                                                </button>
                                            </td>
                                            <td class="px-1 py-3">
                                                <button
                                                    type="button"
                                                    @if (!$stockOut)
                                                        @if ($hasOptions)
                                                            @click="addOrOpenOptionSelector(@js($productData), $wire)"
                                                        @else
                                                            wire:click="addProduct({{ $product->id }})"
                                                        @endif
                                                    @endif
                                                    {{ $stockOut ? 'disabled' : '' }}
                                                    class="text-left w-full disabled:cursor-not-allowed"
                                                >
                                                    <span class="block font-medium leading-snug text-neutral-800 dark:text-neutral-100">
                                                        {{ $product->name }}
                                                    </span>
                                                    <span class="block font-semibold text-amber-600 dark:text-amber-400">
                                                        R$ {{ number_format($product->effective_price, 2, ',', '.') }}
                                                    </span>
                                                    @if ($stockOut)
                                                        <span class="block text-[11px] font-semibold text-red-600 dark:text-red-400">Sem estoque</span>
                                                    @elseif ($stockQty !== null && $stockQty <= 5)
                                                        <span class="block text-[11px] font-semibold text-amber-600 dark:text-amber-400">Restam {{ $stockQty }}</span>
                                                    @endif
                                                </button>
                                            </td>
                                            <td class="px-3 py-3">
                                                <div class="flex items-center justify-end gap-1.5">
                                                    @if ($pdvCartQty > 0)
                                                        <button
                                                            @if ($hasOptions)
                                                                wire:click.stop="decrementProductFromCart({{ $product->id }})"
                                                            @else
                                                                wire:click.stop="updateCartQty('{{ $product->id }}', {{ $pdvCartQty - 1 }})"
                                                            @endif
                                                            class="size-7 rounded-full border flex items-center justify-center text-neutral-500 hover:bg-red-50 hover:text-red-600 hover:border-red-300 transition-colors dark:border-zinc-600"
                                                        >
                                                            <span class="text-sm font-bold leading-none">−</span>
                                                        </button>
                                                        <span class="w-5 text-center text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $pdvCartQty }}</span>
                                                    @endif
                                                    <button
                                                        @if (!$stockOut)
                                                            @if ($hasOptions)
                                                                @click.stop="addOrOpenOptionSelector(@js($productData), $wire)"
                                                            @else
                                                                wire:click.stop="addProduct({{ $product->id }})"
                                                            @endif
                                                        @endif
                                                        {{ $stockOut ? 'disabled' : '' }}
                                                        class="size-7 rounded-full text-white flex items-center justify-center transition-colors {{ $stockOut ? 'bg-neutral-300 cursor-not-allowed dark:bg-zinc-600' : 'bg-amber-500 hover:bg-amber-600 active:scale-90' }}"
                                                    >
                                                        <span class="text-sm font-bold leading-none">+</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                    @endif
                    @error('stock')
                        <div class="mt-3 flex items-center gap-2 bg-red-50 border border-red-200 text-red-700 rounded-xl px-3 py-2 text-sm dark:bg-red-900/20 dark:border-red-700 dark:text-red-300">
                            <flux:icon.exclamation-triangle class="size-4 shrink-0" />
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                {{-- ── Painel de seleção de opções (overlay) ── --}}
                <div
                    x-show="selectingProduct !== null"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="translate-x-full opacity-0"
                    x-transition:enter-end="translate-x-0 opacity-100"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="translate-x-0 opacity-100"
                    x-transition:leave-end="translate-x-full opacity-0"
                    class="absolute inset-0 z-10 bg-white dark:bg-zinc-900 flex flex-col overflow-hidden"
                    style="display:none"
                >
                    <div class="shrink-0 px-4 py-3 flex items-center gap-3 border-b bg-amber-500 dark:border-zinc-700">
                        <button
                            @click="selectingProduct = null; pendingSelections = {}"
                            class="text-white/70 hover:text-white p-1 rounded-lg hover:bg-white/10 transition-colors"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                            </svg>
                        </button>
                        <div class="flex-1 min-w-0">
                            <p class="text-white font-bold text-sm truncate" x-text="selectingProduct?.name"></p>
                            <p class="text-white/70 text-xs">
                                R$ <span x-text="getTotalWithOptions().toFixed(2).replace('.', ',')"></span>
                            </p>
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto px-4 py-4 space-y-5">
                        <template x-if="selectingProduct">
                            <div class="space-y-5">
                                <template x-for="group in selectingProduct.groups" :key="group.id">
                                    <div>
                                        <div class="flex items-center justify-between mb-3">
                                            <div class="flex items-center gap-2.5 min-w-0">
                                                <template x-if="group.image_url">
                                                    <img :src="group.image_url" class="w-10 h-10 rounded-lg object-cover shrink-0" />
                                                </template>
                                                <div>
                                                    <p class="font-bold text-sm text-neutral-800 dark:text-neutral-100" x-text="group.name"></p>
                                                    <p class="text-xs text-neutral-400 mt-0.5">
                                                        Máximo <span class="font-semibold" x-text="group.total_qty"></span> unidades
                                                    </p>
                                                </div>
                                            </div>
                                            <span class="text-xs font-bold px-2 py-1 rounded-full"
                                                :class="getGroupTotal(group.id) > group.total_qty
                                                    ? 'bg-red-100 text-red-700'
                                                    : getGroupTotal(group.id) === group.total_qty
                                                        ? 'bg-green-100 text-green-700'
                                                        : 'bg-neutral-100 text-neutral-500'">
                                                <span x-text="getGroupTotal(group.id)"></span>/<span x-text="group.total_qty"></span>
                                            </span>
                                        </div>

                                        <div class="space-y-1">
                                            <template x-for="option in group.options" :key="option.id">
                                                <div class="flex items-center gap-3 p-2.5 rounded-xl border transition-colors"
                                                    :class="option.paused
                                                        ? 'border-amber-100 bg-amber-50 dark:border-amber-900/30 dark:bg-amber-900/10'
                                                        : 'border-neutral-100 bg-neutral-50 dark:border-zinc-700 dark:bg-zinc-800'">
                                                    <template x-if="option.image_url">
                                                        <img :src="option.image_url"
                                                             class="w-12 h-12 rounded-xl object-cover shrink-0"
                                                             :class="option.paused ? 'grayscale opacity-50' : ''" />
                                                    </template>
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center gap-1.5 flex-wrap">
                                                            <p class="text-sm font-medium"
                                                                :class="option.paused ? 'text-neutral-400' : 'text-neutral-800 dark:text-neutral-100'"
                                                                x-text="option.name"></p>
                                                            <span x-show="option.paused"
                                                                class="inline-flex items-center gap-0.5 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-600">
                                                                Em pausa
                                                            </span>
                                                        </div>
                                                        <p class="text-xs text-neutral-500 mt-0.5 leading-snug"
                                                            x-show="option.description && !option.paused"
                                                            x-text="option.description"></p>
                                                        <p class="text-xs text-amber-600 mt-0.5"
                                                            x-show="option.additional_price > 0 && !option.paused"
                                                            x-text="'+R$ ' + option.additional_price.toFixed(2).replace('.', ',')"></p>
                                                    </div>
                                                    <div class="flex items-center gap-1.5 shrink-0">
                                                        <template x-if="!option.paused && group.fixed">
                                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300 text-xs font-semibold">
                                                                <span x-text="option.prefilledQty"></span> un.
                                                            </span>
                                                        </template>
                                                        <template x-if="!option.paused && !group.fixed">
                                                            <div class="flex items-center gap-1.5">
                                                                <button
                                                                    @click="if ((pendingSelections[group.id]?.[option.id] || 0) > 0) pendingSelections[group.id][option.id]--"
                                                                    class="w-7 h-7 rounded-full bg-amber-100 text-amber-500 font-bold text-base flex items-center justify-center">−</button>
                                                                <span class="w-7 text-center text-sm font-bold text-neutral-800 dark:text-neutral-100"
                                                                    x-text="pendingSelections[group.id]?.[option.id] || 0"></span>
                                                                <button
                                                                    @click="if (getGroupTotal(group.id) < group.total_qty) { if (!pendingSelections[group.id]) pendingSelections[group.id] = {}; pendingSelections[group.id][option.id] = (pendingSelections[group.id]?.[option.id] || 0) + 1 }"
                                                                    :disabled="getGroupTotal(group.id) >= group.total_qty"
                                                                    :class="getGroupTotal(group.id) >= group.total_qty ? 'bg-neutral-200 text-neutral-400 cursor-not-allowed' : 'bg-amber-500 text-white'"
                                                                    class="w-7 h-7 rounded-full font-bold text-base flex items-center justify-center">+</button>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    <div class="shrink-0 border-t border-neutral-100 dark:border-zinc-700 px-4 py-3">
                        <button
                            @click="confirmOptions($wire)"
                            :disabled="!canConfirm()"
                            :class="!canConfirm() ? 'opacity-50 cursor-not-allowed' : ''"
                            class="w-full bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-xl px-4 py-2.5 transition-colors text-sm"
                        >
                            Adicionar ao carrinho
                        </button>
                    </div>
                </div>
            </div>
            {{-- fim coluna central --}}
            @endif

            @unless ($mesaNotCommitted)
            {{-- ── Coluna direita: Carrinho / Pagamento / Sucesso / PIX ── --}}
            <div
                class="shrink-0 flex-col rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden lg:flex lg:w-[22rem] xl:w-[24rem]"
                :class="mobileCartOpen ? 'fixed inset-0 z-30 flex' : 'hidden'"
            >

                {{-- ── Sucesso ── --}}
                @if ($step === 'success')
                    <div class="flex flex-col h-full overflow-hidden">
                        <div class="px-4 py-3 border-b border-neutral-100 dark:border-zinc-800 shrink-0 flex items-center gap-2 bg-white dark:bg-zinc-900">
                            <div class="size-7 rounded-full bg-green-100 flex items-center justify-center dark:bg-green-900/40 shrink-0">
                                <flux:icon.check class="size-4 text-green-600 dark:text-green-400" />
                            </div>
                            <h2 class="font-bold text-neutral-800 dark:text-neutral-100">Pedido registrado!</h2>
                        </div>
                        <div class="flex-1 flex flex-col items-center justify-center p-5 space-y-4 overflow-y-auto">
                            <p class="font-mono text-amber-500 dark:text-amber-400 font-semibold text-2xl">{{ $lastOrderNumber }}</p>
                            @if ($lastOrderTotal)
                                <p class="text-sm text-neutral-500 dark:text-neutral-400">
                                    Total: <span class="font-semibold text-neutral-700 dark:text-neutral-200">R$ {{ number_format($lastOrderTotal, 2, ',', '.') }}</span>
                                </p>
                            @endif
                            @if ($changeAmount > 0)
                                <div class="w-full bg-amber-50 border border-amber-200 rounded-xl p-4 dark:bg-amber-900/20 dark:border-amber-700 text-center">
                                    <p class="text-xs text-amber-700 dark:text-amber-300 mb-1">Troco para o cliente</p>
                                    <p class="text-4xl font-bold text-amber-700 dark:text-amber-300">
                                        R$ {{ number_format($changeAmount, 2, ',', '.') }}
                                    </p>
                                </div>
                            @endif
                            <div class="w-full flex gap-2">
                                <flux:button wire:click="resetTerminal" variant="primary" size="base" class="flex-1">
                                    Novo pedido
                                </flux:button>
                                @if ($lastOrderId)
                                    <flux:button
                                        x-data
                                        x-on:click="window.open('{{ route('admin.orders.receipt', ['order' => $lastOrderId]) }}', '_blank')"
                                        variant="outline"
                                        size="base"
                                        icon="printer"
                                        title="Imprimir cupom"
                                    />
                                @endif
                            </div>
                        </div>
                    </div>

                {{-- ── Carrinho ── --}}
                @else
                    <div class="flex flex-col h-full overflow-hidden">
                        <div class="px-4 py-3 border-b border-neutral-100 dark:border-zinc-800 shrink-0 bg-white dark:bg-zinc-900">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    <button
                                        @click="mobileCartOpen = false"
                                        class="lg:hidden shrink-0 p-1.5 -ml-1.5 rounded-lg text-neutral-500 hover:bg-neutral-100 dark:hover:bg-zinc-800 dark:text-neutral-400"
                                        title="Voltar ao catálogo"
                                    >
                                        <flux:icon.chevron-left class="size-5" />
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="deselectOpenTab"
                                        class="hidden lg:flex shrink-0 p-1.5 -ml-1.5 rounded-lg text-neutral-500 hover:bg-neutral-100 dark:hover:bg-zinc-800 dark:text-neutral-400"
                                        title="Voltar para as comandas"
                                    >
                                        <flux:icon.arrow-left class="size-5" />
                                    </button>
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-bold text-neutral-400 dark:text-neutral-500 uppercase tracking-wider">Pedido atual</p>
                                        <h2 class="font-bold text-neutral-900 dark:text-neutral-100 truncate">Comanda</h2>
                                    </div>
                                </div>
                                <flux:button wire:click="deselectOpenTab" variant="ghost" size="sm" icon="arrow-left" class="lg:hidden shrink-0">
                                    Comandas
                                </flux:button>
                            </div>
                        </div>

                        {{-- Região do meio rola por dentro (overflow-y-auto) e o footer fica FORA
                             dela — garantia estrutural de que o footer nunca some/desloca por
                             causa de redimensionamento, sem depender de conta de altura. --}}
                        <div class="flex-1 min-h-0 overflow-y-auto flex flex-col">
                            {{-- Clicar no produto já lança direto na comanda — sem carrinho
                                 pendente nem botão de "enviar". A lista abaixo já é o pedido real. --}}
                            <div class="px-4 pt-3 pb-2 shrink-0 bg-white dark:bg-zinc-900 border-b border-neutral-100 dark:border-zinc-800 flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold text-neutral-400 dark:text-neutral-500 uppercase tracking-wider">Itens do pedido</p>
                                    <p class="text-xs font-semibold text-amber-700 dark:text-amber-400 truncate">
                                        @if ($openTabOrderId)
                                            {{ $this->openTabs->firstWhere('id', $openTabOrderId)?->table_label }}
                                        @elseif ($selectedTableId)
                                            Mesa {{ $this->availableTables->firstWhere('id', $selectedTableId)?->number }} · aguardando itens
                                        @endif
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="deselectOpenTab"
                                    class="shrink-0 min-h-11 px-3 rounded-lg text-xs font-semibold text-amber-600 hover:bg-amber-50 hover:text-amber-800 dark:text-amber-400 dark:hover:bg-amber-900/20 dark:hover:text-amber-200 transition-colors"
                                >
                                    Nova comanda
                                </button>
                            </div>

                            @error('order')
                                <p class="mx-4 mt-3 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            @error('stock')
                                <p class="mx-4 mt-3 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror

                            <div x-ref="itemsSection" class="flex-1 overflow-y-auto divide-y divide-neutral-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                                @forelse ($this->activeTabItems as $tabItem)
                                    <div class="px-3 py-2.5 text-sm hover:bg-neutral-50 dark:hover:bg-zinc-800/60 transition-colors">
                                        <p class="text-neutral-800 dark:text-neutral-100 font-medium leading-snug mb-1.5">{{ $tabItem->product_name }}</p>
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="flex items-center gap-1 shrink-0">
                                                <button
                                                    type="button"
                                                    wire:click="updateTabItemQuantity({{ $tabItem->id }}, {{ $tabItem->quantity - 1 }})"
                                                    class="size-11 rounded-full border flex items-center justify-center text-neutral-500 hover:bg-red-50 hover:text-red-600 hover:border-red-300 active:scale-90 transition-colors dark:border-zinc-600"
                                                >
                                                    <span class="text-lg font-bold leading-none">−</span>
                                                </button>
                                                <span class="w-6 text-center font-semibold text-neutral-800 dark:text-neutral-100">{{ $tabItem->quantity }}</span>
                                                <button
                                                    type="button"
                                                    wire:click="updateTabItemQuantity({{ $tabItem->id }}, {{ $tabItem->quantity + 1 }})"
                                                    class="size-11 rounded-full border flex items-center justify-center text-neutral-500 hover:bg-green-50 hover:text-green-600 hover:border-green-300 active:scale-90 transition-colors dark:border-zinc-600"
                                                >
                                                    <span class="text-lg font-bold leading-none">+</span>
                                                </button>
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <span class="text-right font-semibold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($tabItem->subtotal, 2, ',', '.') }}</span>
                                                <button
                                                    type="button"
                                                    wire:click="removeTabItem({{ $tabItem->id }})"
                                                    class="size-11 rounded-full flex items-center justify-center text-neutral-300 hover:bg-red-50 hover:text-red-600 dark:text-neutral-600 dark:hover:bg-red-900/20 dark:hover:text-red-400 transition-colors"
                                                    title="Remover item"
                                                >
                                                    <flux:icon.x-mark class="size-5" />
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="flex-1 flex items-center justify-center py-16 text-neutral-400 dark:text-neutral-500 bg-zinc-50 dark:bg-[#0f1926]/40">
                                        <div class="text-center">
                                            <flux:icon.shopping-cart class="size-10 mx-auto mb-2 opacity-40" />
                                            <p class="text-sm">Nenhum item ainda</p>
                                            <p class="text-xs mt-1 text-neutral-300 dark:text-neutral-600">Toque num produto do catálogo pra lançar na comanda</p>
                                        </div>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                        {{-- fim da região rolável do meio --}}

                            @if ($openTabOrderId)
                                @php $activeTabOrder = $this->openTabs->firstWhere('id', $openTabOrderId); @endphp
                                <div class="border-t border-neutral-100 px-4 py-4 space-y-3 bg-zinc-50 dark:border-zinc-800 dark:bg-[#0f1926]/70 shrink-0">
                                    @if ($activeTabOrder)
                                        <div class="space-y-1 text-sm">
                                            <div class="flex justify-between items-center text-neutral-500 dark:text-neutral-400">
                                                <span>Subtotal</span>
                                                <span>R$ {{ number_format($activeTabOrder->subtotal, 2, ',', '.') }}</span>
                                            </div>
                                            @if ($activeTabOrder->service_fee > 0)
                                                <div class="flex justify-between items-center text-neutral-500 dark:text-neutral-400">
                                                    <span>Taxa de serviço</span>
                                                    <span>+ R$ {{ number_format($activeTabOrder->service_fee, 2, ',', '.') }}</span>
                                                </div>
                                            @endif
                                            @if ($activeTabOrder->couvert_fee > 0)
                                                <div class="flex justify-between items-center text-neutral-500 dark:text-neutral-400">
                                                    <span>Couvert artístico</span>
                                                    <span>+ R$ {{ number_format($activeTabOrder->couvert_fee, 2, ',', '.') }}</span>
                                                </div>
                                            @endif
                                            @if ($activeTabOrder->manual_discount > 0)
                                                <div class="flex justify-between items-center text-green-600 dark:text-green-400">
                                                    <span>Desc. manual</span>
                                                    <span>− R$ {{ number_format($activeTabOrder->manual_discount, 2, ',', '.') }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                    <div class="flex justify-between items-end rounded-xl bg-white border border-neutral-200 px-3 py-3 dark:bg-zinc-900 dark:border-zinc-800">
                                        <span class="text-sm font-semibold text-neutral-500 dark:text-neutral-400">Total da comanda</span>
                                        <span class="text-2xl font-black text-neutral-900 dark:text-neutral-100">
                                            R$ {{ number_format($activeTabOrder?->total ?? 0, 2, ',', '.') }}
                                        </span>
                                    </div>
                                    @unless ($isWaiter)
                                        <flux:button wire:click="proceedToCloseTab({{ $openTabOrderId }})" variant="primary" size="base" class="w-full h-14 text-base">
                                            Fechar comanda
                                        </flux:button>
                                    @endunless
                                </div>
                            @endif
                    </div>
                @endif
                {{-- fim painel direito --}}

            </div>
            @endunless

            {{-- ── Barra flutuante do carrinho (mobile) ── --}}
            @if ($step !== 'payment' && ($openTabOrderId || $selectedTableId))
                <div
                    x-show="!mobileCartOpen"
                    class="lg:hidden fixed inset-x-3 bottom-3 z-20 flex items-center justify-between gap-3 rounded-xl bg-amber-500 px-4 py-3 text-white shadow-lg shadow-amber-500/30 dark:bg-amber-400"
                >
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-white/80 truncate">
                            {{ $openTabOrderId ? $this->openTabs->firstWhere('id', $openTabOrderId)?->table_label : 'Mesa '.$this->availableTables->firstWhere('id', $selectedTableId)?->number }}
                        </p>
                        <p class="text-lg font-black leading-none">
                            R$ {{ number_format($openTabOrderId ? ($this->openTabs->firstWhere('id', $openTabOrderId)?->total ?? 0) : 0, 2, ',', '.') }}
                        </p>
                    </div>
                    <button
                        @click="mobileCartOpen = true"
                        class="shrink-0 inline-flex items-center gap-2 rounded-lg bg-white/15 px-4 py-2.5 text-sm font-bold hover:bg-white/25 transition-colors"
                    >
                        <flux:icon.shopping-cart class="size-4" />
                        Ver comanda
                    </button>
                </div>
            @endif

            @if ($step === 'payment')
                <div class="absolute inset-0 z-30 flex items-center justify-center bg-amber-950/45 p-3 lg:p-6">
                    <div class="flex max-h-full w-full max-w-6xl flex-col overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="shrink-0 border-b border-neutral-100 px-4 py-3 dark:border-zinc-800">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    <flux:button wire:click="backToCatalog" variant="ghost" icon="arrow-left" size="sm" />
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-bold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">Checkout</p>
                                        <h2 class="text-lg font-black text-neutral-900 dark:text-neutral-100">
                                            Fechar comanda: {{ $this->openTabs->firstWhere('id', $closingTabOrderId)?->table_label }}
                                        </h2>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">Total</p>
                                    <p class="text-xl font-black text-neutral-900 dark:text-neutral-100">
                                        R$ {{ number_format($this->cartTotalAfterDiscount, 2, ',', '.') }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="grid min-h-0 flex-1 grid-cols-1 overflow-y-auto xl:grid-cols-[minmax(0,1fr)_22rem]">
                            <div class="space-y-5 p-4 lg:p-5">
                                <div class="grid gap-4 md:grid-cols-2">
                                    @if ($manualDiscountAllowed)
                                        <div class="space-y-1.5">
                                            <flux:label class="text-xs font-semibold">Desconto manual (opcional)</flux:label>
                                            @if ($manualDiscountAmount > 0)
                                                <div class="flex items-center justify-between px-3 py-2 bg-green-50 border border-green-200 rounded-xl dark:bg-green-900/20 dark:border-green-700">
                                                    <span class="text-sm font-semibold text-green-700 dark:text-green-300">
                                                        - R$ {{ number_format($manualDiscountAmount, 2, ',', '.') }}
                                                    </span>
                                                    <button wire:click="removeManualDiscount" class="text-xs text-red-500 hover:text-red-700 ml-2">Remover</button>
                                                </div>
                                            @else
                                                <div class="flex gap-2">
                                                    <flux:select wire:model.live="manualDiscountType" class="w-16 shrink-0">
                                                        <flux:select.option value="fixed">R$</flux:select.option>
                                                        <flux:select.option value="percent">%</flux:select.option>
                                                    </flux:select>
                                                    <flux:input
                                                        wire:model.live.debounce.500ms="manualDiscountInput"
                                                        placeholder="{{ $manualDiscountType === 'percent' ? '10' : '5,00' }}"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        class="flex-1"
                                                    />
                                                </div>
                                                @error('manual_discount')
                                                    <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                                @enderror
                                            @endif
                                        </div>
                                    @endif
                                </div>

                                @if ($this->rawServiceFeeAmount > 0 || $this->rawCouvertFeeAmount > 0)
                                    <div class="grid gap-2 md:grid-cols-2">
                                        @if ($this->rawServiceFeeAmount > 0)
                                            <label class="flex items-center justify-between gap-3 px-3 py-2 border rounded-xl dark:border-zinc-700 cursor-pointer">
                                                <span class="flex items-center gap-2">
                                                    <flux:checkbox wire:model.live="serviceFeeWaived" />
                                                    <span class="text-sm">Remover taxa de serviço</span>
                                                </span>
                                                <span class="text-xs font-semibold {{ $serviceFeeWaived ? 'text-neutral-400 line-through' : 'text-neutral-600 dark:text-neutral-300' }}">
                                                    R$ {{ number_format($this->rawServiceFeeAmount, 2, ',', '.') }}
                                                </span>
                                            </label>
                                        @endif
                                        @if ($this->rawCouvertFeeAmount > 0)
                                            <label class="flex items-center justify-between gap-3 px-3 py-2 border rounded-xl dark:border-zinc-700 cursor-pointer">
                                                <span class="flex items-center gap-2">
                                                    <flux:checkbox wire:model.live="couvertFeeWaived" />
                                                    <span class="text-sm">Remover couvert artístico</span>
                                                </span>
                                                <span class="text-xs font-semibold {{ $couvertFeeWaived ? 'text-neutral-400 line-through' : 'text-neutral-600 dark:text-neutral-300' }}">
                                                    R$ {{ number_format($this->rawCouvertFeeAmount, 2, ',', '.') }}
                                                </span>
                                            </label>
                                        @endif
                                    </div>
                                @endif

                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="space-y-1.5">
                                        @include('livewire.admin.pdv._customer-search')
                                    </div>

                                    <div class="space-y-1.5">
                                        <flux:label class="text-xs font-semibold">Método de pagamento</flux:label>
                                        <flux:radio.group wire:model.live="paymentMethod" variant="segmented" class="w-full">
                                            <flux:radio value="cash" label="Dinheiro" />
                                            <flux:radio value="pix" label="PIX" />
                                            <flux:radio value="credit_card" label="Cartão" />
                                        </flux:radio.group>

                                        @if ($paymentMethod === 'cash')
                                            <div class="pt-3 space-y-1.5">
                                                <flux:label class="text-xs font-semibold">Valor recebido</flux:label>
                                                <flux:input
                                                    wire:model="cashReceivedInput"
                                                    placeholder="Em branco = valor exato"
                                                    type="number"
                                                    step="0.01"
                                                    min="{{ $this->cartTotalAfterDiscount }}"
                                                />
                                                @if (filled($cashReceivedInput) && (float) str_replace(',', '.', $cashReceivedInput) >= $this->cartTotalAfterDiscount)
                                                    @php $changePreview = max(0, (float) str_replace(',', '.', $cashReceivedInput) - $this->cartTotalAfterDiscount); @endphp
                                                    <div class="bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 dark:bg-amber-900/20 dark:border-amber-700">
                                                        <p class="text-sm text-amber-700 dark:text-amber-300 font-semibold">
                                                            Troco: R$ {{ number_format($changePreview, 2, ',', '.') }}
                                                        </p>
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <div class="space-y-1.5">
                                    <flux:label class="text-xs font-semibold">Observação (opcional)</flux:label>
                                    <flux:textarea
                                        wire:model="notes"
                                        placeholder="Ex: sem cebola, embrulhar separado..."
                                        rows="3"
                                        class="resize-none"
                                    />
                                </div>

                                @error('order')
                                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="border-t border-neutral-100 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-[#0f1926]/70 xl:border-l xl:border-t-0">
                                <div class="flex h-full flex-col gap-4">
                                    <div>
                                        <p class="text-[11px] font-bold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">Resumo do pedido</p>
                                        <div class="mt-3 max-h-64 overflow-y-auto rounded-xl border border-neutral-200 bg-white divide-y divide-neutral-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
                                            @foreach ($this->closingTabItems as $tabItem)
                                                <div class="px-3 py-2">
                                                    <div class="flex items-start justify-between gap-3 text-sm">
                                                        <span class="font-medium text-neutral-800 dark:text-neutral-100">{{ $tabItem->quantity }}x {{ $tabItem->product_name }}</span>
                                                        <span class="shrink-0 font-semibold text-neutral-900 dark:text-neutral-100">R$ {{ number_format($tabItem->subtotal, 2, ',', '.') }}</span>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="mt-auto space-y-3">
                                        @if ($manualDiscountAmount > 0 || $this->serviceFeeAmount > 0 || $this->couvertFeeAmount > 0)
                                            <div class="space-y-1 text-sm">
                                                <div class="flex justify-between text-neutral-500 dark:text-neutral-400">
                                                    <span>Subtotal</span>
                                                    <span>R$ {{ number_format($this->cartTotal, 2, ',', '.') }}</span>
                                                </div>
                                                @if ($this->serviceFeeAmount > 0)
                                                    <div class="flex justify-between text-neutral-500 dark:text-neutral-400">
                                                        <span>Taxa de serviço</span>
                                                        <span>+ R$ {{ number_format($this->serviceFeeAmount, 2, ',', '.') }}</span>
                                                    </div>
                                                @endif
                                                @if ($this->couvertFeeAmount > 0)
                                                    <div class="flex justify-between text-neutral-500 dark:text-neutral-400">
                                                        <span>Couvert artístico</span>
                                                        <span>+ R$ {{ number_format($this->couvertFeeAmount, 2, ',', '.') }}</span>
                                                    </div>
                                                @endif
                                                @if ($manualDiscountAmount > 0)
                                                    <div class="flex justify-between text-green-600 dark:text-green-400">
                                                        <span>Desconto manual</span>
                                                        <span>- R$ {{ number_format($manualDiscountAmount, 2, ',', '.') }}</span>
                                                    </div>
                                                @endif
                                            </div>
                                        @endif

                                        <div class="rounded-xl border border-neutral-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                                            <div class="flex items-end justify-between gap-3">
                                                <span class="text-sm font-semibold text-neutral-500 dark:text-neutral-400">Total</span>
                                                <span class="text-2xl font-black text-neutral-900 dark:text-neutral-100">
                                                    R$ {{ number_format($this->cartTotalAfterDiscount, 2, ',', '.') }}
                                                </span>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-2 gap-2">
                                            <flux:button wire:click="backToCatalog" variant="ghost" size="base">
                                                Voltar
                                            </flux:button>
                                            <flux:button
                                                wire:click="confirmCloseTab"
                                                variant="primary"
                                                size="base"
                                                wire:loading.attr="disabled"
                                            >
                                                <span wire:loading.remove>Confirmar</span>
                                                <span wire:loading>Processando...</span>
                                            </flux:button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

        </div>
        {{-- fim 3 colunas --}}

</div>
