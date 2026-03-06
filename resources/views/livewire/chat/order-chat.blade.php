{{--
    Mister Coxinha — Chatbot de Pedidos
    Mobile  : tela cheia (igual WhatsApp mobile)
    Desktop : card centralizado sobre fundo com identidade da marca
--}}
<div
    class="
        flex flex-col bg-white overflow-hidden
        w-full h-full
        sm:w-[420px] sm:h-[90vh] sm:max-h-[820px] sm:rounded-2xl sm:shadow-2xl
    "
    x-data="{ copied: false }"
>

    {{-- ═══════════════════════════════ HEADER ══════════════════════════════ --}}
    <div class="shrink-0" style="background: linear-gradient(135deg, var(--mc-red-dark) 0%, var(--mc-red) 60%, var(--mc-red-light) 100%);">

        {{-- Brand bar --}}
        <div class="flex items-center gap-3 px-4 py-3">

            {{-- Avatar / Logo --}}
            <div style="background: var(--mc-red)" class="w-11 h-11 rounded-full bg-white/15 border-2 border-white/30 flex items-center justify-center shrink-0 shadow-lg overflow-hidden">
                @if(isset($currentCompany) && $currentCompany->logo_path)
                    <img src="{{ asset('storage/' . $currentCompany->logo_path) }}" alt="{{ $currentCompany->name }}" class="w-full h-full object-cover">
                @else
                    <img src="{{ asset('logo.png') }}" alt="{{ $currentCompany->name ?? config('app.name') }}" class="w-full h-full object-cover">
                @endif
            </div>

            {{-- Brand info --}}
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-1.5">
                    <p class="text-white font-bold text-base leading-tight">{{ $currentCompany->name ?? config('app.name') }}</p>
                </div>
                <div class="flex items-center gap-1.5 mt-0.5">
                    @if ($step === 'CLOSED')
                        <span class="w-2 h-2 rounded-full bg-red-400 shadow-sm shadow-red-300"></span>
                        <p class="text-white/75 text-xs">Fechado no momento</p>
                    @else
                        <span class="w-2 h-2 rounded-full bg-green-400 shadow-sm shadow-green-300"></span>
                        <p class="text-white/75 text-xs">Online • Pedidos abertos</p>
                    @endif
                </div>
            </div>

            {{-- Restart button --}}
            @if ($step !== 'IDENTIFY_PHONE' && $step !== 'ORDER_CONFIRMED' && $step !== 'CLOSED')
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
        </div>

        {{-- Step progress dots --}}
        @php
            $steps = ['IDENTIFY_PHONE','BRANCH_SELECT','MENU_BROWSE','CART_REVIEW','PAYMENT_PIX'];
            $currentIdx = array_search($step, $steps);
            if ($currentIdx === false) $currentIdx = in_array($step, ['REGISTER_NAME','REGISTER_EMAIL','REGISTER_ADDRESS']) ? 0 : ($step === 'CHECKOUT_NOTES' ? 3 : ($step === 'ORDER_CONFIRMED' ? 5 : $currentIdx));
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
                    {{-- Bot avatar --}}
                    <div class="w-7 h-7 rounded-full bg-[#B91C1C] flex items-center justify-center text-white text-xs font-bold shrink-0 mr-1.5 mt-0.5">
                        🎩
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
                <div class="w-7 h-7 rounded-full bg-[#B91C1C] flex items-center justify-center text-white text-xs font-bold shrink-0 mr-1.5 mt-0.5">
                    🎩
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
    <div class="border-t border-gray-100 bg-white px-4 py-3 shrink-0">

        {{-- ── IDENTIFY_PHONE ── --}}
        @if ($step === 'IDENTIFY_PHONE')
            <div wire:key="step-identify-phone" class="space-y-2.5">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">Telefone com DDD</label>
                    <input
                        wire:model="phone"
                        type="tel"
                        placeholder="(44) 99999-9999"
                        class="mc-input"
                        wire:keydown.enter="submitPhone"
                        autocomplete="tel"
                    />
                    @error('phone') <p class="text-red-600 text-xs mt-1 flex items-center gap-1"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <button wire:click="submitPhone" class="mc-btn-primary" wire:loading.attr="disabled" wire:target="submitPhone">
                    <span wire:loading.remove wire:target="submitPhone">Continuar →</span>
                    <span wire:loading wire:target="submitPhone">Verificando...</span>
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
                <button wire:click="submitName" class="mc-btn-primary">Continuar →</button>
            </div>

        {{-- ── REGISTER_EMAIL ── --}}
        @elseif ($step === 'REGISTER_EMAIL')
            <div wire:key="step-register-email" class="space-y-2.5">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">Seu e-mail</label>
                    <input wire:model="email" type="email" placeholder="seu@email.com" class="mc-input"
                        wire:keydown.enter="submitEmail" autocomplete="email" />
                    @error('email') <p class="text-red-600 text-xs mt-1 flex items-center gap-1"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <button wire:click="submitEmail" class="mc-btn-primary">Continuar →</button>
            </div>

        {{-- ── REGISTER_ADDRESS ── --}}
        @elseif ($step === 'REGISTER_ADDRESS')
            <div wire:key="step-register-address" class="space-y-2">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Rua e número</label>
                    <input wire:model="address" type="text" placeholder="Ex: Av. Brasil, 100" class="mc-input" autocomplete="street-address" />
                    @error('address') <p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Bairro</label>
                        <input wire:model="neighborhood" type="text" placeholder="Zona 1" class="mc-input" autocomplete="address-level2" />
                        @error('neighborhood') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">CEP</label>
                        <input wire:model="cep" type="text" placeholder="00000-000" class="mc-input" autocomplete="postal-code" />
                        @error('cep') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
                    </div>
                </div>
                <button wire:click="submitAddress" class="mc-btn-primary mt-1">Salvar e continuar →</button>
            </div>

        {{-- ── BRANCH_SELECT ── --}}
        @elseif ($step === 'BRANCH_SELECT')
            <div class="space-y-2">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Escolha a filial</p>
                @foreach ($this->branches as $branch)
                    <button
                        wire:click="selectBranch({{ $branch->id }})"
                        class="w-full text-left border-2 border-gray-100 rounded-xl p-3 hover:border-red-200 hover:bg-red-50 transition-all active:scale-[0.98]"
                    >
                        <div class="flex items-start gap-3">
                            <div class="w-9 h-9 rounded-lg bg-red-100 flex items-center justify-center shrink-0">
                                <span class="text-lg">🏪</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-sm text-gray-800">{{ $branch->name }}</p>
                                <p class="text-xs text-gray-500 truncate">{{ $branch->address }}, {{ $branch->city }}</p>
                                @if ($branch->phone)
                                    <p class="text-xs text-gray-400 mt-0.5">📞 {{ $branch->phone }}</p>
                                @endif
                                <p class="text-xs text-green-600 mt-0.5 font-medium">🕐 {{ $branch->opens_at }} – {{ $branch->closes_at }}</p>
                            </div>
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-gray-300 shrink-0 mt-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </button>
                @endforeach
            </div>

        {{-- ── MENU_BROWSE ── --}}
        @elseif ($step === 'MENU_BROWSE')
            <div class="space-y-3 max-h-72 overflow-y-auto mc-scrollbar pr-0.5">
                @forelse ($this->menu as $category)
                    @if ($category->products->isNotEmpty())
                        <div>
                            <div class="flex items-center gap-2 mb-2">
                                <p class="text-xs font-black text-[#B91C1C] uppercase tracking-widest">{{ $category->name }}</p>
                                <div class="flex-1 h-px bg-red-100"></div>
                            </div>
                            <div class="space-y-1.5">
                                @foreach ($category->products as $product)
                                    <div class="flex items-center gap-2.5 bg-gray-50 rounded-xl p-2.5 border border-gray-100">
                                        {{-- Image --}}
                                        <div class="w-12 h-12 rounded-lg overflow-hidden shrink-0">
                                            @if ($product->image_path)
                                                <img src="{{ asset('storage/'.$product->image_path) }}" class="w-full h-full object-cover" />
                                            @else
                                                <div class="w-full h-full bg-gradient-to-br from-red-100 to-amber-100 flex items-center justify-center text-xl">
                                                    🥟
                                                </div>
                                            @endif
                                        </div>

                                        {{-- Info --}}
                                        <div class="flex-1 min-w-0">
                                            <p class="font-semibold text-sm text-gray-800 truncate leading-tight">{{ $product->name }}</p>
                                            @if ($product->description)
                                                <p class="text-[11px] text-gray-400 truncate">{{ $product->description }}</p>
                                            @endif
                                            <p class="text-sm font-black text-[#B91C1C] mt-0.5">
                                                R$ {{ number_format($product->price, 2, ',', '.') }}
                                            </p>
                                        </div>

                                        {{-- Qty controls --}}
                                        <div class="flex items-center gap-1 shrink-0">
                                            @if (isset($cart[$product->id]) && $cart[$product->id]['qty'] > 0)
                                                <button
                                                    wire:click="updateCartQty({{ $product->id }}, {{ $cart[$product->id]['qty'] - 1 }})"
                                                    class="w-7 h-7 rounded-full bg-red-100 text-[#B91C1C] hover:bg-red-200 font-bold text-base flex items-center justify-center transition-colors"
                                                >−</button>
                                                <span class="w-6 text-center text-sm font-bold text-gray-800">{{ $cart[$product->id]['qty'] }}</span>
                                            @endif
                                            <button
                                                wire:click="addToCart({{ $product->id }})"
                                                class="w-7 h-7 rounded-full text-white font-bold text-base flex items-center justify-center transition-colors active:scale-90"
                                                style="background: #B91C1C"
                                            >+</button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @empty
                    <div class="text-center py-6">
                        <p class="text-3xl mb-2">😔</p>
                        <p class="text-sm text-gray-500">Nenhum produto disponível nesta filial.</p>
                    </div>
                @endforelse
            </div>

            @if ($cartError)
                <p class="text-red-600 text-xs mt-1.5 flex items-center gap-1"><span>⚠</span> {{ $cartError }}</p>
            @endif

            {{-- Cart button --}}
            <button wire:click="proceedToCheckout" class="mc-btn-primary mt-3 flex items-center justify-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                Ver carrinho
                @if ($this->cartCount > 0)
                    <span class="ml-1 bg-white text-[#B91C1C] text-xs font-black rounded-full w-5 h-5 flex items-center justify-center">
                        {{ $this->cartCount }}
                    </span>
                @endif
            </button>

        {{-- ── CART_REVIEW ── --}}
        @elseif ($step === 'CART_REVIEW')
            <div class="max-h-52 overflow-y-auto mc-scrollbar space-y-1.5 pr-0.5 mb-2">
                @foreach ($cart as $productId => $item)
                    <div class="flex items-center gap-2.5 bg-gray-50 rounded-xl p-2.5 border border-gray-100">
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-sm text-gray-800 truncate">{{ $item['name'] }}</p>
                            <p class="text-xs text-gray-400">R$ {{ number_format($item['price'], 2, ',', '.') }} cada</p>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            <button wire:click="updateCartQty({{ $productId }}, {{ $item['qty'] - 1 }})"
                                class="w-7 h-7 rounded-full bg-gray-200 hover:bg-gray-300 font-bold text-sm flex items-center justify-center">−</button>
                            <span class="w-6 text-center text-sm font-bold">{{ $item['qty'] }}</span>
                            <button wire:click="updateCartQty({{ $productId }}, {{ $item['qty'] + 1 }})"
                                class="w-7 h-7 rounded-full bg-red-100 hover:bg-red-200 font-bold text-sm text-[#B91C1C] flex items-center justify-center">+</button>
                            <button wire:click="removeFromCart({{ $productId }})"
                                class="w-7 h-7 rounded-full bg-red-50 hover:bg-red-100 text-red-500 text-sm flex items-center justify-center ml-0.5">✕</button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex items-center justify-between py-2 px-1 border-t border-gray-100 mb-2">
                <span class="text-sm font-bold text-gray-600">Total do pedido</span>
                <span class="text-xl font-black text-[#B91C1C]">R$ {{ number_format($this->cartTotal, 2, ',', '.') }}</span>
            </div>

            <div class="flex gap-2">
                <button wire:click="backToMenu" class="mc-btn-secondary flex-1">← Voltar</button>
                <button wire:click="confirmCart" class="mc-btn-primary flex-1">Confirmar →</button>
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

                <div class="flex items-center justify-between bg-red-50 rounded-xl px-4 py-2.5">
                    <span class="text-sm text-gray-600 font-medium">Total a pagar</span>
                    <span class="text-xl font-black text-[#B91C1C]">R$ {{ number_format($this->cartTotal, 2, ',', '.') }}</span>
                </div>

                <button
                    wire:click="submitOrder"
                    class="mc-btn-primary flex items-center justify-center gap-2"
                    wire:loading.attr="disabled"
                    wire:target="submitOrder"
                >
                    <span wire:loading.remove wire:target="submitOrder" class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                        Gerar PIX
                    </span>
                    <span wire:loading wire:target="submitOrder" class="flex items-center gap-2">
                        <svg class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Gerando pagamento...
                    </span>
                </button>
            </div>

        {{-- ── PAYMENT_PIX ── --}}
        @elseif ($step === 'PAYMENT_PIX')
            <div wire:poll.5s="checkPaymentStatus" class="space-y-3 text-center">

                {{-- Status label --}}
                <div class="flex items-center justify-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-yellow-400 animate-pulse"></span>
                    <p class="text-sm font-semibold text-gray-600">Aguardando pagamento PIX…</p>
                </div>

                {{-- QR Code --}}
                @if ($pixQrCode)
                    <div class="flex justify-center">
                        <div class="mc-qr-frame">
                            <img src="data:image/png;base64,{{ $pixQrCode }}" class="w-40 h-40 rounded-lg" />
                        </div>
                    </div>
                @endif

                {{-- PIX copia e cola --}}
                @if ($pixCopyPaste)
                    <div class="bg-gray-50 rounded-xl p-3 border border-gray-200">
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
                                :class="copied ? 'bg-green-500' : 'bg-[#B91C1C] hover:bg-red-700'"
                            >
                                <span x-show="!copied">Copiar</span>
                                <span x-show="copied">✓ Copiado</span>
                            </button>
                        </div>
                    </div>
                @endif

                <p class="text-[11px] text-gray-400">Confirmação automática em até 5s após o pagamento.</p>

                    <div class="border-t border-dashed border-gray-200 pt-3">
                        <p class="text-[10px] text-gray-400 uppercase tracking-wider mb-2 font-bold">Ambiente de desenvolvimento</p>
                        <button
                            wire:click="simulatePayment"
                            wire:loading.attr="disabled"
                            wire:target="simulatePayment"
                            class="w-full py-2 px-4 rounded-xl text-sm font-bold border-2 border-dashed border-amber-400 text-amber-600 hover:bg-amber-50 transition-colors flex items-center justify-center gap-2"
                        >
                            <span wire:loading.remove wire:target="simulatePayment">⚡ Simular Pagamento</span>
                            <span wire:loading wire:target="simulatePayment">Processando...</span>
                        </button>
                    </div>
            </div>

        {{-- ── ORDER_CONFIRMED ── --}}
        @elseif ($step === 'ORDER_CONFIRMED')
            <div class="text-center space-y-3 py-1">
                <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mx-auto text-4xl">
                    🎉
                </div>
                <div>
                    <p class="font-black text-green-600 text-lg">Pedido confirmado!</p>
                    <p class="text-sm text-gray-500 mt-0.5">Seus salgados já estão sendo preparados. 🥟</p>
                </div>
                <button wire:click="startNewOrder" class="mc-btn-primary">
                    Fazer novo pedido
                </button>
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

    {{-- Footer branding --}}
    <div class="bg-gray-50 border-t border-gray-100 px-4 py-1.5 shrink-0 flex items-center justify-center gap-1">
        <span class="text-[10px] text-gray-400 font-medium">{{ $currentCompany->name ?? config('app.name') }}</span>
        @if(isset($currentCompany) && $currentCompany->tagline)
            <span class="text-[10px] text-gray-300">•</span>
            <span class="text-[10px] text-gray-400">{{ $currentCompany->tagline }}</span>
        @endif
    </div>

</div>
