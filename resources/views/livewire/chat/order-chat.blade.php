
<div
    class="
        relative flex flex-col bg-white overflow-hidden
        w-full h-full
        sm:w-[420px] sm:h-[90vh] sm:max-h-[820px] sm:rounded-2xl sm:shadow-2xl
    "
    x-data="{ ...chatApp(), snakeOpen: false, productSidebarOpen: false, productSidebarSide: 'right', selectingProduct: null, pendingSelections: {}, productSearch: '' }"
    x-init="
        $nextTick(() => { if ($wire.step === 'MENU_BROWSE') productSidebarOpen = true; });
        $watch('$wire.step', v => { if (v === 'MENU_BROWSE') productSidebarOpen = true; });
    "
>

    {{-- ═══════════════════════════════ HEADER ══════════════════════════════ --}}
    <div class="shrink-0" style="background: linear-gradient(135deg, var(--mc-red-dark) 0%, var(--mc-red) 60%, var(--mc-red-light) 100%);">

        {{-- Brand bar --}}
        <div class="flex items-center gap-3 px-4 py-3">

            {{-- Avatar / Logo --}}
            <div style="background: var(--mc-red)" class="w-12 h-12 rounded-full border-2 border-white/50 flex items-center justify-center shrink-0 shadow-lg overflow-hidden ring-1 ring-white/20">
                @if(isset($currentCompany) && $currentCompany->logo_path)
                    <img src="{{ $currentCompany->logo_url }}" alt="{{ $currentCompany->name }}" class="w-full h-full object-cover">
                @else
                    <img src="{{ asset('logo_branca.png') }}" alt="{{ $currentCompany->name ?? config('app.name') }}" class="w-full h-full object-cover">
                @endif
            </div>

            {{-- Brand info --}}
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-1.5">
                    <p class="text-white font-bold text-base leading-tight">{{ $currentCompany->name }}</p>
                </div>
                <div class="flex items-center gap-1.5 mt-0.5">
                    @if ($step === 'UNAVAILABLE')
                        <span class="w-2 h-2 rounded-full bg-red-400 shadow-sm shadow-red-300"></span>
                        <p class="text-white/75 text-xs">Indisponível no momento</p>
                    @elseif ($step === 'CLOSED')
                        <span class="w-2 h-2 rounded-full bg-red-400 shadow-sm shadow-red-300"></span>
                        <p class="text-white/75 text-xs">Fechado no momento</p>
                    @else
                        <span class="w-2 h-2 rounded-full bg-green-400 shadow-sm shadow-green-300"></span>
                        <p class="text-white/75 text-xs">Online • Pedidos abertos</p>
                    @endif
                </div>
            </div>

            {{-- Restart + End chat buttons --}}
            @if (! in_array($step, ['CLOSED', 'UNAVAILABLE']))
                <div class="flex items-center gap-1" x-data="{ confirmEnd: false }">
                    {{-- WhatsApp support --}}
                    @if ($selectedBranchId && $this->supportWhatsAppUrl && !in_array($step, ['IDENTIFY_PHONE', 'CLOSED', 'EDIT_PROFILE']))
                        <a
                            href="{{ $this->supportWhatsAppUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-white/70 hover:text-white transition-colors p-1.5 rounded-lg hover:bg-white/10"
                            title="Falar no WhatsApp"
                        >
                            <svg class="w-5 h-5" viewBox="0 0 32 32" fill="currentColor" aria-hidden="true">
                                <path d="M19.11 17.44c-.28-.14-1.64-.81-1.9-.9-.25-.09-.44-.14-.62.14-.18.28-.71.9-.88 1.09-.16.19-.32.21-.6.07-.28-.14-1.17-.43-2.24-1.37-.83-.74-1.39-1.66-1.55-1.94-.16-.28-.02-.43.12-.57.13-.13.28-.32.41-.49.13-.16.18-.28.28-.46.09-.18.05-.35-.02-.49-.07-.14-.62-1.5-.85-2.05-.22-.54-.45-.47-.62-.48h-.53c-.18 0-.46.07-.7.35-.25.28-.93.91-.93 2.22s.95 2.58 1.08 2.76c.14.18 1.87 2.86 4.53 4.02.63.27 1.12.43 1.5.55.63.2 1.2.17 1.65.1.5-.07 1.64-.67 1.87-1.31.23-.64.23-1.19.16-1.31-.06-.12-.25-.19-.53-.33zM16 3.2A12.77 12.77 0 0 0 4.8 22.02L3.2 28.8l6.93-1.56A12.78 12.78 0 1 0 16 3.2zm0 23.35c-2.08 0-4.11-.55-5.9-1.6l-.42-.25-4.11.92.95-4-.27-.43A10.58 10.58 0 1 1 16 26.55z"/>
                            </svg>
                        </a>
                    @endif

                    {{-- Order history --}}
                    @if ($customerId && !in_array($step, ['IDENTIFY_PHONE', 'CLOSED', 'EDIT_PROFILE', 'ORDER_HISTORY']))
                        <button
                            wire:click="goToOrderHistory"
                            class="text-white/70 hover:text-white transition-colors p-1.5 rounded-lg hover:bg-white/10"
                            title="Meus pedidos"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                            </svg>
                        </button>
                    @endif

                    {{-- Edit profile --}}
                    @if ($customerId && !in_array($step, ['ORDER_CONFIRMED', 'EDIT_PROFILE']))
                        <button
                            wire:click="openEditProfile"
                            class="text-white/70 hover:text-white transition-colors p-1.5 rounded-lg hover:bg-white/10"
                            title="Editar perfil"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </button>
                    @endif

                    {{-- Restart --}}
                    @if (!in_array($step, ['ORDER_CONFIRMED']))
                        <button
                            wire:click="startNewOrder"
                            class="text-white/70 hover:text-white transition-colors p-1.5 rounded-lg hover:bg-white/10"
                            title="Recomeçar"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </button>
                    @endif

                    {{-- Confirmation dialog --}}
                    <div
                        x-show="confirmEnd"
                        x-transition
                        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
                        style="display: none;"
                    >
                        <div class="bg-white rounded-2xl shadow-2xl p-6 max-w-xs w-full text-center space-y-4">
                            <div class="w-12 h-12 rounded-full flex items-center justify-center mx-auto text-2xl mc-bg-primary-light">⚠️</div>
                            <div>
                                <p class="font-bold text-gray-800 text-base">Encerrar conversa?</p>
                                <p class="text-sm text-gray-500 mt-1">Você perderá todo o histórico desta conversa. Esta ação não pode ser desfeita.</p>
                            </div>
                            <div class="flex gap-2">
                                <button
                                    x-on:click="confirmEnd = false"
                                    class="flex-1 px-4 py-2.5 rounded-xl border-2 border-gray-200 text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors"
                                >
                                    Cancelar
                                </button>
                                <button
                                    wire:click="endChat"
                                    x-on:click="confirmEnd = false"
                                    class="mc-btn-primary flex-1"
                                >
                                    Encerrar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Step progress dots --}}
        @php
            $currentIdx = match(true) {
                $step === 'BRANCH_SELECT'                                            => 0,
                $step === 'MENU_BROWSE'                                              => 1,
                $step === 'CART_REVIEW'                                              => 2,
                in_array($step, ['IDENTIFY_PHONE','RECOVER_ORDER',
                    'REGISTER_NAME','REGISTER_EMAIL','REGISTER_ADDRESS',
                    'CHECKOUT_COUPON','CHECKOUT_ORDER_TYPE',
                    'CHECKOUT_DELIVERY_ADDRESS','CHECKOUT_DELIVERY_FEE',
                    'CHECKOUT_SCHEDULE','CHECKOUT_NOTES','CHECKOUT_CPF',
                    'CHECKOUT_PAYMENT_METHOD','CHECKOUT_CONFIRM',
                    'EDIT_PROFILE','ORDER_HISTORY'])                                 => 3,
                in_array($step, ['PAYMENT_PIX','PAYMENT_CARD_FORM','ORDER_FAILED']) => 4,
                in_array($step, ['ORDER_CONFIRMED','ORDER_CANCELLED'])               => 5,
                default                                                              => 0,
            };
        @endphp
        <div class="flex items-center justify-center gap-1.5 pb-3 px-4">
            @for ($i = 0; $i < 5; $i++)
                <div class="mc-step-dot {{ $i < $currentIdx ? 'done' : ($i === $currentIdx ? 'active' : '') }}"></div>
            @endfor
        </div>
    </div>

    {{-- ══════════════════════════ MESSAGE HISTORY ═══════════════════════════ --}}
    <div
        class="flex-1 overflow-y-auto px-3 py-4 space-y-3 mc-chat-messages mc-scrollbar"
        id="mc-chat"
        x-init="
            const el = $el;
            const scroll = () => el.scrollTop = el.scrollHeight;
            scroll();
            $watch('$wire.messages', () => $nextTick(scroll));
        "
    >
        @foreach ($messages as $msg)
            <div class="flex {{ $msg['from'] === 'bot' ? 'justify-start' : 'justify-end' }}">

                @if ($msg['from'] === 'bot')
                    {{-- Bot avatar (logo do sistema) --}}
                    <div class="w-7 h-7 rounded-full shrink-0 mr-1.5 mt-0.5 overflow-hidden border border-white/20 shadow-sm">
                        <img src="{{ asset('logo_branca.png') }}" alt="{{ config('app.name') }}" class="w-full h-full object-cover p-0.5">
                    </div>
                @endif

                <div class="max-w-[75%] {{ $msg['from'] === 'bot' ? 'mc-bubble-bot text-gray-800' : 'mc-bubble-user text-white' }} px-3 py-2">
                    <p class="text-sm leading-relaxed whitespace-pre-line">{{ $msg['text'] }}</p>
                    <span class="block text-[10px] mt-0.5 text-right {{ $msg['from'] === 'bot' ? 'text-gray-400' : 'text-white/60' }}">
                        {{ $msg['time'] }}
                    </span>
                </div>
            </div>
        @endforeach

        @if ($isLoading)
            <div class="flex justify-start">
                <div class="w-7 h-7 rounded-full shrink-0 mr-1.5 mt-0.5 overflow-hidden border border-white/20 shadow-sm">
                    <img src="{{ asset('logo_branca.png') }}" alt="{{ config('app.name') }}" class="w-full h-full object-cover p-0.5">
                </div>
                <div class="mc-bubble-bot px-4 py-3">
                    <div class="flex gap-1.5 items-center">
                        <div class="w-2 h-2 rounded-full bg-gray-400 mc-dot-1"></div>
                        <div class="w-2 h-2 rounded-full bg-gray-400 mc-dot-2"></div>
                        <div class="w-2 h-2 rounded-full bg-gray-400 mc-dot-3"></div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- ════════════════════════════ INPUT AREA ══════════════════════════════ --}}
    <div class="border-t border-gray-100 bg-white px-4 py-3 shrink-0 overflow-y-auto max-h-[60vh] mc-scrollbar">

        {{-- ── IDENTIFY_PHONE ── --}}
        @if ($step === 'IDENTIFY_PHONE')
            <div wire:key="step-identify-phone" class="space-y-2.5">
                <p class="text-xs text-center text-gray-500 pb-1">Informe seu telefone para continuar com o pedido</p>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">Telefone com DDD</label>
                    <input
                        wire:model="phone"
                        type="tel"
                        placeholder="(44) 99999-9999"
                        class="mc-input"
                        wire:keydown.enter="submitPhone"
                        autocomplete="tel"
                        inputmode="numeric"
                        x-on:input="$event.target.value = formatPhone($event.target.value)"
                    />
                    @error('phone') <p class="text-red-600 text-xs mt-1 flex items-center gap-1"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <button wire:click="submitPhone" class="mc-btn-primary" wire:loading.attr="disabled" wire:target="submitPhone">
                    <span wire:loading.remove wire:target="submitPhone">Continuar →</span>
                    <span wire:loading wire:target="submitPhone">Verificando...</span>
                </button>
            </div>

        {{-- ── RECOVER_ORDER ── --}}
        @elseif ($step === 'RECOVER_ORDER')
            <div wire:key="step-recover-order" class="space-y-3">
                @if ($pendingOrderSummary)
                    <div class="bg-orange-50 border border-orange-200 rounded-xl p-3 space-y-1.5">
                        <p class="text-xs font-bold text-orange-700 uppercase tracking-wide">Pedido em aberto</p>
                        <p class="text-sm font-semibold text-gray-800">#{{ $pendingOrderSummary['order_number'] }}</p>
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-gray-500">
                                {{ $pendingOrderSummary['items_count'] }} {{ $pendingOrderSummary['items_count'] === 1 ? 'item' : 'itens' }}
                                · {{ $pendingOrderSummary['payment_method'] }}
                            </span>
                            <span class="text-sm font-bold mc-text-primary">
                                R$ {{ number_format($pendingOrderSummary['total'], 2, ',', '.') }}
                            </span>
                        </div>
                    </div>
                @endif
                <div class="flex gap-2">
                    <button
                        wire:click="discardPendingOrder"
                        class="mc-btn-secondary flex-1"
                        wire:loading.attr="disabled"
                        wire:target="discardPendingOrder,resumePendingOrder"
                    >
                        Novo pedido
                    </button>
                    <button
                        wire:click="resumePendingOrder"
                        class="mc-btn-primary flex-1"
                        wire:loading.attr="disabled"
                        wire:target="resumePendingOrder,discardPendingOrder"
                    >
                        <span wire:loading.remove wire:target="resumePendingOrder">Continuar →</span>
                        <span wire:loading wire:target="resumePendingOrder">Recuperando...</span>
                    </button>
                </div>
            </div>

        {{-- ── ORDER_HISTORY ── --}}
        @elseif ($step === 'ORDER_HISTORY')
            <div wire:key="step-order-history">
                @if (empty($orderHistory))
                    <p class="text-sm text-center text-gray-500 py-2">Nenhum pedido encontrado.</p>
                @else
                    <div class="max-h-64 overflow-y-auto mc-scrollbar space-y-2 pr-0.5 mb-2">
                        @foreach ($orderHistory as $order)
                            @php
                                $statusColor = match($order['status']) {
                                    'delivered' => ['bg' => 'bg-green-100', 'text' => 'text-green-700'],
                                    'paid', 'preparing', 'ready', 'out_for_delivery' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700'],
                                    'cancelled', 'refunded' => ['bg' => 'bg-red-100', 'text' => 'text-red-600'],
                                    default => ['bg' => 'bg-amber-100', 'text' => 'text-amber-700'],
                                };
                            @endphp
                            <div class="bg-gray-50 border border-gray-100 rounded-xl p-3 space-y-1.5">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-xs font-bold text-gray-700 truncate">#{{ $order['order_number'] }}</p>
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full shrink-0 {{ $statusColor['bg'] }} {{ $statusColor['text'] }}">
                                        {{ $order['status_label'] }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-gray-400">
                                        {{ $order['items_count'] }} {{ $order['items_count'] === 1 ? 'item' : 'itens' }}
                                        · {{ $order['payment_method'] }}
                                        · {{ $order['order_type'] === 'pickup' ? 'Retirada' : 'Entrega' }}
                                    </span>
                                    <span class="text-sm font-bold mc-text-primary">
                                        R$ {{ number_format($order['total'], 2, ',', '.') }}
                                    </span>
                                </div>
                                <p class="text-[10px] text-gray-400">{{ $order['created_at'] }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
                <button wire:click="backFromOrderHistory" class="mc-btn-secondary w-full">
                    ← Voltar
                </button>
            </div>

        {{-- ── REGISTER_NAME ── --}}
        @elseif ($step === 'REGISTER_NAME')
            <div wire:key="step-register-name" class="space-y-2.5">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">Seu nome completo</label>
                    <input wire:model="name" type="text" placeholder="Ex: João Silva" class="mc-input"
                        wire:keydown.enter="submitName" autocomplete="name" />
                    @error('name') <p class="text-red-600 text-xs mt-1 flex items-center gap-1"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <div class="flex gap-2">
                    <button wire:click="backToIdentifyPhone" class="mc-btn-secondary flex-shrink-0">← Voltar</button>
                    <button wire:click="submitName" class="mc-btn-primary flex-1">Continuar →</button>
                </div>
            </div>

        {{-- ── REGISTER_EMAIL ── --}}
        @elseif ($step === 'REGISTER_EMAIL')
            <div wire:key="step-register-email" class="space-y-2.5">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">Seu e-mail <span class="font-normal text-gray-400">(opcional)</span></label>
                    <input wire:model="email" type="email" placeholder="seu@email.com" class="mc-input"
                        wire:keydown.enter="submitEmail" autocomplete="email" />
                    @error('email') <p class="text-red-600 text-xs mt-1 flex items-center gap-1"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <div class="flex gap-2">
                    <button wire:click="backToRegisterName" class="mc-btn-secondary flex-shrink-0">← Voltar</button>
                    <button wire:click="submitEmail" class="mc-btn-primary flex-1">Continuar →</button>
                </div>
                <button wire:click="skipEmail" class="mc-btn-outline">
                    Não quero informar o e-mail
                </button>
            </div>

        {{-- ── REGISTER_ADDRESS ── --}}
        @elseif ($step === 'REGISTER_ADDRESS')
            <div wire:key="step-register-address" class="space-y-2">

                <x-chat.map-picker prefix="reg" />

                <x-chat.address-fields />

                <div class="flex gap-2 mt-1">
                    <button wire:click="backToRegisterEmail" class="mc-btn-secondary flex-shrink-0">← Voltar</button>
                    <button wire:click="submitAddress" class="mc-btn-primary flex-1">Salvar e continuar →</button>
                </div>
            </div>

        {{-- ── BRANCH_SELECT ── --}}
        @elseif ($step === 'BRANCH_SELECT')
            <div class="space-y-2" x-init="$nextTick(() => branchLocate())">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Escolha a filial</p>
                <p id="branch-locate-error" class="text-red-500 text-xs hidden"></p>
                <div id="branch-list" class="space-y-2">
                @foreach ($this->branches as $branch)
                    @php $branchOpen = $branch->isOpen(); @endphp
                    <button
                        wire:click="selectBranch({{ $branch->id }})"
                        data-branch-id="{{ $branch->id }}"
                        data-branch-open="{{ $branchOpen ? '1' : '0' }}"
                        data-branch-lat="{{ $branch->deliverySetting?->branch_latitude ?? '' }}"
                        data-branch-lng="{{ $branch->deliverySetting?->branch_longitude ?? '' }}"
                        class="w-full text-left rounded-xl p-3 active:scale-[0.98] {{ $branchOpen ? 'mc-card-hover' : 'border-2 border-gray-100 bg-gray-50 opacity-70' }}"
                    >
                        <div class="flex items-start gap-3">
                            <div class="w-9 h-9 rounded-lg {{ $branchOpen ? 'mc-bg-primary-light' : 'bg-gray-200' }} flex items-center justify-center shrink-0">
                                <span class="text-lg">🏪</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="font-bold text-sm {{ $branchOpen ? 'text-gray-800' : 'text-gray-400' }}">{{ $branch->name }}</p>
                                    @if ($branchOpen)
                                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-green-100 text-green-700">Aberto</span>
                                    @else
                                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-red-100 text-red-600">Fechado</span>
                                    @endif
                                    <span class="branch-nearest-badge hidden text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-blue-100 text-blue-700">📍 Mais próxima</span>
                                </div>
                                <p class="text-xs text-gray-500 truncate">{{ $branch->address }}, {{ $branch->city }}</p>
                                @if ($branch->phone)
                                    <p class="text-xs text-gray-400 mt-0.5">📞 {{ $branch->phone }}</p>
                                @endif
                                <p class="text-xs mt-0.5 font-medium {{ $branchOpen ? 'text-green-600' : 'text-gray-400' }}">🕐 {{ $branch->opens_at }} – {{ $branch->closes_at }}</p>
                                <p class="branch-distance text-xs text-blue-500 font-medium mt-0.5 hidden"></p>
                            </div>
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-gray-300 shrink-0 mt-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </button>
                @endforeach
                </div>
            </div>

        {{-- ── MENU_BROWSE ── --}}
        @elseif ($step === 'MENU_BROWSE')
            <div class="flex gap-2">
                <button
                    x-on:click="productSidebarOpen = true"
                    class="mc-btn-primary flex-1 flex items-center justify-center gap-2"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7" />
                    </svg>
                    Ver cardápio
                </button>

                <button wire:click="proceedToCheckout" class="mc-btn-primary flex-1 flex items-center justify-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    Ver carrinho
                    @if ($this->cartCount > 0)
                        <span class="ml-1 bg-white mc-text-primary text-xs font-black rounded-full w-5 h-5 flex items-center justify-center">
                            {{ $this->cartCount }}
                        </span>
                    @endif
                </button>
            </div>

            @if ($cartError)
                <p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><span>⚠</span> {{ $cartError }}</p>
            @endif



        {{-- ── CART_REVIEW ── --}}
        @elseif ($step === 'CART_REVIEW')
            <div class="max-h-52 overflow-y-auto mc-scrollbar space-y-1.5 pr-0.5 mb-2">
                @foreach ($cart as $cartKey => $item)
                    <x-chat.cart-item :item="$item" :cart-key="$cartKey" :editable="true" />
                @endforeach
            </div>

            <div class="flex items-center justify-between py-2 px-1 border-t border-gray-100 mb-2">
                <span class="text-sm font-bold text-gray-600">Total do pedido</span>
                <span class="text-xl font-black mc-text-primary">R$ {{ number_format($this->cartTotal, 2, ',', '.') }}</span>
            </div>

            <div class="flex gap-2">
                <button wire:click="backToMenu" class="mc-btn-secondary flex-1">← Voltar</button>
                <button wire:click="confirmCart" class="mc-btn-primary flex-1">Confirmar →</button>
            </div>

        {{-- ── CHECKOUT_COUPON ── --}}
        @elseif ($step === 'CHECKOUT_COUPON')
            <div class="space-y-3">
                <p class="text-sm font-semibold text-gray-700 text-center">Tem um cupom de desconto?</p>

                @if ($appliedCoupon)
                    {{-- Cupom aplicado --}}
                    <div class="flex items-center gap-3 bg-green-50 border border-green-200 rounded-xl px-3.5 py-3">
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-green-600 uppercase tracking-wide">Cupom aplicado</p>
                            <p class="font-bold text-green-800 text-sm">{{ $appliedCoupon['code'] }}</p>
                            @if ($appliedCoupon['type'] === 'free_delivery')
                                <p class="text-xs text-green-700">Frete grátis</p>
                            @else
                                <p class="text-xs text-green-700">
                                    -R$ {{ number_format($appliedCoupon['discount'], 2, ',', '.') }} de desconto
                                </p>
                            @endif
                        </div>
                        <button wire:click="removeCoupon" class="text-gray-400 hover:text-red-500 transition-colors text-lg leading-none">✕</button>
                    </div>
                    <button wire:click="skipCoupon" class="mc-btn-primary w-full">
                        Continuar →
                    </button>
                @else
                    {{-- Campo para inserir cupom --}}
                    <div class="flex gap-2">
                        <input
                            wire:model.defer="couponInput"
                            wire:keydown.enter="applyCoupon"
                            type="text"
                            placeholder="Ex: DESCONTO10"
                            class="flex-1 border border-gray-200 rounded-xl px-3 py-2.5 text-sm font-mono uppercase mc-coupon-input"
                            autocomplete="off"
                        />
                        <button wire:click="applyCoupon" class="flex-1 mc-btn-primary px-4 shrink-0"
                            wire:loading.attr="disabled" wire:target="applyCoupon">
                            <span wire:loading.remove wire:target="applyCoupon">Aplicar</span>
                            <span wire:loading wire:target="applyCoupon">...</span>
                        </button>
                    </div>
                    @if ($couponError)
                        <p class="text-xs text-red-500 px-1">{{ $couponError }}</p>
                    @endif
                    <button wire:click="skipCoupon" class="mc-btn-outline">
                        Continuar sem cupom →
                    </button>
                    @endif

                <button wire:click="backToCartReview" class="w-full text-xs text-center text-gray-400 hover:text-gray-600 py-1">
                    ← Voltar ao carrinho
                </button>
            </div>

        {{-- ── CHECKOUT_ORDER_TYPE ── --}}
        @elseif ($step === 'CHECKOUT_ORDER_TYPE')
            <div class="space-y-3">
                <p class="text-sm font-semibold text-gray-700 text-center">Como deseja receber seu pedido?</p>
                <button wire:click="selectOrderType('delivery')" class="w-full flex items-center gap-3 p-4 rounded-xl mc-card-hover">
                    <span class="text-2xl">🛵</span>
                    <div class="text-left">
                        <p class="font-bold text-gray-800">Entrega</p>
                        <p class="text-xs text-gray-500">Receba em seu endereço</p>
                    </div>
                </button>
                <button wire:click="selectOrderType('pickup')" class="w-full flex items-center gap-3 p-4 rounded-xl mc-card-hover">
                    <span class="text-2xl">🏪</span>
                    <div class="text-left">
                        <p class="font-bold text-gray-800">Retirada no local</p>
                        <p class="text-xs text-gray-500">Retire na filial</p>
                    </div>
                </button>
                <button wire:click="backToCartReview" class="w-full text-xs text-center text-gray-400 hover:text-gray-600 py-1">
                    ← Voltar ao carrinho
                </button>
            </div>

        {{-- ── CHECKOUT_DELIVERY_ADDRESS ── --}}
        @elseif ($step === 'CHECKOUT_DELIVERY_ADDRESS')
            <div wire:key="step-checkout-delivery-address" class="space-y-2"
                x-on:chat-geocoded.window="mapPickerOpenAt('chk', $event.detail.lat, $event.detail.lng)">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Endereço de entrega</p>

                {{-- Endereço atual em destaque --}}
                @if ($address)
                    <div class="bg-blue-50 border border-blue-100 rounded-xl px-3 py-2.5 mb-1">
                        <p class="text-xs font-semibold text-blue-700 mb-0.5">Endereço cadastrado</p>
                        <p class="text-sm text-gray-800 font-medium">{{ $address }}@if($complement), {{ $complement }}@endif</p>
                        <p class="text-xs text-gray-500">{{ $neighborhood }}, {{ $city }}@if($cep) — CEP {{ $cep }}@endif</p>
                    </div>
                @endif

                <x-chat.map-picker prefix="chk" label="Usar minha localização atual" />

                <x-chat.address-fields />

                <div class="flex gap-2 mt-1">
                    <button wire:click="backToOrderType" class="mc-btn-secondary flex-shrink-0">← Voltar</button>
                    <button wire:click="confirmDeliveryAddress" class="mc-btn-primary flex-1"
                        wire:loading.attr="disabled" wire:target="confirmDeliveryAddress">
                        <span wire:loading.remove wire:target="confirmDeliveryAddress">Confirmar endereço →</span>
                        <span wire:loading wire:target="confirmDeliveryAddress">Calculando frete...</span>
                    </button>
                </div>
            </div>

        {{-- ── CHECKOUT_DELIVERY_FEE ── --}}
        @elseif ($step === 'CHECKOUT_DELIVERY_FEE')
            <div class="space-y-3">
                <p class="text-sm font-semibold text-gray-700 text-center">Resumo da entrega</p>

                {{-- Endereço de entrega --}}
                <div class="flex items-start gap-2 bg-gray-50 border border-gray-100 rounded-xl px-3 py-2.5">
                    <span class="text-base mt-0.5">📍</span>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-gray-500 mb-0.5">Entregando em</p>
                        <p class="text-sm text-gray-800 font-medium truncate">{{ $address }}@if($complement), {{ $complement }}@endif</p>
                        <p class="text-xs text-gray-500">{{ $neighborhood }}, {{ $city }}</p>
                    </div>
                    <button wire:click="backToDeliveryAddress"
                        class="text-xs text-blue-600 hover:text-blue-800 font-medium shrink-0 mt-0.5">
                        Alterar
                    </button>
                </div>

                <div class="bg-gray-50 rounded-xl p-4 space-y-2 border border-gray-100">
                    <div class="flex justify-between text-sm text-gray-600">
                        <span>Subtotal dos itens</span>
                        <span>R$ {{ number_format($this->cartTotal, 2, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Taxa de entrega</span>
                        @if ($freeDelivery || ($appliedCoupon && $appliedCoupon['type'] === 'free_delivery') || $deliveryFee == 0)
                            <span class="font-bold text-green-600">Grátis</span>
                        @else
                            <span class="font-bold text-gray-800">R$ {{ number_format($deliveryFee, 2, ',', '.') }}</span>
                        @endif
                    </div>
                    @if ($appliedCoupon && $appliedCoupon['type'] !== 'free_delivery' && $this->couponDiscount > 0)
                        <div class="flex justify-between text-sm text-green-600">
                            <span>Desconto ({{ $appliedCoupon['code'] }})</span>
                            <span>- R$ {{ number_format($this->couponDiscount, 2, ',', '.') }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between text-base font-black border-t border-gray-200 pt-2 mt-1">
                        <span class="text-gray-800">Total</span>
                        <span class="mc-text-primary">R$ {{ number_format($this->orderTotal, 2, ',', '.') }}</span>
                    </div>
                </div>

                @if ($freeDelivery)
                    <p class="text-xs text-green-600 text-center font-medium">Frete grátis para este pedido!</p>
                @endif

                <button wire:click="confirmDeliveryFee" class="mc-btn-primary">
                    Confirmar e continuar →
                </button>
                <button wire:click="selectOrderType('pickup')"
                    class="w-full text-xs text-center text-gray-500 hover:text-gray-700 py-1">
                    Prefiro retirar no local (sem frete)
                </button>
            </div>

        {{-- ── CHECKOUT_SCHEDULE ── --}}
        @elseif ($step === 'CHECKOUT_SCHEDULE')
            <div class="space-y-3">
                <p class="text-sm font-semibold text-gray-700 text-center">Quando deseja receber seu pedido?</p>

                @if (!$showScheduleForm)
                    <div class="space-y-2">
                        <div class="flex gap-2">
                            <button wire:click="selectScheduleOption('now')" class="mc-btn-primary flex-1 !py-2 !px-3 !text-xs">
                                ⚡ Agora, o mais rápido possível
                            </button>
                            <button wire:click="$set('showScheduleForm', true)" class="mc-btn-secondary flex-1 !py-2 !px-3 !text-xs">
                                🕐 Agendar para outro horário
                            </button>
                        </div>
                        <button wire:click="backFromSchedule" class="mc-btn-outline w-full">← Voltar</button>
                    </div>
                @else
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Data desejada</label>
                            <input
                                type="date"
                                wire:model.live="scheduleDate"
                                min="{{ now(config('app.timezone'))->format('Y-m-d') }}"
                                class="mc-input"
                            />
                            @if ($scheduleDate)
                                @if (count($this->availableTimeSlots) > 0)
                                    <div class="mt-3">
                                        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Horário desejado</label>
                                        <flux:select wire:model="scheduleTime">
                                            <flux:select.option value="">Selecione o horário</flux:select.option>
                                            @foreach ($this->availableTimeSlots as $slot)
                                                <flux:select.option value="{{ $slot }}">{{ $slot }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    </div>
                                @else
                                    <p class="text-amber-600 text-xs mt-1 flex items-center gap-1"><span>⚠</span> Nenhum horário disponível para este dia.</p>
                                @endif
                            @endif
                            @error('scheduledAt') <p class="text-red-600 text-xs mt-1 flex items-center gap-1"><span>⚠</span> {{ $message }}</p> @enderror
                        </div>
                        <div class="flex gap-2">
                            <button wire:click="$set('showScheduleForm', false)" class="mc-btn-secondary flex-shrink-0">← Voltar</button>
                            <button
                                wire:click="submitScheduleTime"
                                class="mc-btn-primary flex-1"
                                wire:loading.attr="disabled"
                                wire:target="submitScheduleTime"
                            >
                                <span wire:loading.remove wire:target="submitScheduleTime">Confirmar agendamento →</span>
                                <span wire:loading wire:target="submitScheduleTime">Verificando...</span>
                            </button>
                        </div>
                    </div>
                @endif
            </div>

        {{-- ── CHECKOUT_NOTES ── --}}
        @elseif ($step === 'CHECKOUT_NOTES')
            <div class="space-y-2.5">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">Observações (opcional)</label>
                    <textarea
                        wire:model="notes"
                        rows="3"
                        placeholder="Ex: Capricha no recheio! Sem pimenta..."
                        class="mc-input resize-none"
                        maxlength="500"
                    ></textarea>
                    @error('notes') <p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><span>⚠</span> {{ $message }}</p> @enderror
                </div>

                {{-- Resumo do endereço de entrega --}}
                @if ($orderType === 'delivery' && $address)
                    <div class="flex items-start gap-1.5 bg-gray-50 border border-gray-100 rounded-lg px-2.5 py-2">
                        <span class="text-xs mt-0.5">📍</span>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-gray-500">Entregando em</p>
                            <p class="text-xs text-gray-700 truncate">{{ $address }}@if($complement), {{ $complement }}@endif </p>
                            <p class="text-xs text-gray-700 truncate"> {{ $neighborhood }}, {{ $city }} </p>

                        </div>
                        <button wire:click="backToDeliveryAddress" class="text-xs text-blue-600 hover:text-blue-800 font-medium shrink-0">Alterar</button>
                    </div>
                @endif

                @if ($appliedCoupon && $this->couponDiscount > 0)
                    <div class="flex items-center justify-between bg-green-50 border border-green-100 rounded-lg px-3 py-1.5">
                        <span class="text-xs text-green-700 font-medium">Desconto ({{ $appliedCoupon['code'] }})</span>
                        <span class="text-sm font-bold text-green-600">- R$ {{ number_format($this->couponDiscount, 2, ',', '.') }}</span>
                    </div>
                @elseif ($appliedCoupon && $appliedCoupon['type'] === 'free_delivery')
                    <div class="flex items-center justify-between bg-green-50 border border-green-100 rounded-lg px-3 py-1.5">
                        <span class="text-xs text-green-700 font-medium">Frete grátis ({{ $appliedCoupon['code'] }})</span>
                        <span class="text-sm font-bold text-green-600">Aplicado</span>
                    </div>
                @endif
                <div class="flex items-center justify-between mc-bg-primary-light rounded-lg px-3 py-2">
                    <span class="text-xs text-gray-600 font-medium">Total a pagar</span>
                    <span class="text-lg font-black mc-text-primary">R$ {{ number_format($this->orderTotal, 2, ',', '.') }}</span>
                </div>

                <div class="flex gap-2">
                    @if ($currentCompany?->schedulingEnabled())
                        <button wire:click="backToSchedule" class="mc-btn-secondary flex-shrink-0">← Voltar</button>
                    @elseif ($orderType === 'delivery' && ($deliveryFee > 0 || $freeDelivery))
                        <button wire:click="backToDeliveryFee" class="mc-btn-secondary flex-shrink-0">← Voltar</button>
                    @elseif ($orderType === 'delivery')
                        <button wire:click="backToDeliveryAddress" class="mc-btn-secondary flex-shrink-0">← Voltar</button>
                    @else
                        <button wire:click="backToOrderType" class="mc-btn-secondary flex-shrink-0">← Voltar</button>
                    @endif
                    <button
                        wire:click="proceedFromNotes"
                        class="mc-btn-primary flex-1 flex items-center justify-center gap-2"
                        wire:loading.attr="disabled"
                        wire:target="proceedFromNotes"
                    >
                    <span wire:loading.remove wire:target="proceedFromNotes" class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                        Continuar →
                    </span>
                    <span wire:loading wire:target="proceedFromNotes" class="flex items-center gap-2">
                        <svg class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Aguarde...
                    </span>
                </button>
                </div>
            </div>

        {{-- ── CHECKOUT_CPF ── --}}
        @elseif ($step === 'CHECKOUT_CPF')
            <div wire:key="step-checkout-cpf" class="space-y-2.5"
                x-data="{
                    cpfStatus: null,
                    validateCpf(val) {
                        const digits = val.replace(/\D/g, '');
                        if (digits.length < 11) { this.cpfStatus = null; return; }
                        fetch('{{ route('api.validate-cpf') }}', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                            body: JSON.stringify({ cpf: val })
                        })
                        .then(r => r.json())
                        .then(data => { this.cpfStatus = data.valid ? 'valid' : 'invalid'; });
                    }
                }"
            >
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">CPF</label>
                    <div class="relative">
                        <input
                            wire:model="taxId"
                            type="text"
                            placeholder="000.000.000-00"
                            class="mc-input pr-8"
                            :class="{
                                'border-green-500 focus:ring-green-500': cpfStatus === 'valid',
                                'border-red-500 focus:ring-red-500': cpfStatus === 'invalid'
                            }"
                            wire:keydown.enter="submitCpf"
                            maxlength="14"
                            autocomplete="off"
                            inputmode="numeric"
                            x-on:input="
                                $event.target.value = formatCpf($event.target.value);
                                validateCpf($event.target.value);
                            "
                        />
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-sm pointer-events-none"
                            x-show="cpfStatus === 'valid'" x-cloak>✅</span>
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-sm pointer-events-none"
                            x-show="cpfStatus === 'invalid'" x-cloak>❌</span>
                    </div>
                    <p x-show="cpfStatus === 'invalid'" x-cloak class="text-red-600 text-xs mt-1 flex items-center gap-1">
                        <span>⚠</span> CPF inválido. Verifique os números digitados.
                    </p>
                    @error('taxId') <p class="text-red-600 text-xs mt-1 flex items-center gap-1"><span>⚠</span> {{ $message }}</p> @enderror
                </div>

                <div class="flex gap-2">
                    <button wire:click="backToPaymentMethod" class="mc-btn-secondary flex-shrink-0">← Voltar</button>
                    <button
                        wire:click="submitCpf"
                        class="mc-btn-primary flex-1 flex items-center justify-center gap-2"
                        wire:loading.attr="disabled"
                        wire:target="submitCpf"
                        :disabled="cpfStatus === 'invalid'"
                    >
                        <span wire:loading.remove wire:target="submitCpf" class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                            </svg>
                            Continuar →
                        </span>
                        <span wire:loading wire:target="submitCpf" class="flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Aguarde...
                        </span>
                    </button>
                </div>
            </div>

        {{-- ── CHECKOUT_PAYMENT_METHOD ── --}}
        @elseif ($step === 'CHECKOUT_PAYMENT_METHOD')
            <div wire:key="step-payment-method" class="space-y-3">
                <p class="text-xs font-semibold text-gray-500 text-center uppercase tracking-wider">Forma de pagamento</p>

                {{-- Dinheiro --}}
                <button
                    wire:click="selectPaymentMethod('CASH')"
                    wire:loading.attr="disabled"
                    class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl border-2 border-yellow-200 bg-yellow-50 hover:bg-yellow-100 hover:border-yellow-400 transition-all text-left"
                >
                    <div class="w-9 h-9 rounded-full bg-yellow-500 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                    <div>
                        <p class="font-bold text-gray-800 text-sm">Dinheiro</p>
                        <p class="text-xs text-gray-500">Pagamento na entrega</p>
                    </div>
                    <div class="ml-auto">
                        <span class="text-xs font-bold text-yellow-700 bg-yellow-100 px-2 py-0.5 rounded-full">Na entrega</span>
                    </div>
                </button>

                {{-- PIX --}}
                <button
                    wire:click="selectPaymentMethod('PIX')"
                    wire:loading.attr="disabled"
                    class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl border-2 border-green-200 bg-green-50 hover:bg-green-100 hover:border-green-400 transition-all text-left"
                >
                    <div class="w-9 h-9 rounded-full bg-green-500 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M11.85 3.15a.5.5 0 01.3 0l7 2.5A.5.5 0 0119.5 6v6c0 3.5-2.5 6.5-7 8.5-.12.05-.26.05-.38 0C7.5 18.5 5 15.5 5 12V6a.5.5 0 01.35-.47l7-2.5-.5-.88zm0 1.02L6 6.38V12c0 3 2.1 5.6 6 7.47 3.9-1.87 6-4.47 6-7.47V6.38l-6.15-2.21z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="font-bold text-gray-800 text-sm">PIX</p>
                        <p class="text-xs text-gray-500">
                            Pagamento instantâneo
                            @if(!($currentCompany?->pix_fee_absorbed_by_company))
                                · <span class="text-amber-600">+ R$ {{ number_format(config('payments.pix_payment_fee', 0.50), 2, ',', '.') }} de taxa</span>
                            @endif
                        </p>
                    </div>
                    <div class="ml-auto">
                        <span class="text-xs font-bold text-green-600 bg-green-100 px-2 py-0.5 rounded-full">Instantâneo</span>
                    </div>
                </button>

                {{-- Cartão de Crédito --}}
                <button
                    wire:click="selectPaymentMethod('CARD')"
                    wire:loading.attr="disabled"
                    class="w-full flex items-center gap-3 px-4 py-3.5 rounded-xl border-2 border-blue-200 bg-blue-50 hover:bg-blue-100 hover:border-blue-400 transition-all text-left"
                >
                    <div class="w-9 h-9 rounded-full bg-blue-500 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                        </svg>
                    </div>
                    <div>
                        <p class="font-bold text-gray-800 text-sm">Cartão de Crédito</p>
                        <p class="text-xs text-gray-500">Visa, Mastercard e outros</p>
                    </div>
                </button>

                <div wire:loading class="flex justify-center pt-1">
                    <svg class="animate-spin w-5 h-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                </div>

                <button wire:click="backToNotes"
                    class="w-full text-xs text-center text-gray-400 hover:text-gray-600 py-1">
                    ← Voltar
                </button>
            </div>

        {{-- ── CHECKOUT_CONFIRM ── --}}
        @elseif ($step === 'CHECKOUT_CONFIRM')
            <div wire:key="step-confirm" class="space-y-3">
                <p class="text-xs font-semibold text-gray-500 text-center uppercase tracking-wider">Confirme seu pedido</p>

                {{-- Itens --}}
                <div class="max-h-44 overflow-y-auto mc-scrollbar space-y-1.5 pr-0.5">
                    @foreach ($cart as $item)
                        <x-chat.cart-item :item="$item" :editable="true" />
                    @endforeach
                </div>

                {{-- Resumo de valores --}}
                <div class="rounded-xl border border-gray-100 bg-gray-50 px-3 py-2.5 space-y-1.5">
                    <div class="flex justify-between text-sm text-gray-600">
                        <span>Subtotal</span>
                        <span>R$ {{ number_format($this->cartTotal, 2, ',', '.') }}</span>
                    </div>

                    @if ($orderType === 'delivery')
                        <div class="flex justify-between text-sm text-gray-600">
                            <span>Taxa de entrega</span>
                            @if ($freeDelivery || ($appliedCoupon && $appliedCoupon['type'] === 'free_delivery'))
                                <span class="text-green-600 font-semibold">Grátis</span>
                            @else
                                <span>R$ {{ number_format($deliveryFee, 2, ',', '.') }}</span>
                            @endif
                        </div>
                    @endif

                    @if ($appliedCoupon && $appliedCoupon['discount'] > 0)
                        <div class="flex justify-between text-sm text-green-600">
                            <span>Cupom ({{ $appliedCoupon['code'] }})</span>
                            <span>− R$ {{ number_format($appliedCoupon['discount'], 2, ',', '.') }}</span>
                        </div>
                    @endif

                    @if ($paymentMethod === 'CARD' && !empty($cardFeeBreakdown))
                        <div class="flex justify-between text-sm text-gray-600">
                            <span>Taxa do cartão ({{ round($cardFeeBreakdown['card_rate'] * 100, 2) }}%)</span>
                            <span>+ R$ {{ number_format($cardFeeBreakdown['fee_amount'], 2, ',', '.') }}</span>
                        </div>
                        @if (($cardFeeBreakdown['platform_fee_amount'] ?? 0) > 0)
                            <div class="flex justify-between text-sm text-gray-600">
                                <span>Taxa da plataforma ({{ round($cardFeeBreakdown['platform_rate'] * 100, 2) }}%)</span>
                                <span>− R$ {{ number_format($cardFeeBreakdown['platform_fee_amount'], 2, ',', '.') }}</span>
                            </div>
                        @endif
                    @endif

                    <div class="flex justify-between items-center pt-1.5 border-t border-gray-200">
                        <span class="font-bold text-gray-800">Total</span>
                        <span class="text-lg font-black mc-text-primary">
                            @if ($paymentMethod === 'CARD' && !empty($cardFeeBreakdown))
                                R$ {{ number_format($cardFeeBreakdown['final_amount'], 2, ',', '.') }}
                            @else
                                R$ {{ number_format($this->orderTotal, 2, ',', '.') }}
                            @endif
                        </span>
                    </div>
                </div>

                {{-- Detalhes do pedido --}}
                <div class="rounded-xl border border-gray-100 bg-gray-50 px-3 py-2.5 space-y-1">
                    <div class="flex justify-between text-xs text-gray-600">
                        <span class="font-medium">Pagamento</span>
                        <span>
                            @if ($paymentMethod === 'CASH') Dinheiro
                            @elseif ($paymentMethod === 'CARD') Cartão de Crédito
                            @else PIX
                            @endif
                        </span>
                    </div>
                    <div class="flex justify-between text-xs text-gray-600">
                        <span class="font-medium">Tipo</span>
                        <span>{{ $orderType === 'delivery' ? 'Entrega' : 'Retirada' }}</span>
                    </div>
                    @if ($notes)
                        <div class="flex justify-between text-xs text-gray-600 gap-2">
                            <span class="font-medium shrink-0">Obs.</span>
                            <span class="text-right text-gray-500 truncate">{{ $notes }}</span>
                        </div>
                    @endif
                </div>

                <div class="flex gap-2">
                    <button wire:click="backToPaymentMethod"
                        wire:loading.attr="disabled"
                        class="mc-btn-secondary flex-1">← Voltar</button>
                    <button wire:click="confirmOrder"
                        wire:loading.attr="disabled"
                        class="mc-btn-primary flex-1">
                        <span wire:loading.remove wire:target="confirmOrder">Confirmar pedido →</span>
                        <span wire:loading wire:target="confirmOrder">Enviando...</span>
                    </button>
                </div>
            </div>

        {{-- ── PAYMENT_PIX ── --}}
        @elseif ($step === 'PAYMENT_PIX')
            <div wire:poll.5s="checkPaymentStatus" class="space-y-3 text-center">

                {{-- Status label + countdown timer --}}
                <div class="flex items-center justify-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-yellow-400 animate-pulse"></span>
                    <p class="text-sm font-semibold text-gray-600">
                        @if($paymentMethod === 'CARD')
                            Aguardando pagamento no cartão…
                        @else
                            Aguardando pagamento PIX…
                        @endif
                    </p>
                </div>

                {{-- Countdown timer (Alpine.js) --}}
                @if ($expiresAt)
                    <div
                        x-data="{
                            expiresAt: new Date('{{ $expiresAt }}'),
                            timeLeft: '',
                            expired: false,
                            intervalId: null,
                            init() {
                                this.tick();
                                this.intervalId = setInterval(() => this.tick(), 1000);
                            },
                            tick() {
                                const diff = Math.max(0, this.expiresAt - Date.now());
                                if (diff === 0) {
                                    this.expired = true;
                                    clearInterval(this.intervalId);
                                    $wire.handlePaymentExpired();
                                    return;
                                }
                                const m = String(Math.floor(diff / 60000)).padStart(2, '0');
                                const s = String(Math.floor((diff % 60000) / 1000)).padStart(2, '0');
                                this.timeLeft = m + ':' + s;
                            }
                        }"
                        class="flex items-center justify-center gap-1.5"
                    >
                        <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span
                            x-text="expired ? 'Expirado' : timeLeft"
                            :class="expired ? 'text-red-500 font-bold' : 'text-gray-400'"
                            class="text-[11px] font-mono"
                        ></span>
                    </div>
                @endif

                {{-- Breakdown do valor --}}
                @if ($paymentMethod !== 'CARD')
                    @php
                        $pixFeeCharged = !($currentCompany?->pix_fee_absorbed_by_company);
                        $pixFeeAmount  = $pixFeeCharged ? (float) config('payments.pix_payment_fee', 0.50) : 0.0;
                        $totalWithFee  = $this->orderTotal + $pixFeeAmount;
                    @endphp
                    <div class="bg-gray-50 rounded-xl px-4 py-3 border border-gray-100 text-left space-y-1">
                        <div class="flex justify-between text-xs text-gray-500">
                            <span>Subtotal dos itens</span>
                            <span>R$ {{ number_format($this->cartTotal, 2, ',', '.') }}</span>
                        </div>
                        @if ($orderType === 'delivery' && $deliveryFee > 0 && !($appliedCoupon && $appliedCoupon['type'] === 'free_delivery') && !$freeDelivery)
                            <div class="flex justify-between text-xs text-gray-500">
                                <span>Taxa de entrega</span>
                                <span>+ R$ {{ number_format($deliveryFee, 2, ',', '.') }}</span>
                            </div>
                        @elseif ($orderType === 'delivery' && ($freeDelivery || ($appliedCoupon && $appliedCoupon['type'] === 'free_delivery')))
                            <div class="flex justify-between text-xs text-green-600">
                                <span>Taxa de entrega</span>
                                <span>Grátis</span>
                            </div>
                        @endif
                        @if ($appliedCoupon && $appliedCoupon['type'] !== 'free_delivery' && $this->couponDiscount > 0)
                            <div class="flex justify-between text-xs text-green-600">
                                <span>Desconto ({{ $appliedCoupon['code'] }})</span>
                                <span>- R$ {{ number_format($this->couponDiscount, 2, ',', '.') }}</span>
                            </div>
                        @endif
                        @if ($pixFeeCharged)
                            <div class="flex justify-between text-xs text-amber-600">
                                <span>Taxa PIX</span>
                                <span>+ R$ {{ number_format($pixFeeAmount, 2, ',', '.') }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between text-sm font-bold text-gray-800 border-t border-gray-200 pt-1.5 mt-1">
                            <span>Total a pagar</span>
                            <span class="mc-text-primary">R$ {{ number_format($totalWithFee, 2, ',', '.') }}</span>
                        </div>
                    </div>
                @endif

                {{-- QR Code --}}
                @if ($pixCopyPaste)
                    <div class="flex justify-center" wire:ignore>
                        <div class="mc-qr-frame"
                            x-data
                            x-init="
                                QRCode.toCanvas($el.querySelector('canvas'), {{ json_encode($pixCopyPaste) }}, {
                                    width: 160,
                                    margin: 1,
                                    color: { dark: '#000000', light: '#ffffff' }
                                });
                            "
                        >
                            <canvas class="rounded-lg"></canvas>
                        </div>
                    </div>
                @endif

                {{-- PIX copia e cola --}}
                @if ($paymentMethod !== 'CARD')
                    @if ($pixCopyPaste)
                        <div x-data="{ copied: false }" class="bg-gray-50 rounded-xl p-3 border border-gray-200">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1.5">PIX Copia e Cola</p>
                            <div class="flex gap-2">
                                <input
                                    readonly
                                    value="{{ $pixCopyPaste }}"
                                    class="flex-1 text-[11px] font-mono bg-white border border-gray-200 rounded-lg px-2 py-1.5 text-gray-500 truncate"
                                />
                                <button
                                    x-on:click="
                                        navigator.clipboard.writeText('{{ $pixCopyPaste }}');
                                        copied = true;
                                        setTimeout(() => copied = false, 2500);
                                    "
                                    class="shrink-0 px-3 py-1.5 rounded-lg text-xs font-bold text-white transition-all"
                                    :class="copied ? 'bg-green-500' : 'mc-bg-primary'"
                                    :style="copied ? '' : 'background: var(--mc-red)'"
                                >
                                    <span x-show="!copied">Copiar</span>
                                    <span x-show="copied">✓ Copiado</span>
                                </button>
                            </div>
                        </div>
                    @endif
                @endif

                <p class="text-[11px] text-gray-400">Confirmação automática em até 5s após o pagamento.</p>

                <button
                    wire:click="changePaymentMethod"
                    wire:loading.attr="disabled"
                    class="w-full text-xs text-center text-gray-400 hover:text-gray-600 py-1 transition-colors"
                >
                    ← Trocar forma de pagamento
                </button>
            </div>

        {{-- ── PAYMENT_CARD_FORM ── --}}
        @elseif ($step === 'PAYMENT_CARD_FORM')
            <div class="space-y-4 px-1 py-2">
                @if (!empty($customerCards) && !$useNewCard)
                    <p class="text-sm font-medium text-neutral-700 dark:text-neutral-300">Escolha um cartão salvo:</p>

                    {{-- Cartões salvos --}}
                    <div class="flex flex-wrap gap-2">
                        @foreach ($customerCards as $savedCard)
                            <button
                                type="button"
                                wire:click="selectSavedCard({{ $savedCard['id'] }})"
                                class="flex items-center gap-2 rounded-lg border {{ $selectedCardId === $savedCard['id'] ? 'mc-border-primary ring-1 ring-(--mc-red)' : 'border-neutral-300 dark:border-neutral-600' }} bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-left"
                            >
                                <span>{{ $savedCard['label'] }}</span>
                                @if ($selectedCardId === $savedCard['id'])
                                    <span class="mc-text-primary">✓</span>
                                @endif
                            </button>
                        @endforeach
                    </div>

                    {{-- CVV do cartão salvo --}}
                    <div>
                        <label class="block text-xs text-neutral-500 mb-1">CVV</label>
                        <input
                            wire:model="cardCvv"
                            placeholder="CVV"
                            maxlength="4"
                            inputmode="numeric"
                            type="password"
                            autocomplete="cc-csc"
                            class="w-full rounded-lg border @error('cardCvv') border-red-400 @else border-neutral-300 dark:border-neutral-600 @enderror bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-(--mc-red)"
                        />
                        @error('cardCvv')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>

                    <button type="button" wire:click="useNewCardForm" class="mc-btn-outline w-full">
                        Usar outro cartão
                    </button>
                @else
                    <p class="text-sm font-medium text-neutral-700 dark:text-neutral-300">Insira os dados do cartão:</p>

                    {{-- Número do cartão --}}
                    <div>
                        <label class="block text-xs text-neutral-500 mb-1">Número do cartão</label>
                        <input
                            wire:model="cardNumber"
                            placeholder="0000 0000 0000 0000"
                            maxlength="19"
                            inputmode="numeric"
                            autocomplete="cc-number"
                            x-data
                            x-mask="9999 9999 9999 9999"
                            class="w-full rounded-lg border @error('cardNumber') border-red-400 @else border-neutral-300 dark:border-neutral-600 @enderror bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-(--mc-red)"
                        />
                        @error('cardNumber')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>

                    {{-- Validade + CVV --}}
                    <div class="flex gap-3">
                        <div class="flex-1">
                            <label class="block text-xs text-neutral-500 mb-1">Validade</label>
                            <input
                                wire:model="cardExpiry"
                                placeholder="MM/AA"
                                x-data
                                x-mask="99/99"
                                maxlength="5"
                                inputmode="numeric"
                                autocomplete="cc-exp"
                                class="w-full rounded-lg border @error('cardExpiry') border-red-400 @else border-neutral-300 dark:border-neutral-600 @enderror bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-(--mc-red)"
                            />
                            @error('cardExpiry')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                        </div>
                        <div class="flex-1">
                            <label class="block text-xs text-neutral-500 mb-1">CVV</label>
                            <input
                                wire:model="cardCvv"
                                placeholder="CVV"
                                maxlength="4"
                                inputmode="numeric"
                                type="password"
                                autocomplete="cc-csc"
                                class="w-full rounded-lg border @error('cardCvv') border-red-400 @else border-neutral-300 dark:border-neutral-600 @enderror bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-(--mc-red)"
                            />
                            @error('cardCvv')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    {{-- Nome no cartão --}}
                    <div>
                        <label class="block text-xs text-neutral-500 mb-1">Nome impresso no cartão</label>
                        <input
                            wire:model="cardHolderName"
                            placeholder="NOME SOBRENOME"
                            autocomplete="cc-name"
                            style="text-transform:uppercase"
                            class="w-full rounded-lg border @error('cardHolderName') border-red-400 @else border-neutral-300 dark:border-neutral-600 @enderror bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-(--mc-red)"
                        />
                        @error('cardHolderName')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>

                    @if (!empty($customerCards))
                        <button type="button" wire:click="selectSavedCard({{ $customerCards[0]['id'] }})" class="mc-btn-outline w-full">
                            Usar cartão salvo
                        </button>
                    @endif
                @endif

                {{-- Breakdown de taxas --}}
                @if (!empty($cardFeeBreakdown))
                    <div class="rounded-lg bg-neutral-50 dark:bg-neutral-800/50 border border-neutral-200 dark:border-neutral-700 px-3 py-2.5 space-y-1">
                        <div class="flex justify-between text-xs text-neutral-500">
                            <span>Valor do pedido</span>
                            <span>R$ {{ number_format($cardFeeBreakdown['original_amount'], 2, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between text-xs text-neutral-500">
                            <span>Taxa do cartão ({{ round($cardFeeBreakdown['card_rate'] * 100, 2) }}%)</span>
                            <span>+ R$ {{ number_format($cardFeeBreakdown['fee_amount'], 2, ',', '.') }}</span>
                        </div>
                        @if (($cardFeeBreakdown['platform_fee_amount'] ?? 0) > 0)
                            <div class="flex justify-between text-xs text-neutral-500">
                                <span>Taxa da plataforma ({{ round($cardFeeBreakdown['platform_rate'] * 100, 2) }}%)</span>
                                <span>− R$ {{ number_format($cardFeeBreakdown['platform_fee_amount'], 2, ',', '.') }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between text-sm font-semibold text-neutral-800 dark:text-neutral-100 border-t border-neutral-200 dark:border-neutral-700 pt-1 mt-1">
                            <span>Total cobrado</span>
                            <span>R$ {{ number_format($cardFeeBreakdown['final_amount'], 2, ',', '.') }}</span>
                        </div>
                        </div>
                @else
                    <p class="text-xs text-neutral-500">
                        Total à vista: <span class="font-semibold text-neutral-700 dark:text-neutral-200">R$ {{ number_format($this->orderTotal, 2, ',', '.') }}</span>
                    </p>
                @endif

                {{-- Erro da API / recusa --}}
                @if ($cardError)
                    <div class="rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-3 py-2">
                        <p class="text-sm text-red-600 dark:text-red-400 font-medium">{{ $cardError }}</p>
                    </div>
                @endif

                {{-- Botão submit --}}
                <button
                    wire:click="submitCardPayment"
                    wire:loading.attr="disabled"
                    class="mc-btn-primary"
                >
                    <span wire:loading.remove wire:target="submitCardPayment">
                        @if (!empty($cardFeeBreakdown))
                            Pagar R$ {{ number_format($cardFeeBreakdown['final_amount'], 2, ',', '.') }}
                        @else
                            Pagar com cartão
                        @endif
                    </span>
                    <span wire:loading wire:target="submitCardPayment">Processando...</span>
                </button>

                <button
                    wire:click="changePaymentMethod"
                    wire:loading.attr="disabled"
                    class="w-full text-xs text-center text-gray-400 hover:text-gray-600 py-1 transition-colors"
                >
                    ← Trocar forma de pagamento
                </button>
            </div>

        {{-- ── ORDER_CONFIRMED ── --}}
        @elseif ($step === 'ORDER_CONFIRMED')
            {{-- Polling de fallback caso o evento Echo não chegue --}}
            @if ($orderId && !in_array($lastNotifiedStatus, ['delivered', 'cancelled']))
                <div wire:poll.15s="checkPaymentStatus" class="hidden"></div>
            @endif

            <div class="text-center space-y-3 py-1">
                <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mx-auto text-4xl">
                    🎉
                </div>
                <div>
                    <p class="font-black text-green-600 text-lg">Pedido confirmado!</p>
                    <p class="text-sm text-gray-500 mt-0.5">Seu pedido já estará sendo preparado. 🥟</p>
                </div>

                {{-- Confirmação de cancelamento --}}
                @php
                    $currentOrder = $orderId ? \App\Models\Order::find($orderId) : null;
                    $canCancel = $currentOrder && in_array($currentOrder->status, ['pending', 'awaiting_payment', 'scheduled', 'paid', 'preparing']);
                @endphp

                {{-- Confirmação de cancelamento --}}
                @if ($showCancelConfirm)
                    <div class="bg-red-50 border border-red-200 rounded-xl p-3 text-left space-y-2">
                        <p class="text-sm font-semibold text-red-700">Tem certeza que deseja cancelar o pedido?</p>
                        <p class="text-xs text-red-500">Essa ação não pode ser desfeita.</p>
                        <div class="flex gap-2">
                            <button wire:click="confirmCancelOrder" wire:loading.attr="disabled" class="flex-1 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold py-2 rounded-lg transition-colors">
                                Sim, cancelar
                            </button>
                            <button wire:click="dismissCancelOrder" class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm font-semibold py-2 rounded-lg transition-colors">
                                Não
                            </button>
                        </div>
                    </div>
                @else
                    @if ($canCancel)
                        <button wire:click="requestCancelOrder" class="w-full border border-red-300 text-red-600 hover:bg-red-50 text-sm font-medium py-2 rounded-lg transition-colors">
                            Cancelar pedido
                        </button>
                    @endif
                @endif

                <div class="space-y-2">
                    <button wire:click="startNewOrderKeepHistory" class="mc-btn-primary w-full">
                        Fazer novo pedido
                    </button>
                    <button wire:click="startNewOrder" class="mc-btn-secondary w-full text-xs">
                        Novo pedido e apagar histórico
                    </button>
                </div>

            </div>



        {{-- ── UNAVAILABLE ── --}}
        @elseif ($step === 'UNAVAILABLE')
            <div class="text-center space-y-3 py-2">
                <div class="w-14 h-14 rounded-full bg-gray-100 flex items-center justify-center mx-auto text-3xl">
                    ⚠️
                </div>
                <div>
                    <p class="font-bold text-gray-700 text-base">Atendimento indisponível</p>
                    <p class="text-xs text-gray-500 mt-1">Esta loja ainda não concluiu a configuração necessária para receber pedidos. Volte em breve!</p>
                </div>
            </div>

        {{-- ── CLOSED ── --}}
        @elseif ($step === 'CLOSED')
            <div class="text-center space-y-3 py-2">
                <div class="w-14 h-14 rounded-full bg-gray-100 flex items-center justify-center mx-auto text-3xl">
                    🕐
                </div>
                <div>
                    <p class="font-bold text-gray-700 text-base">Estamos fechados</p>
                    <p class="text-xs text-gray-500 mt-1">No momento não estamos recebendo pedidos. Confira os horários de cada filial e volte em breve!</p>
                </div>
                @if($this->branches->isNotEmpty())
                    <div class="space-y-1.5 text-left">
                        @foreach($this->branches as $branch)
                            <div class="bg-gray-50 rounded-xl px-3 py-2 border border-gray-100 flex items-center justify-between">
                                <span class="text-sm font-medium text-gray-700">{{ $branch->name }}</span>
                                <span class="text-xs text-gray-500">{{ $branch->opens_at }} – {{ $branch->closes_at }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

        {{-- ── EDIT_PROFILE ── --}}
        @elseif ($step === 'EDIT_PROFILE')
            <div wire:key="step-edit-profile" class="space-y-2">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Editar cadastro</p>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Nome completo</label>
                    <input wire:model="name" type="text" class="mc-input" autocomplete="name" />
                    @error('name') <p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <x-chat.address-fields />

                <div class="flex gap-2 pt-1">
                    <button wire:click="cancelEditProfile" class="mc-btn-secondary flex-1">Cancelar</button>
                    <button wire:click="submitEditProfile" class="mc-btn-primary flex-1"
                        wire:loading.attr="disabled" wire:target="submitEditProfile">
                        <span wire:loading.remove wire:target="submitEditProfile">Salvar →</span>
                        <span wire:loading wire:target="submitEditProfile">Salvando...</span>
                    </button>
                </div>
            </div>

        {{-- ── ORDER_CANCELLED ── --}}
        @elseif ($step === 'ORDER_CANCELLED')
            <div class="text-center space-y-3 py-1">
                <div class="w-14 h-14 rounded-full bg-orange-100 flex items-center justify-center mx-auto text-3xl">
                    ❌
                </div>
                <div>
                    <p class="font-bold text-orange-600 text-base">Pedido cancelado</p>
                    <p class="text-xs text-gray-500 mt-1">Seu pedido foi cancelado. Que tal fazer um novo?</p>
                </div>
                <div class="flex gap-2">
                    <button wire:click="startNewOrderKeepHistory" class="mc-btn-primary flex-1">Novo pedido</button>
                    <button wire:click="startNewOrder" class="mc-btn-secondary flex-1 text-xs">Recomeçar</button>
                </div>
            </div>

        {{-- ── ORDER_FAILED ── --}}
        @elseif ($step === 'ORDER_FAILED')
            <div class="text-center space-y-3 py-1">
                <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto text-3xl">
                    ⚠️
                </div>
                <div>
                    <p class="font-bold text-red-600 text-base">Erro ao processar pagamento</p>
                    <p class="text-xs text-gray-500 mt-1">Não foi possível gerar o PIX. Verifique sua conexão e tente novamente.</p>
                </div>
                <div class="flex gap-2">
                    <button wire:click="retryOrder" class="mc-btn-primary flex-1">Tentar novamente</button>
                    <button wire:click="startNewOrder" class="mc-btn-secondary flex-1">Recomeçar</button>
                </div>
            </div>
        @endif

    </div>

    {{-- Footer branding + game trigger --}}
    <div class="shrink-0 border-t px-4 py-2 flex items-center justify-between gap-2"
         style="background: linear-gradient(90deg, color-mix(in srgb, var(--mc-red-dark) 95%, black) 0%, var(--mc-red-dark) 100%); border-color: var(--mc-red-dark);">
        <div class="flex items-center gap-2">
            <div class="w-5 h-5 rounded-full overflow-hidden border border-white/20 shrink-0" style="background: var(--mc-red)">
                @if(isset($currentCompany) && $currentCompany->logo_path)
                    <img src="{{ $currentCompany->logo_url }}" alt="" class="w-full h-full object-cover">
                @else
                    <img src="{{ asset('logo_branca.png') }}" alt="" class="w-full h-full object-cover p-0.5">
                @endif
            </div>
            <span class="text-[10px] text-white/70 font-medium">{{ $currentCompany->name ?? config('app.name') }}</span>
            @if(isset($currentCompany) && $currentCompany->tagline)
                <span class="text-[10px] text-white/70">•</span>
                <span class="text-[10px] text-white/70">{{ $currentCompany->tagline }}</span>
            @endif
        </div>
        <button
            @click="snakeOpen = true; $nextTick(() => window.SnakeDash && window.SnakeDash.onOpen())"
            class="flex items-center gap-1 text-[10px] font-semibold text-white/60 hover:text-white/90 transition-colors whitespace-nowrap"
        >
            🎮 Jogue enquanto espera
        </button>
    </div>

    {{-- Snake modal backdrop (covers full chat card) --}}
    <div
        x-show="snakeOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="absolute inset-0 z-50 flex items-center justify-center p-4"
        style="display:none; background:rgba(0,0,0,.65); backdrop-filter:blur(4px)"
        @click.self="snakeOpen = false; window.SnakeDash && window.SnakeDash.onClose()"
        wire:ignore
    >
        {{-- Modal card --}}
        <div
            x-show="snakeOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95 translate-y-2"
            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100 translate-y-0"
            x-transition:leave-end="opacity-0 scale-95 translate-y-2"
            class="w-full rounded-2xl overflow-hidden shadow-2xl"
            style="max-width:310px; background:linear-gradient(160deg,#0f0720 0%,#1a0b35 50%,#110820 100%); border:1px solid rgba(255,255,255,.07)"
        >
            {{-- Card header --}}
            <div class="flex items-center justify-between px-3 py-2" style="border-bottom:1px solid rgba(255,255,255,.06)">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-base shrink-0" >
                            <img src="{{ asset('logo_branca.png') }}" alt="{{ config('app.name') }}" class="w-full h-full object-cover p-0.5 rounded-full">
                        </div>           
                         <div>
                        <p class="font-black text-sm leading-tight" style="color:#fff">Veddi Dash</p>
                        <p class="text-xs" style="color:rgba(255,255,255,.35)">Jogue enquanto espera</p>
                    </div>
                </div>
                <button
                    @click="snakeOpen = false; window.SnakeDash && window.SnakeDash.onClose()"
                    class="w-7 h-7 rounded-lg flex items-center justify-center"
                    style="background:rgba(255,255,255,.07);color:rgba(255,255,255,.4)"
                    onmouseover="this.style.background='rgba(255,255,255,.13)';this.style.color='rgba(255,255,255,.85)'"
                    onmouseout="this.style.background='rgba(255,255,255,.07)';this.style.color='rgba(255,255,255,.4)'"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Game --}}
            <div class="px-3 py-2">
                <div id="mc-snake-game" class="w-full"></div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════ PRODUCT SIDEBAR ═══════════════════════ --}}
    {{-- Sidebar panel --}}
    <div
        x-show="productSidebarOpen"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-full"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-full"
        class="absolute inset-0 z-40 bg-white shadow-2xl flex flex-col overflow-hidden"
        style="display:none"
    >
        {{-- Sidebar header --}}
        <div class="shrink-0 px-4 py-3 flex items-center justify-between" style="background: linear-gradient(135deg, var(--mc-red-dark) 0%, var(--mc-red) 60%, var(--mc-red-light) 100%);">
            <p class="text-white font-bold text-sm">Cardápio</p>
            <button
                @click="productSidebarOpen = false"
                class="text-white/70 hover:text-white transition-colors p-1.5 rounded-lg hover:bg-white/10"
                title="Fechar"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        {{-- Search --}}
        <div class="shrink-0 px-3 pt-3">
            <div class="relative">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5A6.5 6.5 0 114 10.5a6.5 6.5 0 0113 0z" />
                </svg>
                <input
                    type="text"
                    x-model="productSearch"
                    placeholder="Buscar produto..."
                    class="w-full text-sm bg-gray-100 border border-gray-200 rounded-xl pl-9 pr-8 py-2 focus:outline-none focus:ring-2 focus:ring-red-200"
                >
                <button
                    type="button"
                    x-show="productSearch"
                    x-cloak
                    @click="productSearch = ''"
                    class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                    title="Limpar"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        {{-- Product list --}}
        <div class="flex-1 overflow-y-auto px-3 py-3 space-y-3 mc-scrollbar">
            @forelse ($this->menu as $category)
                @if ($category->products->isNotEmpty())
                    <div
                        x-data="{ categoryName: @js($category->name), categoryProductNames: @js($category->products->pluck('name')) }"
                        x-show="!productSearch || categoryName.toLowerCase().includes(productSearch.toLowerCase()) || categoryProductNames.some(n => n.toLowerCase().includes(productSearch.toLowerCase()))"
                    >
                        <div class="flex items-center gap-2 mb-2">
                            <p class="text-xs font-black mc-text-primary uppercase tracking-widest">{{ $category->name }}</p>
                            <div class="flex-1 h-px mc-bg-primary-light"></div>
                        </div>
                        <div class="space-y-1.5">
                            @foreach ($category->products as $product)
                                @php
                                    $sbOutOfStock = $product->track_stock && ($product->quantity <= 0 || !$product->available);
                                    // Soma qty de todas as entradas do cart que pertencem a este produto
                                    $sbCartQty = 0;
                                    foreach ($cart as $__key => $__item) {
                                        $__pid = (int) ($__item['product_id'] ?? $__key);
                                        if ($__pid === $product->id) {
                                            $sbCartQty += $__item['qty'];
                                        }
                                    }
                                    $sbInsufficientStock = !$sbOutOfStock && $product->track_stock && $sbCartQty > 0 && $sbCartQty >= $product->quantity;
                                    $sbDisabled = $sbOutOfStock || $sbInsufficientStock;
                                @endphp
                                <div
                                    x-show="!productSearch || categoryName.toLowerCase().includes(productSearch.toLowerCase()) || @js($product->name).toLowerCase().includes(productSearch.toLowerCase())"
                                    class="flex items-center gap-3 rounded-xl p-2.5 border {{ $sbDisabled ? 'bg-gray-100 border-gray-200 opacity-70' : 'bg-gray-50 border-gray-100' }}">
                                    {{-- Image --}}
                                    <div class="w-16 h-16 rounded-xl overflow-hidden shrink-0">
                                        @if ($product->image_path)
                                            <img src="{{ $product->image_url }}" class="w-full h-full object-cover {{ $sbDisabled ? 'grayscale' : '' }}" />
                                        @else
                                            <div class="w-full h-full bg-linear-to-br from-red-100 to-amber-100 flex items-center justify-center text-2xl">🥟</div>
                                        @endif
                                    </div>
                                    {{-- Info --}}
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-1">
                                            <p class="font-semibold text-sm {{ $sbDisabled ? 'text-gray-400' : 'text-gray-800' }} truncate leading-tight">{{ $product->name }}</p>
                                            @if ($sbOutOfStock)
                                                <span class="shrink-0 text-[9px] font-bold px-1 py-0.5 rounded-full bg-gray-200 text-gray-500 uppercase">Esgotado</span>
                                            @elseif ($sbInsufficientStock)
                                                <span class="shrink-0 text-[9px] font-bold px-1 py-0.5 rounded-full bg-amber-100 text-amber-600 uppercase">Insuf.</span>
                                            @endif
                                        </div>
                                        @if ($product->description)
                                            <p class="text-[11px] text-gray-400 leading-snug mt-0.5 line-clamp-2">{{ $product->description }}</p>
                                        @endif
                                     
                                        @if ($product->is_variant && $product->optionGroups->isNotEmpty())
                                            @php
                                                $variantFirstGroup = $product->optionGroups->first();
                                                if ($variantFirstGroup?->fixed) {
                                                    $variantMinOption = $variantFirstGroup->activeOptions->min(
                                                        fn ($o) => (float) $o->additional_price * max(1, (int) $o->default_qty)
                                                    ) ?? 0;
                                                } else {
                                                    $variantMinOption = $variantFirstGroup?->activeOptions->min('additional_price') ?? 0;
                                                }
                                                $variantMinPrice = (float) $product->effective_price + (float) $variantMinOption;
                                            @endphp
                                            <p class="text-sm font-black {{ $sbDisabled ? 'text-gray-400' : 'mc-text-primary' }} mt-0.5">
                                                A partir de R$ {{ number_format($variantMinPrice, 2, ',', '.') }}
                                            </p>
                                        @elseif ($product->promo_price_enabled && $product->promo_price_value !== null)
                                            <div class="flex items-center gap-2 mt-0.5">
                                                <p class="text-[11px] text-gray-400 line-through">
                                                    R$ {{ number_format($product->price, 2, ',', '.') }}
                                                </p>
                                                <p class="text-sm font-black {{ $sbDisabled ? 'text-gray-400' : 'mc-text-primary' }}">
                                                    R$ {{ number_format($product->effective_price, 2, ',', '.') }}
                                                </p>
                                            </div>
                                        @else
                                            <p class="text-sm font-black {{ $sbDisabled ? 'text-gray-400' : 'mc-text-primary' }} mt-0.5">
                                                R$ {{ number_format($product->price, 2, ',', '.') }}
                                            </p>
                                        @endif
                                        @if ($sbInsufficientStock)
                                            <p class="text-[10px] text-amber-500 mt-0.5">Máx. disponível: {{ $product->quantity }}</p>
                                        @endif
                                        @if ($product->optionGroups->isNotEmpty())
                                            @php $hasPausedOptions = $product->optionGroups->some(fn ($g) => $g->inactiveOptions->isNotEmpty()); @endphp
                                            <div class="flex flex-wrap gap-1 mt-1">
                                                @foreach ($product->optionGroups as $optGroup)
                                                    <span class="inline-flex items-center gap-0.5 text-[10px] font-semibold px-1.5 py-0.5 rounded-full {{ $sbDisabled ? 'bg-gray-100 text-gray-400' : 'mc-bg-primary-light mc-text-primary' }}">
                                                        {{ $optGroup->name }}
                                                        <span class="opacity-60">({{ $optGroup->total_qty }})</span>
                                                    </span>
                                                @endforeach
                                            </div>
                                            @if ($hasPausedOptions && ! $sbDisabled)
                                                <p class="text-[10px] text-amber-500 mt-0.5 flex items-center gap-0.5">
                                                    <svg class="w-3 h-3 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                                                    Alguns itens em pausa
                                                </p>
                                            @endif
                                        @endif
                                    </div>
                                    {{-- Qty controls --}}
                                    @php
                                        $productData = $this->buildProductDataForSidebar($product);
                                        $hasOptions = $productData !== null;
                                    @endphp
                                    <div class="flex items-center gap-0.5 shrink-0">
                                        @if (! $sbOutOfStock)
	                                            @if ($hasOptions)
	                                                {{-- Produtos com opções: mesmos controles visuais dos produtos simples --}}
	                                                @if ($sbCartQty > 0)
	                                                    <button wire:click="decrementProductFromCart({{ $product->id }})"
	                                                        class="w-6 h-6 rounded-full mc-bg-primary-light mc-text-primary font-bold text-sm flex items-center justify-center">−</button>
	                                                    <span class="w-5 text-center text-xs font-bold text-gray-800">{{ $sbCartQty }}</span>
	                                                @endif
	                                                <button
	                                                    @if (! $sbInsufficientStock)
                                                        @click="addOrOpenOptionSelector(@js($productData), $wire)"
                                                    @endif
                                                    @disabled($sbInsufficientStock)
                                                    class="w-6 h-6 rounded-full font-bold text-sm flex items-center justify-center transition-colors {{ $sbInsufficientStock ? 'bg-gray-200 text-gray-400 cursor-not-allowed' : 'mc-bg-primary text-white active:scale-90' }}"
                                                >+</button>
                                            @else
                                                {{-- Produtos simples: controles de quantidade normais --}}
                                                @if ($sbCartQty > 0)
                                                    <button wire:click="updateCartQty('{{ $product->id }}', {{ $sbCartQty - 1 }})"
                                                        class="w-6 h-6 rounded-full mc-bg-primary-light mc-text-primary font-bold text-sm flex items-center justify-center">−</button>
                                                    <span class="w-5 text-center text-xs font-bold text-gray-800">{{ $sbCartQty }}</span>
                                                @endif
                                                <button
                                                    @if (! $sbInsufficientStock)
                                                        wire:click="addToCart({{ $product->id }})"
                                                    @endif
                                                    @disabled($sbInsufficientStock)
                                                    class="w-6 h-6 rounded-full font-bold text-sm flex items-center justify-center transition-colors {{ $sbInsufficientStock ? 'bg-gray-200 text-gray-400 cursor-not-allowed' : 'mc-bg-primary text-white active:scale-90' }}"
                                                >+</button>
                                            @endif
                                        @else
                                            <div class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center">
                                                <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @empty
                <div class="text-center py-8">
                    <p class="text-3xl mb-2">😔</p>
                    <p class="text-sm text-gray-500">Nenhum produto disponível.</p>
                </div>
            @endforelse
        </div>

        {{-- ── Painel de seleção de opções ── --}}
        <div
            x-show="selectingProduct !== null"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-full opacity-0"
            x-transition:enter-end="translate-x-0 opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0 opacity-100"
            x-transition:leave-end="translate-x-full opacity-0"
            class="absolute inset-0 z-50 bg-white flex flex-col overflow-hidden"
            style="display:none"
        >
            {{-- Header --}}
            <div class="shrink-0 px-4 py-3 flex items-center gap-3" style="background: linear-gradient(135deg, var(--mc-red-dark) 0%, var(--mc-red) 60%, var(--mc-red-light) 100%);">
                <button @click="selectingProduct = null; pendingSelections = {}"
                    class="text-white/70 hover:text-white p-1 rounded-lg hover:bg-white/10 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
                <div class="flex-1 min-w-0">
                    <p class="text-white font-bold text-sm truncate" x-text="selectingProduct?.name"></p>
                    <p class="text-white/70 text-xs" x-text="'R$ ' + getTotalWithOptions().toFixed(2).replace('.', ',')"></p>
                </div>
            </div>

            {{-- Grupos --}}
            <div class="flex-1 overflow-y-auto px-4 py-4 space-y-5 mc-scrollbar">
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
                                            <p class="font-bold text-sm text-gray-800" x-text="group.name"></p>
                                            <p class="text-xs text-gray-400 mt-0.5">
                                                Máximo <span class="font-semibold" x-text="group.total_qty"></span> unidades
                                            </p>
                                        </div>
                                    </div>
                                    <span class="text-xs font-bold px-2 py-1 rounded-full"
                                        :class="getGroupTotal(group.id) > group.total_qty
                                            ? 'bg-red-100 text-red-700'
                                            : getGroupTotal(group.id) === group.total_qty
                                                ? 'bg-green-100 text-green-700'
                                                : 'bg-gray-100 text-gray-500'">
                                        <span x-text="getGroupTotal(group.id)"></span>/<span x-text="group.total_qty"></span>
                                    </span>
                                </div>

                                {{-- Opções --}}
                                <div class="space-y-1">
                                    <template x-for="option in group.options" :key="option.id">
                                        <div class="flex items-center gap-3 p-2.5 rounded-xl border transition-colors"
                                            :class="option.paused ? 'border-amber-100 bg-amber-50' : 'border-gray-100 bg-gray-50'">
                                            <template x-if="option.image_url">
                                                <img :src="option.image_url"
                                                     class="w-12 h-12 rounded-xl object-cover shrink-0"
                                                     :class="option.paused ? 'grayscale opacity-50' : ''" />
                                            </template>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-1.5 flex-wrap">
                                                    <p class="text-sm font-medium"
                                                        :class="option.paused ? 'text-gray-400' : 'text-gray-800'"
                                                        x-text="option.name"></p>
                                                    <span x-show="option.paused"
                                                        class="inline-flex items-center gap-0.5 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-600">
                                                        <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                                                        Em pausa
                                                    </span>
                                                </div>
                                                <p class="text-xs text-gray-500 mt-0.5 leading-snug"
                                                    x-show="option.description && !option.paused"
                                                    x-text="option.description"></p>
                                                <p class="text-xs text-gray-400 mt-0.5"
                                                    x-show="option.additional_price > 0 && !option.paused"
                                                    x-text="'+R$ ' + option.additional_price.toFixed(2).replace('.', ',')"></p>
                                            </div>
                                            <div class="flex items-center gap-1.5 shrink-0">
                                                <template x-if="!option.paused && group.fixed">
                                                    {{-- Fixo: apenas exibe a quantidade --}}
                                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-gray-100 text-gray-600 text-xs font-semibold">
                                                        <span x-text="option.prefilledQty"></span> un.
                                                    </span>
                                                </template>
                                                <template x-if="!option.paused && !group.fixed">
                                                    {{-- Variável: controles de quantidade --}}
                                                    <div class="flex items-center gap-1.5">
                                                        <button
                                                            @click="if ((pendingSelections[group.id]?.[option.id] || 0) > 0) pendingSelections[group.id][option.id]--"
                                                            class="w-7 h-7 rounded-full mc-bg-primary-light mc-text-primary font-bold text-base flex items-center justify-center">−</button>
                                                        <span class="w-7 text-center text-sm font-bold text-gray-800"
                                                            x-text="pendingSelections[group.id]?.[option.id] || 0"></span>
                                                        <button
                                                            @click="if (getGroupTotal(group.id) < group.total_qty) { if (!pendingSelections[group.id]) pendingSelections[group.id] = {}; pendingSelections[group.id][option.id] = (pendingSelections[group.id]?.[option.id] || 0) + 1 }"
                                                            :disabled="getGroupTotal(group.id) >= group.total_qty"
                                                            :class="getGroupTotal(group.id) >= group.total_qty ? 'bg-gray-200 text-gray-400 cursor-not-allowed' : 'mc-bg-primary text-white'"
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

            {{-- Footer --}}
            <div class="shrink-0 border-t border-gray-100 px-4 py-3">
                <button
                    @click="confirmOptions($wire)"
                    :disabled="!canConfirm()"
                    class="mc-btn-primary w-full"
                    :class="!canConfirm() ? 'opacity-50 cursor-not-allowed' : ''">
                    Adicionar ao carrinho
                </button>
            </div>
        </div>

        {{-- Sidebar footer: go to cart --}}
        @if ($step === 'MENU_BROWSE')
            <div class="shrink-0 border-t border-gray-100 px-3 py-3">
                <button wire:click="proceedToCheckout" @click="productSidebarOpen = false" class="mc-btn-primary flex items-center justify-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    Ver carrinho
                    @if ($this->cartCount > 0)
                        <span class="ml-1 bg-white mc-text-primary text-xs font-black rounded-full w-5 h-5 flex items-center justify-center">
                            {{ $this->cartCount }}
                        </span>
                    @endif
                </button>
            </div>
        @endif
    </div>

</div>

