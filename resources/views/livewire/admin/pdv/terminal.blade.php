<div class="flex flex-col flex-1 min-h-0" x-data="pdvApp()">

    {{-- Header --}}
    <div class="flex items-center justify-between px-6 py-3 bg-white border-b dark:bg-zinc-900 dark:border-zinc-700 shrink-0">
        <div class="flex items-center gap-3">
            <flux:icon.computer-desktop class="size-5 text-purple-600" />
            <h1 class="text-lg font-bold text-neutral-800 dark:text-neutral-100">Terminal PDV</h1>
        </div>
        <div class="flex items-center gap-3">
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
                class="p-1.5 rounded-lg text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 dark:hover:bg-zinc-800 dark:hover:text-neutral-300 transition-colors"
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

    {{-- Tela: Sucesso --}}
    @if ($step === 'success')
        <div class="flex-1 flex items-center justify-center p-8">
            <div class="text-center space-y-4 max-w-sm">
                <div class="mx-auto size-16 rounded-full bg-green-100 flex items-center justify-center dark:bg-green-900/40">
                    <flux:icon.check-circle class="size-10 text-green-600 dark:text-green-400" />
                </div>
                <h2 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Pedido registrado!</h2>
                <p class="font-mono text-purple-600 dark:text-purple-400 font-semibold text-lg">{{ $lastOrderNumber }}</p>
                @if ($changeAmount > 0)
                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 dark:bg-amber-900/20 dark:border-amber-700">
                        <p class="text-sm text-amber-700 dark:text-amber-300">Troco para o cliente</p>
                        <p class="text-3xl font-bold text-amber-700 dark:text-amber-300">
                            R$ {{ number_format($changeAmount, 2, ',', '.') }}
                        </p>
                    </div>
                @endif
                <flux:button wire:click="resetTerminal" variant="primary" size="base" class="w-full">
                    Novo pedido
                </flux:button>
            </div>
        </div>

    {{-- Tela: PIX aguardando --}}
    @elseif ($step === 'pix')
        <div class="flex-1 flex items-center justify-center p-8">
            <div class="text-center space-y-4 max-w-sm">
                <div class="mx-auto size-16 rounded-full bg-blue-100 flex items-center justify-center dark:bg-blue-900/40">
                    <flux:icon.qr-code class="size-10 text-blue-600 dark:text-blue-400" />
                </div>
                <h2 class="text-xl font-bold text-neutral-800 dark:text-neutral-100">PIX gerado</h2>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">Pedido <span class="font-mono font-semibold text-purple-600">{{ $lastOrderNumber }}</span></p>
                @if ($pixCopyPaste)
                    <div class="bg-neutral-50 border rounded-xl p-3 text-left dark:bg-zinc-800 dark:border-zinc-600">
                        <p class="text-xs text-neutral-500 mb-1">Copia e cola</p>
                        <p class="font-mono text-xs break-all text-neutral-700 dark:text-neutral-300 select-all">{{ $pixCopyPaste }}</p>
                    </div>
                    <flux:button
                        x-data
                        x-on:click="navigator.clipboard.writeText('{{ $pixCopyPaste }}')"
                        variant="outline"
                        size="sm"
                        icon="clipboard"
                    >
                        Copiar código
                    </flux:button>
                @endif
                <flux:button wire:click="resetTerminal" variant="primary" size="base" class="w-full">
                    Novo pedido
                </flux:button>
            </div>
        </div>

    {{-- Tela: Pagamento --}}
    @elseif ($step === 'payment')
        <div class="flex-1 flex items-center justify-center p-8 overflow-y-auto">
            <div class="w-full max-w-md space-y-5">
                <div class="flex items-center gap-2">
                    <flux:button wire:click="backToCatalog" variant="ghost" icon="arrow-left" size="sm" />
                    <h2 class="text-lg font-bold text-neutral-800 dark:text-neutral-100">Pagamento</h2>
                </div>

                {{-- Resumo do pedido --}}
                <div class="bg-neutral-50 rounded-xl p-4 border dark:bg-zinc-800 dark:border-zinc-700 space-y-2">
                    @foreach ($cart as $cartKey => $item)
                        @php
                            $itemOptionsExtra = 0.0;
                            foreach ($item['options'] ?? [] as $group) {
                                foreach ($group['selections'] ?? [] as $sel) {
                                    $itemOptionsExtra += ($sel['qty'] ?? 0) * ($sel['additional_price'] ?? 0);
                                }
                            }
                            $itemUnitPrice = (float) $item['price'] + $itemOptionsExtra;
                        @endphp
                        <div class="text-sm">
                            <div class="flex justify-between">
                                <span class="text-neutral-700 dark:text-neutral-300">{{ $item['qty'] }}× {{ $item['name'] }}</span>
                                <span class="font-medium text-neutral-800 dark:text-neutral-100">
                                    R$ {{ number_format($itemUnitPrice * $item['qty'], 2, ',', '.') }}
                                </span>
                            </div>
                            @foreach ($item['options'] ?? [] as $group)
                                @foreach ($group['selections'] ?? [] as $sel)
                                    <p class="text-xs text-neutral-400 dark:text-neutral-500 ml-3 leading-tight">
                                        {{ $sel['qty'] }}× {{ $sel['name'] }}@if (($sel['additional_price'] ?? 0) > 0) <span class="text-amber-500">(+R$ {{ number_format($sel['additional_price'], 2, ',', '.') }})</span>@endif
                                    </p>
                                @endforeach
                            @endforeach
                        </div>
                    @endforeach
                    <div class="border-t pt-2 flex justify-between font-bold dark:border-zinc-600">
                        <span>Total</span>
                        <span class="text-purple-600 dark:text-purple-400">R$ {{ number_format($this->cartTotal, 2, ',', '.') }}</span>
                    </div>
                </div>

                {{-- Cliente (opcional) --}}
                <div class="space-y-2">
                    <flux:label>Cliente (opcional)</flux:label>
                    <div class="flex gap-2">
                        <flux:input
                            wire:model="customerPhone"
                            placeholder="Telefone..."
                            class="flex-1"
                        />
                        <flux:button wire:click="lookupCustomer" variant="outline" icon="magnifying-glass" />
                    </div>
                    @if ($customerFound)
                        <p class="text-sm text-green-600 dark:text-green-400">✓ {{ $customerName }}</p>
                    @elseif (filled($customerPhone))
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">Cliente não encontrado — pedido será lançado como balcão.</p>
                    @endif
                </div>

                {{-- Método de pagamento --}}
                <div class="space-y-2">
                    <flux:label>Método de pagamento</flux:label>
                    <flux:radio.group wire:model.live="paymentMethod" variant="segmented" class="w-full">
                        <flux:radio value="cash" label="Dinheiro" />
                        <flux:radio value="pix" label="PIX" />
                        <flux:radio value="credit_card" label="Cartão" />
                    </flux:radio.group>
                </div>

                {{-- Campo dinheiro recebido --}}
                @if ($paymentMethod === 'cash')
                    <div class="space-y-2">
                        <flux:label>Valor recebido (deixe em branco = valor exato)</flux:label>
                        <flux:input
                            wire:model="cashReceivedInput"
                            placeholder="R$ 0,00"
                            type="number"
                            step="0.01"
                            min="{{ $this->cartTotal }}"
                        />
                        @if (filled($cashReceivedInput) && (float) str_replace(',', '.', $cashReceivedInput) >= $this->cartTotal)
                            <p class="text-sm text-green-600 dark:text-green-400">
                                Troco: R$ {{ number_format(max(0, (float) str_replace(',', '.', $cashReceivedInput) - $this->cartTotal), 2, ',', '.') }}
                            </p>
                        @endif
                    </div>
                @endif

                @error('order')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror

                <flux:button
                    wire:click="processOrder"
                    variant="primary"
                    size="base"
                    class="w-full"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove>Confirmar pedido</span>
                    <span wire:loading>Processando...</span>
                </flux:button>
            </div>
        </div>

    {{-- Tela principal: Catálogo + Carrinho --}}
    @else
        <div class="flex flex-1 overflow-hidden">

            {{-- Coluna esquerda: Catálogo --}}
            <div class="flex flex-col flex-1 overflow-hidden border-r dark:border-zinc-700 relative">

                {{-- Busca e categorias --}}
                <div class="px-4 py-3 space-y-2 shrink-0 border-b bg-neutral-50 dark:bg-zinc-800/50 dark:border-zinc-700">
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        placeholder="Buscar produto..."
                        icon="magnifying-glass"
                    />
                    <div class="flex gap-2 flex-wrap">
                        <button
                            wire:click="selectCategory(null)"
                            class="px-3 py-1 rounded-full text-xs font-medium transition-colors
                                {{ $activeCategoryId === null
                                    ? 'bg-purple-600 text-white'
                                    : 'bg-white border text-neutral-600 hover:bg-neutral-100 dark:bg-zinc-700 dark:border-zinc-600 dark:text-neutral-300' }}"
                        >
                            Todos
                        </button>
                        @foreach ($this->categories as $category)
                            <button
                                wire:click="selectCategory({{ $category->id }})"
                                class="px-3 py-1 rounded-full text-xs font-medium transition-colors
                                    {{ $activeCategoryId === $category->id
                                        ? 'bg-purple-600 text-white'
                                        : 'bg-white border text-neutral-600 hover:bg-neutral-100 dark:bg-zinc-700 dark:border-zinc-600 dark:text-neutral-300' }}"
                            >
                                {{ $category->name }}
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Grid de produtos --}}
                <div class="flex-1 overflow-y-auto p-4">
                    @if ($this->products->isEmpty())
                        <div class="text-center py-12 text-neutral-400 dark:text-neutral-500">
                            <flux:icon.shopping-bag class="size-10 mx-auto mb-2 opacity-40" />
                            <p class="text-sm">Nenhum produto disponível</p>
                        </div>
                    @else
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
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
                                @endphp
                                <div class="group bg-white border rounded-xl p-3 text-left hover:border-purple-400 hover:shadow-md transition-all dark:bg-zinc-800 dark:border-zinc-700 dark:hover:border-purple-500 flex flex-col">
                                    {{-- Imagem --}}
                                    @if ($product->image_path)
                                        <img
                                            src="{{ $product->image_url }}"
                                            alt="{{ $product->name }}"
                                            class="w-full aspect-square object-cover rounded-lg mb-2"
                                        />
                                    @else
                                        <div class="w-full aspect-square bg-neutral-100 rounded-lg mb-2 flex items-center justify-center dark:bg-zinc-700">
                                            <flux:icon.shopping-bag class="size-8 text-neutral-300 dark:text-zinc-500" />
                                        </div>
                                    @endif

                                    {{-- Nome e preço --}}
                                    <p class="text-xs font-semibold text-neutral-800 dark:text-neutral-100 leading-tight line-clamp-2 flex-1">
                                        {{ $product->name }}
                                    </p>
                                    <p class="text-sm font-bold text-purple-600 dark:text-purple-400 mt-1">
                                        @if ($hasOptions && $product->is_variant)
                                            A partir de R$ {{ number_format($product->effective_price, 2, ',', '.') }}
                                        @else
                                            R$ {{ number_format($product->effective_price, 2, ',', '.') }}
                                        @endif
                                    </p>

                                    {{-- Badge de grupos de opções --}}
                                    @if ($hasOptions)
                                        <div class="flex flex-wrap gap-1 mt-1">
                                            @foreach ($product->optionGroups as $optGroup)
                                                <span class="text-[9px] font-semibold px-1.5 py-0.5 rounded-full bg-purple-50 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400">
                                                    {{ $optGroup->name }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif

                                    {{-- Controles de quantidade --}}
                                    <div class="flex items-center justify-end gap-1 mt-2">
                                        @if ($pdvCartQty > 0)
                                            <button
                                                @if ($hasOptions)
                                                    wire:click="decrementProductFromCart({{ $product->id }})"
                                                @else
                                                    wire:click="updateCartQty('{{ $product->id }}', {{ $pdvCartQty - 1 }})"
                                                @endif
                                                class="size-6 rounded-full border flex items-center justify-center text-neutral-500 hover:bg-red-50 hover:text-red-600 hover:border-red-300 transition-colors dark:border-zinc-600"
                                            >
                                                <span class="text-sm font-bold leading-none">−</span>
                                            </button>
                                            <span class="w-6 text-center text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $pdvCartQty }}</span>
                                        @endif
                                        <button
                                            @if ($hasOptions)
                                                @click="addOrOpenOptionSelector(@js($productData), $wire)"
                                            @else
                                                wire:click="addProduct({{ $product->id }})"
                                            @endif
                                            class="size-6 rounded-full bg-purple-600 text-white flex items-center justify-center hover:bg-purple-700 transition-colors active:scale-90"
                                        >
                                            <span class="text-sm font-bold leading-none">+</span>
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
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
                    {{-- Header do seletor --}}
                    <div class="shrink-0 px-4 py-3 flex items-center gap-3 border-b bg-purple-600 dark:border-zinc-700">
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

                    {{-- Grupos de opções --}}
                    <div class="flex-1 overflow-y-auto px-4 py-4 space-y-5">
                        <template x-if="selectingProduct">
                            <div class="space-y-5">
                                <template x-for="group in selectingProduct.groups" :key="group.id">
                                    <div>
                                        {{-- Cabeçalho do grupo --}}
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

                                        {{-- Opções --}}
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
                                                                    class="w-7 h-7 rounded-full bg-purple-100 text-purple-600 font-bold text-base flex items-center justify-center">−</button>
                                                                <span class="w-7 text-center text-sm font-bold text-neutral-800 dark:text-neutral-100"
                                                                    x-text="pendingSelections[group.id]?.[option.id] || 0"></span>
                                                                <button
                                                                    @click="if (getGroupTotal(group.id) < group.total_qty) { if (!pendingSelections[group.id]) pendingSelections[group.id] = {}; pendingSelections[group.id][option.id] = (pendingSelections[group.id]?.[option.id] || 0) + 1 }"
                                                                    :disabled="getGroupTotal(group.id) >= group.total_qty"
                                                                    :class="getGroupTotal(group.id) >= group.total_qty ? 'bg-neutral-200 text-neutral-400 cursor-not-allowed' : 'bg-purple-600 text-white'"
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

                    {{-- Footer: Adicionar ao carrinho --}}
                    <div class="shrink-0 border-t border-neutral-100 dark:border-zinc-700 px-4 py-3">
                        <button
                            @click="confirmOptions($wire)"
                            :disabled="!canConfirm()"
                            :class="!canConfirm() ? 'opacity-50 cursor-not-allowed' : ''"
                            class="w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold rounded-xl px-4 py-2.5 transition-colors text-sm"
                        >
                            Adicionar ao carrinho
                        </button>
                    </div>
                </div>
            </div>

            {{-- Coluna direita: Carrinho --}}
            <div class="w-80 flex flex-col bg-white dark:bg-zinc-900 shrink-0">

                <div class="px-4 py-3 border-b dark:border-zinc-700">
                    <h2 class="font-bold text-neutral-800 dark:text-neutral-100">
                        Carrinho
                        @if ($this->cartCount > 0)
                            <span class="ml-1 text-xs font-normal text-neutral-500">({{ $this->cartCount }} itens)</span>
                        @endif
                    </h2>
                </div>

                {{-- Itens do carrinho --}}
                <div class="flex-1 overflow-y-auto divide-y dark:divide-zinc-700">
                    @forelse ($cart as $cartKey => $item)
                        @php
                            $cartItemOptionsExtra = 0.0;
                            foreach ($item['options'] ?? [] as $group) {
                                foreach ($group['selections'] ?? [] as $sel) {
                                    $cartItemOptionsExtra += ($sel['qty'] ?? 0) * ($sel['additional_price'] ?? 0);
                                }
                            }
                            $cartItemUnitPrice = (float) $item['price'] + $cartItemOptionsExtra;
                        @endphp
                        <div class="px-4 py-3">
                            <div class="flex items-start gap-3">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-neutral-800 dark:text-neutral-100 truncate">
                                        {{ $item['name'] }}
                                    </p>
                                    @if (!empty($item['options']))
                                        @foreach ($item['options'] as $group)
                                            <p class="text-xs font-medium text-neutral-500 mt-0.5">{{ $group['group_name'] }}:</p>
                                            @foreach ($group['selections'] as $sel)
                                                <p class="text-xs text-neutral-400 leading-tight">
                                                    {{ $sel['qty'] }}× {{ $sel['name'] }}@if (($sel['additional_price'] ?? 0) > 0) <span class="text-amber-500">(+R$ {{ number_format($sel['additional_price'], 2, ',', '.') }})</span>@endif
                                                </p>
                                            @endforeach
                                        @endforeach
                                    @else
                                        <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                            R$ {{ number_format($cartItemUnitPrice, 2, ',', '.') }} cada
                                        </p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    <button
                                        wire:click="updateCartQty('{{ $cartKey }}', {{ $item['qty'] - 1 }})"
                                        class="size-6 rounded-full border flex items-center justify-center text-neutral-500 hover:bg-red-50 hover:text-red-600 hover:border-red-300 transition-colors dark:border-zinc-600"
                                    >
                                        <span class="text-sm font-bold leading-none">−</span>
                                    </button>
                                    <span class="w-6 text-center text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                                        {{ $item['qty'] }}
                                    </span>
                                    <button
                                        wire:click="updateCartQty('{{ $cartKey }}', {{ $item['qty'] + 1 }})"
                                        class="size-6 rounded-full border flex items-center justify-center text-neutral-500 hover:bg-green-50 hover:text-green-600 hover:border-green-300 transition-colors dark:border-zinc-600"
                                    >
                                        <span class="text-sm font-bold leading-none">+</span>
                                    </button>
                                </div>
                                <p class="w-16 text-right text-sm font-semibold text-neutral-800 dark:text-neutral-100 shrink-0">
                                    R$ {{ number_format($cartItemUnitPrice * $item['qty'], 2, ',', '.') }}
                                </p>
                            </div>
                        </div>
                    @empty
                        <div class="flex-1 flex items-center justify-center py-12 text-neutral-400 dark:text-neutral-500">
                            <div class="text-center">
                                <flux:icon.shopping-cart class="size-10 mx-auto mb-2 opacity-40" />
                                <p class="text-sm">Carrinho vazio</p>
                            </div>
                        </div>
                    @endforelse
                </div>

                {{-- Total e ações --}}
                @if (!empty($cart))
                    <div class="border-t px-4 py-4 space-y-3 dark:border-zinc-700">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-neutral-500 dark:text-neutral-400">Total</span>
                            <span class="text-xl font-bold text-neutral-800 dark:text-neutral-100">
                                R$ {{ number_format($this->cartTotal, 2, ',', '.') }}
                            </span>
                        </div>
                        <flux:button wire:click="proceedToPayment" variant="primary" size="base" class="w-full">
                            Ir para pagamento
                        </flux:button>
                        <flux:button wire:click="clearCart" variant="ghost" size="sm" class="w-full text-red-500 hover:text-red-700">
                            Limpar carrinho
                        </flux:button>
                    </div>
                @endif
            </div>

        </div>
    @endif

</div>
