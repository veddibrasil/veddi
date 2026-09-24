<div class="flex flex-col flex-1 min-h-0 bg-zinc-50 text-neutral-900 dark:bg-[#0f1926] dark:text-neutral-100" x-data="pdvApp()" x-effect="watchPdvStep($wire.step)">

    {{-- ══ Toast: produto adicionado ══ --}}
    <div
        x-show="toastMessage"
        x-transition
        x-text="toastMessage"
        class="fixed top-4 right-4 z-50 rounded-lg bg-neutral-900 text-white text-sm font-semibold px-4 py-2.5 shadow-lg dark:bg-amber-500 dark:text-white"
        style="display: none;"
    ></div>

    {{-- ══ Card flutuante: resultado do último pedido — não bloqueia o catálogo,
         cupom/nota fiscal já imprimem sozinhos (ver pdv-printer.js) ══ --}}
    @if ($lastOrderId)
        <div
            wire:key="order-success-{{ $lastOrderId }}"
            x-data
            x-init="@if ($changeAmount <= 0) setTimeout(() => $wire.dismissOrderSuccess(), 6000) @endif"
            class="fixed inset-x-0 top-4 z-40 mx-auto w-full max-w-sm px-4"
        >
            <div class="rounded-xl border border-neutral-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900 overflow-hidden">
                <div class="px-4 py-3 flex items-center gap-2 border-b border-neutral-100 dark:border-zinc-800">
                    <div class="size-7 rounded-full bg-green-100 flex items-center justify-center dark:bg-green-900/40 shrink-0">
                        <flux:icon.check class="size-4 text-green-600 dark:text-green-400" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-bold text-neutral-800 dark:text-neutral-100 text-sm">Pedido {{ $lastOrderNumber }} registrado</h2>
                        @if ($lastOrderTotal)
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">Total: R$ {{ number_format($lastOrderTotal, 2, ',', '.') }}</p>
                        @endif
                    </div>
                    <button aria-label="Fechar" wire:click="dismissOrderSuccess" type="button" class="shrink-0 p-1 rounded-lg text-neutral-400 hover:bg-neutral-100 dark:hover:bg-zinc-800" title="Fechar">
                        <flux:icon.x-mark class="size-4" />
                    </button>
                </div>
                <div class="p-4 space-y-3">
                    @if ($changeAmount > 0)
                        <div class="w-full bg-amber-50 border border-amber-200 rounded-xl p-3 dark:bg-amber-900/20 dark:border-amber-700 text-center">
                            <p class="text-xs text-amber-700 dark:text-amber-300 mb-1">Troco para o cliente</p>
                            <p class="text-3xl font-bold text-amber-700 dark:text-amber-300">
                                R$ {{ number_format($changeAmount, 2, ',', '.') }}
                            </p>
                        </div>
                    @endif

                    <flux:button wire:click="openCancelOrderModal({{ $lastOrderId }})" variant="ghost" size="sm" class="w-full text-red-500 hover:text-red-700">
                        Cancelar este pedido
                    </flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- ══ Modal: cancelar pedido — motivo obrigatório (select + descrição).
         Overlay bloqueante próprio, independente do card de sucesso (que pode
         sumir sozinho por timeout) e da lista de sessão: guarda seu próprio
         id/número e só fecha por ação explícita do operador (Voltar ou
         confirmar). Erro de validação/regra de negócio mantém o modal aberto. ══ --}}
    @if ($cancelModalOrderId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4">
            <div class="bg-white dark:bg-zinc-800 rounded-xl shadow-xl p-6 w-full max-w-md space-y-5">
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
                        <flux:icon.x-mark class="size-5 text-red-600 dark:text-red-400" />
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">Cancelar pedido {{ $cancelModalOrderNumber }}</h3>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Estoque será restaurado e sem reembolso automático. Informe o motivo — fica registrado com seu usuário como operador do cancelamento.</p>
                    </div>
                </div>

                <div class="space-y-2">
                    <flux:label>Motivo <span class="text-red-500">*</span></flux:label>
                    <flux:select wire:model.live="cancelReasonCode" placeholder="Selecione o motivo...">
                        @foreach (\App\Enums\OrderCancellationReason::cases() as $reason)
                            <flux:select.option value="{{ $reason->value }}">{{ $reason->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('cancelReasonCode')
                        <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-2">
                    <flux:label>
                        Descrição
                        @if ($cancelReasonCode === \App\Enums\OrderCancellationReason::Other->value)
                            <span class="text-red-500">*</span>
                        @else
                            <span class="text-neutral-400 font-normal">(opcional)</span>
                        @endif
                    </flux:label>
                    <flux:textarea
                        wire:model="cancelReasonDescription"
                        placeholder="Detalhes do cancelamento..."
                        rows="3"
                        class="resize-none"
                    />
                    @error('cancelReasonDescription')
                        <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                @error('cancel')
                    <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror

                <div class="flex justify-end gap-3 pt-1">
                    <flux:button wire:click="closeCancelOrderModal" variant="ghost" size="sm">Voltar</flux:button>
                    <flux:button wire:click="cancelPdvOrder({{ $cancelModalOrderId }})"
                                 variant="danger"
                                 size="sm"
                                 wire:loading.attr="disabled"
                                 wire:target="cancelPdvOrder({{ $cancelModalOrderId }})">
                        <span wire:loading.remove wire:target="cancelPdvOrder({{ $cancelModalOrderId }})">Confirmar cancelamento</span>
                        <span wire:loading wire:target="cancelPdvOrder({{ $cancelModalOrderId }})">Cancelando...</span>
                    </flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- ══ Header ══ --}}
    <div class="flex items-center justify-between gap-4 px-5 py-3 bg-white border-b border-neutral-200 shadow-sm dark:bg-zinc-900 dark:border-zinc-800 shrink-0">
        <div class="flex items-center gap-3 min-w-0">
            <div class="size-9 rounded-lg bg-amber-500 text-white flex items-center justify-center shadow-sm shadow-amber-500/20 dark:bg-amber-400 dark:text-white">
                <flux:icon.computer-desktop class="size-5" />
            </div>
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h1 class="text-base font-bold text-neutral-900 dark:text-neutral-100">Terminal PDV</h1>
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
        <div class="flex items-center gap-2 min-w-0 sm:shrink-0">
            @if ($this->branches->count() > 1)
                <flux:select wire:model.live="selectedBranchId" class="w-32 sm:w-56" :disabled="! empty($cart)" :title="! empty($cart) ? 'Finalize ou limpe o carrinho para trocar de filial' : null" aria-label="Filial">
                    @foreach ($this->branches as $branch)
                        <flux:select.option value="{{ $branch->id }}">{{ $branch->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <span class="text-sm text-neutral-500 dark:text-neutral-400">
                    {{ $this->branches->first()?->name ?? '—' }}
                </span>
            @endif

            <flux:button
                href="{{ route('admin.pdv.tabs') }}"
                wire:navigate
                variant="outline"
                size="sm"
                icon="table-cells"
                class="max-sm:hidden"
            >
                Mesas/Comandas
            </flux:button>

            @unless ($isWaiter || $isCaixa)
                <flux:button wire:click="openClosingReports" variant="outline" size="sm" icon="document-text" class="max-sm:hidden" title="Relatórios de fechamento" aria-label="Relatórios de fechamento" />
            @endunless

            @if ($cashSessionId && !in_array($step, ['open_cash', 'close_cash']))
                <flux:button wire:click="proceedToCloseCash" variant="outline" size="sm" icon="lock-closed" class="hover:text-red-600">
                    Fechar caixa
                </flux:button>
            @endif

            <flux:button
                @click="toggleSidebar()"
                variant="outline"
                size="sm"
                icon="arrows-pointing-out"
                ::title="sidebarHidden ? 'Mostrar menu lateral' : 'Ocultar menu lateral'"
            >
                Expandir
            </flux:button>
        </div>
    </div>

    {{-- ══ Relatórios de fechamento (overlay) ══ --}}
    @if ($showClosingReports)
        @include('livewire.admin.pdv._closing-reports')
    @endif

    {{-- ══ Barra de turno ══ --}}
    @if ($cashSessionId && !in_array($step, ['open_cash', 'close_cash']))
        @php $stats = $this->shiftStats; @endphp
        <div class="shrink-0 px-5 py-2 bg-amber-600 text-white border-b border-amber-700 dark:bg-amber-900/40 dark:border-amber-800 flex items-center gap-3 text-xs overflow-x-auto">
            <span class="flex items-center gap-1">
                <flux:icon.user-circle class="size-3.5" />
                {{ $stats['operator'] }}
            </span>
            <span class="text-white/30">|</span>
            <span class="flex items-center gap-1">
                <flux:icon.clock class="size-3.5" />
                Turno aberto há {{ $stats['duration'] }}
            </span>
            <span class="text-white/30">|</span>
            <span class="flex items-center gap-1">
                <flux:icon.shopping-bag class="size-3.5" />
                {{ $stats['orders'] }} pedido{{ $stats['orders'] !== 1 ? 's' : '' }}
            </span>
            @if ($stats['revenue'] > 0)
                <span class="text-white/30">|</span>
                    <span class="flex items-center gap-1 font-bold text-amber-100">
                    R$ {{ number_format($stats['revenue'], 2, ',', '.') }}
                </span>
            @endif
            <span class="text-white/30">|</span>
            <button
                type="button"
                wire:click="toggleCashMovementForm('supply')"
                class="inline-flex items-center gap-1 rounded-md bg-white/10 px-2 py-1 font-semibold text-white transition hover:bg-white/20"
            >
                <flux:icon.plus class="size-3.5" />
                Suprimento
            </button>
            <button
                type="button"
                wire:click="toggleCashMovementForm('withdrawal')"
                class="inline-flex items-center gap-1 rounded-md bg-white/10 px-2 py-1 font-semibold text-white transition hover:bg-white/20"
            >
                <flux:icon.minus class="size-3.5" />
                Sangria
            </button>
        </div>
        @if ($showCashMovementForm)
            <div class="shrink-0 border-b border-amber-100 bg-amber-50 px-5 py-3 dark:border-amber-900/30 dark:bg-amber-900/10">
                <div class="flex flex-col gap-2 lg:flex-row lg:items-start">
                    <div class="min-w-36">
                        <p class="text-xs font-bold uppercase tracking-wider text-amber-700 dark:text-amber-300">
                            {{ $cashMovementType === 'withdrawal' ? 'Sangria' : 'Suprimento' }}
                        </p>
                        <p class="text-xs text-amber-600/80 dark:text-amber-300/80">Movimentação manual do caixa.</p>
                    </div>
                    <div class="grid flex-1 gap-2 sm:grid-cols-[10rem_minmax(0,1fr)_auto]">
                        <div>
                            <flux:input
                                wire:model="cashMovementAmountInput"
                                placeholder="R$ 0,00"
                                type="number"
                                step="0.01"
                                min="0"
                            />
                            @error('cash_movement_amount')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <flux:input
                                wire:model="cashMovementReason"
                                wire:keydown.enter="registerCashMovement"
                                placeholder="Motivo"
                            />
                            @error('cash_movement_reason')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="flex gap-2">
                            <flux:button wire:click="registerCashMovement" variant="primary" size="sm">
                                Registrar
                            </flux:button>
                            <flux:button wire:click="toggleCashMovementForm('{{ $cashMovementType }}')" variant="ghost" size="sm">
                                Cancelar
                            </flux:button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- ══ Abertura de caixa ══ --}}
    @if ($step === 'open_cash')
        @include('livewire.admin.pdv._cash-open')
    {{-- ══ Fechamento de caixa ══ --}}
    @elseif ($step === 'close_cash')
        @include('livewire.admin.pdv._cash-close')
    {{-- ══ Layout 3 colunas: catálogo + painel direito ══ --}}
    @else
        <div class="flex flex-col lg:flex-row flex-1 overflow-hidden relative p-3 gap-3">

            {{-- ── Overlay: Histórico de sessão ── --}}
            @if ($showSessionHistory)
                <div class="absolute inset-0 z-20 flex flex-col bg-white dark:bg-zinc-900">
                    <div class="shrink-0 px-4 py-3 flex items-center gap-3 border-b dark:border-zinc-700 bg-white dark:bg-zinc-900">
                        <flux:button wire:click="backFromSessionHistory" variant="ghost" icon="arrow-left" size="sm" aria-label="Voltar para o catálogo" />
                        <h2 class="text-base font-bold text-neutral-800 dark:text-neutral-100">Pedidos da sessão</h2>
                    </div>
                    <div class="flex-1 overflow-y-auto p-4">
                        @if ($this->sessionOrders->isEmpty())
                            <div class="text-center py-12 text-neutral-500 dark:text-neutral-400">
                                <flux:icon.shopping-bag class="size-10 mx-auto mb-2 opacity-40" />
                                <p class="text-sm">Nenhum pedido nesta sessão ainda.</p>
                            </div>
                        @else
                            <div class="space-y-2">
                                @foreach ($this->sessionOrders as $sessionOrder)
                                    @php
                                        $isCancelled = in_array($sessionOrder->status, ['cancelled', 'refunded']);
                                        $isAwaitingPayment = $sessionOrder->status === 'awaiting_payment';
                                        $methodLabel = match(strtolower($sessionOrder->payment_method ?? '')) {
                                            'pix' => 'PIX',
                                            'credit_card' => 'Cartão',
                                            'cash' => 'Dinheiro',
                                            default => $sessionOrder->payment_method,
                                        };
                                    @endphp
                                    <div class="bg-white border rounded-xl px-4 py-3 dark:bg-zinc-800 dark:border-zinc-700 {{ $isCancelled ? 'opacity-60' : '' }}">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <span class="font-mono text-sm font-semibold text-amber-500 dark:text-amber-400">{{ $sessionOrder->order_number }}</span>
                                                    <span class="text-xs px-1.5 py-0.5 rounded-full font-medium
                                                        {{ $isCancelled ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : ($isAwaitingPayment ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400' : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400') }}">
                                                        {{ $isCancelled ? 'Cancelado' : ($isAwaitingPayment ? 'Ag. pagamento' : 'Pago') }}
                                                    </span>
                                                </div>
                                                <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">
                                                    {{ $sessionOrder->created_at->format('H:i') }}
                                                    · {{ $methodLabel }}
                                                    @if ($sessionOrder->customer && $sessionOrder->customer->phone !== 'pdv-guest')
                                                        · {{ $sessionOrder->customer->name }}
                                                    @endif
                                                </p>
                                                @if (($sessionOrder->discount > 0 || $sessionOrder->manual_discount > 0) && !$isCancelled)
                                                    <p class="text-xs text-green-600 dark:text-green-400 mt-0.5">
                                                        Desconto: R$ {{ number_format($sessionOrder->discount + $sessionOrder->manual_discount, 2, ',', '.') }}
                                                    </p>
                                                @endif
                                            </div>
                                            <div class="text-right shrink-0">
                                                <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100">
                                                    R$ {{ number_format($sessionOrder->total, 2, ',', '.') }}
                                                </p>
                                                @if ($isAwaitingPayment)
                                                    <button
                                                        wire:click="confirmSessionOrderPayment({{ $sessionOrder->id }})"
                                                        wire:confirm="Confirmar que o pagamento deste pedido foi recebido?"
                                                        class="block text-xs text-emerald-600 hover:text-emerald-700 mt-1 font-medium"
                                                    >
                                                        Confirmar pagamento
                                                    </button>
                                                @endif
                                                @if (!$isCancelled)
                                                    <button
                                                        wire:click="openCancelOrderModal({{ $sessionOrder->id }})"
                                                        class="text-xs text-red-400 hover:text-red-600 mt-1"
                                                    >
                                                        Cancelar
                                                    </button>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endif


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
                        <div class="text-center py-12 text-neutral-500 dark:text-neutral-400">
                            <flux:icon.shopping-bag class="size-10 mx-auto mb-2 opacity-40" />
                            <p class="text-sm">Nenhum produto disponível</p>
                        </div>
                    @else
                        <div class="grid grid-cols-3 md:grid-cols-4 gap-2">
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
                                <div class="flex flex-col overflow-hidden rounded-xl border bg-white dark:bg-zinc-900 {{ $pdvCartQty > 0 ? 'border-amber-400 ring-1 ring-amber-400 dark:border-amber-500 dark:ring-amber-500' : 'border-neutral-200 dark:border-zinc-800' }}">
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
                                        aria-label="Adicionar {{ $product->name }} ao carrinho"
                                        class="relative block w-full disabled:cursor-not-allowed"
                                    >
                                        @if ($product->image_path)
                                            <img
                                                src="{{ $product->image_url }}"
                                                alt="{{ $product->name }}"
                                                class="aspect-square w-full object-cover bg-neutral-100 dark:bg-zinc-800"
                                            />
                                        @else
                                            <div class="aspect-square w-full bg-neutral-100 flex items-center justify-center dark:bg-zinc-800">
                                                <flux:icon.shopping-bag class="size-6 text-neutral-300 dark:text-zinc-500" />
                                            </div>
                                        @endif
                                        @if ($pdvCartQty > 0)
                                            <span class="absolute top-1 right-1 min-w-[1.25rem] h-5 px-1 rounded-full bg-amber-500 text-white text-[11px] font-bold flex items-center justify-center shadow ring-2 ring-white dark:ring-zinc-900">
                                                {{ $pdvCartQty }}
                                            </span>
                                        @endif
                                    </button>
                                    <div class="flex flex-1 flex-col p-2">
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
                                            class="text-left w-full flex-1 disabled:cursor-not-allowed"
                                        >
                                            <span class="block text-xs font-medium leading-snug text-neutral-800 dark:text-neutral-100 line-clamp-2">
                                                {{ $product->name }}
                                            </span>
                                            <span class="block text-xs font-semibold text-amber-600 dark:text-amber-400">
                                                R$ {{ number_format($product->effective_price, 2, ',', '.') }}
                                            </span>
                                            @if ($stockOut)
                                                <span class="block text-[11px] font-semibold text-red-600 dark:text-red-400">Sem estoque</span>
                                            @elseif ($stockQty !== null && $stockQty <= 5)
                                                <span class="block text-[11px] font-semibold text-amber-600 dark:text-amber-400">Restam {{ $stockQty }}</span>
                                            @endif
                                        </button>
                                        <div class="flex items-center justify-end gap-1 mt-1.5">
                                            @if ($pdvCartQty > 0)
                                                <button
                                                    @if ($hasOptions)
                                                        wire:click.stop="decrementProductFromCart({{ $product->id }})"
                                                    @else
                                                        wire:click.stop="updateCartQty('{{ $product->id }}', {{ $pdvCartQty - 1 }})"
                                                    @endif
                                                    aria-label="Diminuir quantidade de {{ $product->name }}"
                                                    class="size-6 rounded-full border flex items-center justify-center text-neutral-500 hover:bg-red-50 hover:text-red-600 hover:border-red-300 transition-colors dark:border-zinc-600"
                                                >
                                                    <span class="text-xs font-bold leading-none">−</span>
                                                </button>
                                                <span class="w-4 text-center text-xs font-semibold text-neutral-800 dark:text-neutral-100">{{ $pdvCartQty }}</span>
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
                                                aria-label="Adicionar {{ $product->name }}"
                                                class="size-6 rounded-full text-white flex items-center justify-center transition-colors {{ $stockOut ? 'bg-neutral-300 cursor-not-allowed dark:bg-zinc-600' : 'bg-amber-500 hover:bg-amber-600 active:scale-90' }}"
                                            >
                                                <span class="text-xs font-bold leading-none">+</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
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
                        <button aria-label="Fechar seleção de opções"
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
                                                        <template x-if="group.min_qty > 0">
                                                            <span>Mín. <span class="font-semibold" x-text="group.min_qty"></span> ·</span>
                                                        </template>
                                                        Máximo <span class="font-semibold" x-text="group.total_qty"></span> unidades
                                                    </p>
                                                </div>
                                            </div>
                                            <span class="text-xs font-bold px-2 py-1 rounded-full"
                                                :class="getGroupTotal(group.id) > group.total_qty
                                                    ? 'bg-red-100 text-red-700'
                                                    : getGroupTotal(group.id) >= (group.min_qty || 0)
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
                                                                class="inline-flex items-center gap-0.5 text-[11px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-600">
                                                                Em pausa
                                                            </span>
                                                        </div>
                                                        <p class="text-xs text-neutral-500 mt-0.5 leading-snug"
                                                            x-show="option.description && !option.paused"
                                                            x-html="option.description"></p>
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
                                                                    @click="if (getGroupTotal(group.id) < group.total_qty && (!option.max_qty || (pendingSelections[group.id]?.[option.id] || 0) < option.max_qty)) { if (!pendingSelections[group.id]) pendingSelections[group.id] = {}; pendingSelections[group.id][option.id] = (pendingSelections[group.id]?.[option.id] || 0) + 1 }"
                                                                    :disabled="getGroupTotal(group.id) >= group.total_qty || (option.max_qty && (pendingSelections[group.id]?.[option.id] || 0) >= option.max_qty)"
                                                                    :class="(getGroupTotal(group.id) >= group.total_qty || (option.max_qty && (pendingSelections[group.id]?.[option.id] || 0) >= option.max_qty)) ? 'bg-neutral-200 text-neutral-400 cursor-not-allowed' : 'bg-amber-500 text-white'"
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

            {{-- ── Coluna direita: Carrinho / Pagamento / PIX ── --}}
            <div
                class="shrink-0 flex-col rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden lg:flex lg:w-[22rem] xl:w-[24rem]"
                :class="mobileCartOpen ? 'fixed inset-0 z-30 flex' : 'hidden'"
            >

                {{-- ── Carrinho ── --}}
                    <div class="flex flex-col h-full overflow-hidden">
                        <div class="px-4 py-3 [@media(max-height:700px)]:py-1.5 border-b border-neutral-100 dark:border-zinc-800 shrink-0 bg-white dark:bg-zinc-900">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    <button aria-label="Voltar ao catálogo"
                                        @click="mobileCartOpen = false"
                                        class="lg:hidden shrink-0 p-1.5 -ml-1.5 rounded-lg text-neutral-500 hover:bg-neutral-100 dark:hover:bg-zinc-800 dark:text-neutral-400"
                                        title="Voltar ao catálogo"
                                    >
                                        <flux:icon.chevron-left class="size-5" />
                                    </button>
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">Pedido atual</p>
                                        <h2 class="font-bold text-neutral-900 dark:text-neutral-100 truncate">
                                            Carrinho
                                            @if ($this->cartCount > 0)
                                                <span class="ml-1 text-xs font-normal text-neutral-500">({{ $this->cartCount }} itens)</span>
                                            @endif
                                        </h2>
                                    </div>
                                </div>
                                @if ($this->cartCount > 0)
                                    <span class="inline-flex size-9 items-center justify-center rounded-lg bg-amber-500 text-sm font-bold text-white shadow-sm shadow-amber-500/20 dark:bg-amber-400 dark:text-white shrink-0">
                                        {{ $this->cartCount }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        {{-- Região do meio rola por dentro (overflow-y-auto) e o footer fica FORA
                             dela — garantia estrutural de que o footer nunca some/desloca por
                             causa de redimensionamento, sem depender de conta de altura. --}}
                        <div class="flex-1 min-h-0 overflow-y-auto flex flex-col">

                        <div class="px-4 pt-2.5 pb-1 [@media(max-height:700px)]:pt-1.5 [@media(max-height:700px)]:pb-0.5 shrink-0 bg-white dark:bg-zinc-900">
                            <p class="text-[11px] font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">Itens do pedido</p>
                        </div>
                        <div x-ref="itemsSection" class="flex-1 overflow-y-auto divide-y divide-neutral-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
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
                                <div class="px-3 py-2.5 [@media(max-height:700px)]:py-1.5 hover:bg-neutral-50 dark:hover:bg-zinc-800/60 transition-colors flex gap-2.5">
                                    @if (!empty($item['image_url']))
                                        <img
                                            src="{{ $item['image_url'] }}"
                                            alt="{{ $item['name'] }}"
                                            class="size-11 [@media(max-height:700px)]:size-9 shrink-0 rounded-lg object-cover bg-neutral-100 dark:bg-zinc-800"
                                        />
                                    @endif
                                    <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-neutral-800 dark:text-neutral-100 leading-snug mb-1 [@media(max-height:700px)]:mb-0.5">
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
                                        <p class="text-xs text-neutral-500 dark:text-neutral-400 mb-1.5 [@media(max-height:700px)]:mb-0.5">
                                            R$ {{ number_format($cartItemUnitPrice, 2, ',', '.') }} cada
                                        </p>
                                    @endif
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="flex items-center gap-1 shrink-0">
                                            <button aria-label="Diminuir quantidade de {{ $item['name'] }}"
                                                wire:click="updateCartQty('{{ $cartKey }}', {{ $item['qty'] - 1 }})"
                                                class="size-11 [@media(max-height:700px)]:size-8 rounded-full border flex items-center justify-center text-neutral-500 hover:bg-red-50 hover:text-red-600 hover:border-red-300 active:scale-90 transition-colors dark:border-zinc-600"
                                            >
                                                <span class="text-lg font-bold leading-none">−</span>
                                            </button>
                                            <span class="w-6 text-center text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                                                {{ $item['qty'] }}
                                            </span>
                                            <button aria-label="Aumentar quantidade de {{ $item['name'] }}"
                                                wire:click="updateCartQty('{{ $cartKey }}', {{ $item['qty'] + 1 }})"
                                                class="size-11 [@media(max-height:700px)]:size-8 rounded-full border flex items-center justify-center text-neutral-500 hover:bg-green-50 hover:text-green-600 hover:border-green-300 active:scale-90 transition-colors dark:border-zinc-600"
                                            >
                                                <span class="text-lg font-bold leading-none">+</span>
                                            </button>
                                        </div>
                                        <p class="text-right text-sm font-semibold text-neutral-800 dark:text-neutral-100 shrink-0">
                                            R$ {{ number_format($cartItemUnitPrice * $item['qty'], 2, ',', '.') }}
                                        </p>
                                    </div>
                                    </div>
                                </div>
                            @empty
                                <div class="flex-1 flex items-center justify-center py-16 text-neutral-500 dark:text-neutral-400 bg-zinc-50 dark:bg-[#0f1926]/40">
                                    <div class="text-center">
                                        <flux:icon.shopping-cart class="size-10 mx-auto mb-2 opacity-40" />
                                        <p class="text-sm">Carrinho vazio</p>
                                        <p class="text-xs mt-1 text-neutral-500 dark:text-neutral-400">Clique em + para adicionar</p>
                                    </div>
                                </div>
                            @endforelse
                        </div>
                        {{-- Alça de arrasto (QA): puxa pra redimensionar a lista de itens acima --}}
                        <div
                            class="h-2.5 shrink-0 cursor-row-resize flex items-center justify-center bg-neutral-200/70 hover:bg-amber-400 dark:bg-zinc-700 dark:hover:bg-amber-500 transition-colors touch-none"
                            @pointerdown="startResize($refs.itemsSection, $event)"
                            title="Arraste para redimensionar"
                        >
                            <div class="w-8 h-1 rounded-full bg-neutral-400/70 dark:bg-neutral-500"></div>
                        </div>
                        </div>
                        {{-- fim da região rolável do meio --}}

                        @if (!empty($cart))
                            <div class="border-t border-neutral-100 px-4 py-4 space-y-3 [@media(max-height:700px)]:py-2 [@media(max-height:700px)]:space-y-1.5 bg-zinc-50 dark:border-zinc-800 dark:bg-[#0f1926]/70 shrink-0">
                                <div class="space-y-1 [@media(max-height:700px)]:space-y-0.5 text-sm [@media(max-height:700px)]:text-xs">
                                    <div class="flex justify-between items-center text-neutral-500 dark:text-neutral-400">
                                        <span>Subtotal</span>
                                        <span>R$ {{ number_format($this->cartTotal, 2, ',', '.') }}</span>
                                    </div>
                                    @if ($this->serviceFeeAmount > 0)
                                        <div class="flex justify-between items-center text-neutral-500 dark:text-neutral-400">
                                            <span>Taxa de serviço</span>
                                            <span>+ R$ {{ number_format($this->serviceFeeAmount, 2, ',', '.') }}</span>
                                        </div>
                                    @endif
                                    @if ($this->couvertFeeAmount > 0)
                                        <div class="flex justify-between items-center text-neutral-500 dark:text-neutral-400">
                                            <span>Couvert artístico</span>
                                            <span>+ R$ {{ number_format($this->couvertFeeAmount, 2, ',', '.') }}</span>
                                        </div>
                                    @endif
                                    @if ($manualDiscountAmount > 0)
                                        <div class="flex justify-between items-center text-green-600 dark:text-green-400">
                                            <span>Desc. manual</span>
                                            <span>− R$ {{ number_format($manualDiscountAmount, 2, ',', '.') }}</span>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex justify-between items-end rounded-xl bg-white border border-neutral-200 px-3 py-3 [@media(max-height:700px)]:py-1.5 dark:bg-zinc-900 dark:border-zinc-800">
                                    <span class="text-sm font-semibold text-neutral-500 dark:text-neutral-400">Total</span>
                                    <span class="text-2xl [@media(max-height:700px)]:text-lg font-black text-neutral-900 dark:text-neutral-100">
                                        R$ {{ number_format($this->cartTotalAfterDiscount, 2, ',', '.') }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2 [@media(max-height:700px)]:gap-1.5">
                                    <flux:button id="pdv-proceed-payment-btn" wire:click="proceedToPayment" variant="primary" size="base" class="flex-1 [@media(max-height:700px)]:!py-1.5">
                                        Ir para pagamento
                                    </flux:button>
                                    <flux:button wire:click="clearCart" wire:confirm="Limpar todos os itens do carrinho?" aria-label="Limpar carrinho" variant="ghost" size="sm" class="hidden [@media(max-height:700px)]:inline-flex shrink-0" icon="trash" title="Limpar carrinho" />
                                </div>
                                <flux:button wire:click="clearCart" wire:confirm="Limpar todos os itens do carrinho?" variant="ghost" size="sm" class="w-full text-red-500 hover:text-red-700 [@media(max-height:700px)]:hidden">
                                    Limpar carrinho
                                </flux:button>
                            </div>
                        @endif
                    </div>
                {{-- fim painel direito --}}

            </div>

            {{-- ── Barra flutuante do carrinho (mobile) ── --}}
            @if ($step !== 'payment' && !empty($cart))
                <div
                    x-show="!mobileCartOpen"
                    class="lg:hidden fixed inset-x-3 bottom-3 z-20 flex items-center justify-between gap-3 rounded-xl bg-amber-500 px-4 py-3 text-white shadow-lg shadow-amber-500/30 dark:bg-amber-400"
                >
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-white/80">{{ $this->cartCount }} {{ $this->cartCount === 1 ? 'item' : 'itens' }}</p>
                        <p class="text-lg font-black leading-none">R$ {{ number_format($this->cartTotalAfterDiscount, 2, ',', '.') }}</p>
                    </div>
                    <button
                        @click="mobileCartOpen = true"
                        class="shrink-0 inline-flex items-center gap-2 rounded-lg bg-white/15 px-4 py-2.5 text-sm font-bold hover:bg-white/25 transition-colors"
                    >
                        <flux:icon.shopping-cart class="size-4" />
                        Ver carrinho
                    </button>
                </div>
            @endif

            @if ($step === 'payment')
                @include('livewire.admin.pdv._payment-modal')
            @endif

        </div>
        {{-- fim 3 colunas --}}
    @endif

</div>
