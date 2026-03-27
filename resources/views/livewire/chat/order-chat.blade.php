{{--
    Mister Coxinha — Chatbot de Pedidos
    Mobile  : tela cheia (igual WhatsApp mobile)
    Desktop : card centralizado sobre fundo com identidade da marca
--}}
<div
    class="
        relative flex flex-col bg-white overflow-hidden
        w-full h-full
        sm:w-[420px] sm:h-[90vh] sm:max-h-[820px] sm:rounded-2xl sm:shadow-2xl
    "
    x-data="{ ...chatApp(), snakeOpen: false }"
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
                    @if ($step === 'CLOSED')
                        <span class="w-2 h-2 rounded-full bg-red-400 shadow-sm shadow-red-300"></span>
                        <p class="text-white/75 text-xs">Fechado no momento</p>
                    @else
                        <span class="w-2 h-2 rounded-full bg-green-400 shadow-sm shadow-green-300"></span>
                        <p class="text-white/75 text-xs">Online • Pedidos abertos</p>
                    @endif
                </div>
            </div>

            {{-- Restart + End chat buttons --}}
            @if ($step !== 'IDENTIFY_PHONE' && $step !== 'CLOSED')
                <div class="flex items-center gap-1" x-data="{ confirmEnd: false }">
                    {{-- Support icon --}}
                    @if ($customerId && !in_array($step, ['IDENTIFY_PHONE', 'CLOSED', 'EDIT_PROFILE']) && !$showSupportModal)
                        <button
                            wire:click="goToSupport"
                            class="text-white/70 hover:text-white transition-colors p-1.5 rounded-lg hover:bg-white/10"
                            title="Falar com suporte"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
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

                    {{-- End chat --}}
                    <button
                        x-on:click="confirmEnd = true"
                        class="text-white/70 hover:text-white transition-colors p-1.5 rounded-lg hover:bg-white/10"
                        title="Encerrar conversa"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>

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
            $steps = ['IDENTIFY_PHONE','BRANCH_SELECT','MENU_BROWSE','CART_REVIEW','PAYMENT_PIX'];
            $currentIdx = array_search($step, $steps);
            if ($currentIdx === false) $currentIdx = in_array($step, ['REGISTER_NAME','REGISTER_EMAIL','REGISTER_ADDRESS']) ? 0 : (in_array($step, ['CHECKOUT_COUPON','CHECKOUT_ORDER_TYPE','CHECKOUT_DELIVERY_ADDRESS','CHECKOUT_DELIVERY_FEE','CHECKOUT_NOTES','CHECKOUT_CPF','CHECKOUT_PAYMENT_METHOD']) ? 3 : ($step === 'ORDER_CONFIRMED' ? 5 : $currentIdx));
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
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">Seu e-mail</label>
                    <input wire:model="email" type="email" placeholder="seu@email.com" class="mc-input"
                        wire:keydown.enter="submitEmail" autocomplete="email" />
                    @error('email') <p class="text-red-600 text-xs mt-1 flex items-center gap-1"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <div class="flex gap-2">
                    <button wire:click="backToRegisterName" class="mc-btn-secondary flex-shrink-0">← Voltar</button>
                    <button wire:click="submitEmail" class="mc-btn-primary flex-1">Continuar →</button>
                </div>
            </div>

        {{-- ── REGISTER_ADDRESS ── --}}
        @elseif ($step === 'REGISTER_ADDRESS')
            <div wire:key="step-register-address" class="space-y-2">

                {{-- Botão de localização --}}
                <button type="button" id="map-reg-loc-btn" onclick="mapPickerUseLocation('reg')"
                    class="w-full flex items-center justify-center gap-2 border border-blue-200 bg-blue-50 hover:bg-blue-100 active:bg-blue-200 text-blue-700 text-xs font-semibold rounded-xl py-2.5 transition-colors disabled:opacity-60">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span id="map-reg-span-default">Usar minha localização</span>
                    <span id="map-reg-span-loading" style="display:none" class="flex items-center gap-1.5">
                        <svg class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        Obtendo localização...
                    </span>
                </button>
                <p id="map-reg-error" style="display:none" class="text-red-500 text-xs -mt-1 flex items-center gap-1"></p>

                {{-- Mapa interativo --}}
                <div id="map-reg-container" style="display:none" class="rounded-xl overflow-hidden border border-blue-200 shadow-sm">
                    <div class="bg-blue-50 px-3 py-1.5 flex items-center justify-between">
                        <span class="text-xs font-semibold text-blue-700">📍 Arraste o pin para ajustar</span>
                        <button type="button" onclick="mapPickerCloseMap('reg')" class="text-blue-400 hover:text-blue-700 text-lg leading-none">×</button>
                    </div>
                    <div id="map-reg-el" style="height:200px; width:100%;"></div>
                    <div class="flex gap-2 p-2 bg-gray-50 border-t border-gray-100">
                        <button type="button" onclick="mapPickerCloseMap('reg')" class="mc-btn-secondary flex-1 !py-1.5 text-xs">Cancelar</button>
                        <button type="button" id="map-reg-confirm-btn" onclick="mapPickerConfirmLocation('reg')"
                            class="mc-btn-primary flex-1 !py-1.5 text-xs disabled:opacity-60">
                            <span id="map-reg-span-confirm">Confirmar local →</span>
                            <span id="map-reg-span-geocoding" style="display:none" class="flex items-center justify-center gap-1.5">
                                <svg class="animate-spin w-3 h-3" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                Buscando endereço...
                            </span>
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">
                        CEP
                        <span x-show="cepLoading" class="text-blue-500 font-normal normal-case ml-1">Buscando...</span>
                    </label>
                    <input
                        wire:model="cep"
                        type="text"
                        placeholder="00000-000"
                        class="mc-input"
                        autocomplete="postal-code"
                        inputmode="numeric"
                        maxlength="9"
                        x-on:input="
                            $event.target.value = formatCep($event.target.value);
                            if ($event.target.value.replace(/\D/g,'').length === 8) lookupCep($event.target.value, $wire);
                        "
                    />
                    @error('cep') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Rua e número</label>
                    <input wire:model="address" type="text" placeholder="Ex: Av. Brasil, 100" class="mc-input" autocomplete="street-address" />
                    @error('address') <p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Complemento <span class="text-gray-400 font-normal">(opcional)</span></label>
                    <input wire:model="complement" type="text" placeholder="Apto 12, Bloco B..." class="mc-input" autocomplete="address-line2" />
                    @error('complement') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Bairro</label>
                        <input wire:model="neighborhood" type="text" placeholder="Zona 1" class="mc-input" autocomplete="address-level3" />
                        @error('neighborhood') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Cidade</label>
                        <input wire:model="city" type="text" placeholder="Maringá" class="mc-input" autocomplete="address-level2" />
                        @error('city') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex gap-2 mt-1">
                    <button wire:click="backToRegisterEmail" class="mc-btn-secondary flex-shrink-0">← Voltar</button>
                    <button wire:click="submitAddress" class="mc-btn-primary flex-1">Salvar e continuar →</button>
                </div>
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
                                <p class="text-xs font-black mc-text-primary uppercase tracking-widest">{{ $category->name }}</p>
                                <div class="flex-1 h-px mc-bg-primary-light"></div>
                            </div>
                            <div class="space-y-1.5">
                                @foreach ($category->products as $product)
                                    @php
                                        $outOfStock = $product->track_stock && ($product->quantity <= 0 || !$product->available);
                                        $cartQty = $cart[$product->id]['qty'] ?? 0;
                                        $insufficientStock = !$outOfStock && $product->track_stock && $cartQty > 0 && $cartQty >= $product->quantity;
                                        $disabled = $outOfStock || $insufficientStock;
                                    @endphp
                                    <div class="flex items-center gap-2.5 rounded-xl p-2.5 border {{ $disabled ? 'bg-gray-100 border-gray-200 opacity-70' : 'bg-gray-50 border-gray-100' }}">
                                        {{-- Image --}}
                                        <div class="w-12 h-12 rounded-lg overflow-hidden shrink-0 relative">
                                            @if ($product->image_path)
                                                <img src="{{ $product->image_url }}" class="w-full h-full object-cover {{ $disabled ? 'grayscale' : '' }}" />
                                            @else
                                                <div class="w-full h-full bg-gradient-to-br from-red-100 to-amber-100 flex items-center justify-center text-xl">
                                                    🥟
                                                </div>
                                            @endif
                                        </div>

                                        {{-- Info --}}
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-1.5">
                                                <p class="font-semibold text-sm {{ $disabled ? 'text-gray-400' : 'text-gray-800' }} truncate leading-tight">{{ $product->name }}</p>
                                                @if ($outOfStock)
                                                    <span class="shrink-0 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-gray-200 text-gray-500 uppercase tracking-wide">Esgotado</span>
                                                @elseif ($insufficientStock)
                                                    <span class="shrink-0 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-600 uppercase tracking-wide">Estoque insuficiente</span>
                                                @endif
                                            </div>
                                            @if ($product->description)
                                                <p class="text-[11px] text-gray-400 truncate">{{ $product->description }}</p>
                                            @endif
                                            <p class="text-sm font-black {{ $disabled ? 'text-gray-400' : 'mc-text-primary' }} mt-0.5">
                                                R$ {{ number_format($product->price, 2, ',', '.') }}
                                            </p>
                                            @if ($insufficientStock)
                                                <p class="text-[10px] text-amber-500 mt-0.5">Máx. disponível: {{ $product->quantity }}</p>
                                            @endif
                                        </div>

                                        {{-- Qty controls --}}
                                        <div class="flex items-center gap-1 shrink-0">
                                            @if (! $outOfStock)
                                                @if ($cartQty > 0)
                                                    <button
                                                        wire:click="updateCartQty({{ $product->id }}, {{ $cartQty - 1 }})"
                                                        class="w-7 h-7 rounded-full mc-bg-primary-light mc-text-primary font-bold text-base flex items-center justify-center transition-colors"
                                                    >−</button>
                                                    <span class="w-6 text-center text-sm font-bold text-gray-800">{{ $cartQty }}</span>
                                                @endif
                                                <button
                                                    @if (! $insufficientStock) wire:click="addToCart({{ $product->id }})" @endif
                                                    @disabled($insufficientStock)
                                                    class="w-7 h-7 rounded-full font-bold text-base flex items-center justify-center transition-colors {{ $insufficientStock ? 'bg-gray-200 text-gray-400 cursor-not-allowed' : 'mc-bg-primary text-white active:scale-90' }}"
                                                    @if ($insufficientStock) title="Estoque insuficiente para adicionar mais" @endif
                                                >+</button>
                                            @else
                                                <div class="w-7 h-7 rounded-full bg-gray-200 flex items-center justify-center">
                                                    <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                    <span class="ml-1 bg-white mc-text-primary text-xs font-black rounded-full w-5 h-5 flex items-center justify-center">
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
                                class="w-7 h-7 rounded-full mc-bg-primary-light font-bold text-sm mc-text-primary flex items-center justify-center">+</button>
                            <button wire:click="removeFromCart({{ $productId }})"
                                class="w-7 h-7 rounded-full bg-red-50 hover:bg-red-100 text-red-500 text-sm flex items-center justify-center ml-0.5">✕</button>
                        </div>
                    </div>
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
                    <button wire:click="skipCoupon" class="w-full text-xs text-center text-gray-400 hover:text-gray-600 py-1 transition-colors">
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
                <button wire:click="selectOrderType('delivery')" class="w-full flex items-center gap-3 p-4 rounded-xl border-2 border-gray-200 hover:border-red-400 hover:bg-red-50 transition-colors">
                    <span class="text-2xl">🛵</span>
                    <div class="text-left">
                        <p class="font-bold text-gray-800">Entrega</p>
                        <p class="text-xs text-gray-500">Receba em seu endereço</p>
                    </div>
                </button>
                <button wire:click="selectOrderType('pickup')" class="w-full flex items-center gap-3 p-4 rounded-xl border-2 border-gray-200 hover:border-red-400 hover:bg-red-50 transition-colors">
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
            <div wire:key="step-checkout-delivery-address" class="space-y-2">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Endereço de entrega</p>

                {{-- Endereço atual em destaque --}}
                @if ($address)
                    <div class="bg-blue-50 border border-blue-100 rounded-xl px-3 py-2.5 mb-1">
                        <p class="text-xs font-semibold text-blue-700 mb-0.5">Endereço cadastrado</p>
                        <p class="text-sm text-gray-800 font-medium">{{ $address }}@if($complement), {{ $complement }}@endif</p>
                        <p class="text-xs text-gray-500">{{ $neighborhood }}, {{ $city }}@if($cep) — CEP {{ $cep }}@endif</p>
                    </div>
                @endif

                {{-- Botão de localização --}}
                <button type="button" id="map-chk-loc-btn" onclick="mapPickerUseLocation('chk')"
                    class="w-full flex items-center justify-center gap-2 border border-blue-200 bg-blue-50 hover:bg-blue-100 active:bg-blue-200 text-blue-700 text-xs font-semibold rounded-xl py-2.5 transition-colors disabled:opacity-60">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span id="map-chk-span-default">Usar minha localização atual</span>
                    <span id="map-chk-span-loading" style="display:none" class="flex items-center gap-1.5">
                        <svg class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        Obtendo localização...
                    </span>
                </button>
                <p id="map-chk-error" style="display:none" class="text-red-500 text-xs -mt-1"></p>

                {{-- Mapa interativo --}}
                <div id="map-chk-container" style="display:none" class="rounded-xl overflow-hidden border border-blue-200 shadow-sm">
                    <div class="bg-blue-50 px-3 py-1.5 flex items-center justify-between">
                        <span class="text-xs font-semibold text-blue-700">📍 Arraste o pin para ajustar</span>
                        <button type="button" onclick="mapPickerCloseMap('chk')" class="text-blue-400 hover:text-blue-700 text-lg leading-none">×</button>
                    </div>
                    <div id="map-chk-el" style="height:200px; width:100%;"></div>
                    <div class="flex gap-2 p-2 bg-gray-50 border-t border-gray-100">
                        <button type="button" onclick="mapPickerCloseMap('chk')" class="mc-btn-secondary flex-1 !py-1.5 text-xs">Cancelar</button>
                        <button type="button" id="map-chk-confirm-btn" onclick="mapPickerConfirmLocation('chk')"
                            class="mc-btn-primary flex-1 !py-1.5 text-xs disabled:opacity-60">
                            <span id="map-chk-span-confirm">Confirmar local →</span>
                            <span id="map-chk-span-geocoding" style="display:none" class="flex items-center justify-center gap-1.5">
                                <svg class="animate-spin w-3 h-3" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                Buscando endereço...
                            </span>
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">
                        CEP
                        <span x-show="cepLoading" class="text-blue-500 font-normal normal-case ml-1">Buscando...</span>
                    </label>
                    <input
                        wire:model="cep"
                        type="text"
                        placeholder="00000-000"
                        class="mc-input"
                        autocomplete="postal-code"
                        inputmode="numeric"
                        maxlength="9"
                        x-on:input="
                            $event.target.value = formatCep($event.target.value);
                            if ($event.target.value.replace(/\D/g,'').length === 8) lookupCep($event.target.value, $wire);
                        "
                    />
                    @error('cep') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Rua e número</label>
                    <input wire:model="address" type="text" placeholder="Ex: Av. Brasil, 100" class="mc-input" autocomplete="street-address" />
                    @error('address') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Complemento <span class="text-gray-400 font-normal">(opcional)</span></label>
                    <input wire:model="complement" type="text" placeholder="Apto 12, Bloco B..." class="mc-input" autocomplete="address-line2" />
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Bairro</label>
                        <input wire:model="neighborhood" type="text" placeholder="Zona 1" class="mc-input" autocomplete="address-level3" />
                        @error('neighborhood') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Cidade</label>
                        <input wire:model="city" type="text" placeholder="Maringá" class="mc-input" autocomplete="address-level2" />
                        @error('city') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
                    </div>
                </div>
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
                        @if ($freeDelivery || $deliveryFee == 0)
                            <span class="font-bold text-green-600">Grátis</span>
                        @else
                            <span class="font-bold text-gray-800">R$ {{ number_format($deliveryFee, 2, ',', '.') }}</span>
                        @endif
                    </div>
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

                <div class="flex items-center justify-between mc-bg-primary-light rounded-lg px-3 py-2">
                    <span class="text-xs text-gray-600 font-medium">Total a pagar</span>
                    <span class="text-lg font-black mc-text-primary">R$ {{ number_format($this->orderTotal, 2, ',', '.') }}</span>
                </div>

                <div class="flex gap-2">
                    <button wire:click="backToOrderType" class="mc-btn-secondary flex-shrink-0">← Voltar</button>
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
                    <button wire:click="backToNotes" class="mc-btn-secondary flex-shrink-0">← Voltar</button>
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
                        <p class="text-xs text-gray-500">Pagamento instantâneo</p>
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

                <button wire:click="{{ $taxId ? 'backToCpf' : 'backToNotes' }}"
                    class="w-full text-xs text-center text-gray-400 hover:text-gray-600 py-1">
                    ← Voltar
                </button>
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

                {{-- QR Code --}}
                @if ($pixQrCode)
                    <div class="flex justify-center">
                        <div class="mc-qr-frame">
                            <img src="data:image/png;base64,{{ $pixQrCode }}" class="w-40 h-40 rounded-lg" />
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

                {{-- Simular pagamento (apenas sandbox/debug) --}}
                @if(config('app.debug') && $paymentId)
                    <div class="border-t border-dashed border-gray-200 pt-3">
                        <p class="text-[10px] font-bold text-orange-400 uppercase tracking-wider mb-2">Ambiente de Testes</p>
                        <button
                            wire:click="simulatePayment"
                            wire:loading.attr="disabled"
                            wire:target="simulatePayment"
                            class="w-full px-4 py-2.5 rounded-xl text-xs font-bold text-white bg-orange-500 hover:bg-orange-600 transition-colors flex items-center justify-center gap-2"
                        >
                            <span wire:loading.remove wire:target="simulatePayment">⚡ Simular pagamento aprovado</span>
                            <span wire:loading wire:target="simulatePayment" class="flex items-center gap-2">
                                <svg class="animate-spin w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                Simulando...
                            </span>
                        </button>
                    </div>
                @endif

                <p class="text-[11px] text-gray-400">Confirmação automática em até 5s após o pagamento.</p>
            </div>

        {{-- ── PAYMENT_CARD_FORM ── --}}
        @elseif ($step === 'PAYMENT_CARD_FORM')
            <div class="space-y-4 px-1 py-2">
                <p class="text-sm font-medium text-neutral-700 dark:text-neutral-300">💳 Insira os dados do cartão:</p>

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
                        class="w-full rounded-lg border @error('cardNumber') border-red-400 @else border-neutral-300 dark:border-neutral-600 @enderror bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
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
                            class="w-full rounded-lg border @error('cardExpiry') border-red-400 @else border-neutral-300 dark:border-neutral-600 @enderror bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
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
                            class="w-full rounded-lg border @error('cardCvv') border-red-400 @else border-neutral-300 dark:border-neutral-600 @enderror bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
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
                        class="w-full rounded-lg border @error('cardHolderName') border-red-400 @else border-neutral-300 dark:border-neutral-600 @enderror bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
                    />
                    @error('cardHolderName')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>

                {{-- Total à vista --}}
                <p class="text-xs text-neutral-500">
                    Total à vista: <span class="font-semibold text-neutral-700 dark:text-neutral-200">R$ {{ number_format($this->orderTotal, 2, ',', '.') }}</span>
                </p>

                {{-- Erro da API / recusa --}}
                @if ($cardError)
                    <div class="rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-3 py-2">
                        <p class="text-sm text-red-600 dark:text-red-400 font-medium">⚠ {{ $cardError }}</p>
                    </div>
                @endif

                {{-- Botão submit --}}
                <button
                    wire:click="submitCardPayment"
                    wire:loading.attr="disabled"
                    class="w-full rounded-lg bg-purple-600 hover:bg-purple-700 disabled:opacity-60 text-white font-semibold py-2.5 text-sm transition-colors"
                >
                    <span wire:loading.remove wire:target="submitCardPayment">Pagar com cartão</span>
                    <span wire:loading wire:target="submitCardPayment">Processando...</span>
                </button>
            </div>

        {{-- ── ORDER_CONFIRMED ── --}}
        @elseif ($step === 'ORDER_CONFIRMED')
            {{-- Polling de fallback caso o evento Echo não chegue --}}
            @if ($orderId && !in_array($lastNotifiedStatus, ['delivered', 'cancelled']))
                <div wire:poll.15s="checkPaymentStatus" class="hidden"></div>
            @endif
            {{-- Polling de mensagens do admin (fallback Echo) --}}
            @if ($orderId)
                <div wire:poll.4s="pollAdminMessages" class="hidden"></div>
            @endif

            <div class="text-center space-y-3 py-1">
                <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mx-auto text-4xl">
                    🎉
                </div>
                <div>
                    <p class="font-black text-green-600 text-lg">Pedido confirmado!</p>
                    <p class="text-sm text-gray-500 mt-0.5">Seus salgados já estão sendo preparados. 🥟</p>
                </div>

                {{-- Confirmação de cancelamento --}}
                @php
                    $currentOrder = $orderId ? \App\Models\Order::find($orderId) : null;
                    $canCancel = $currentOrder && in_array($currentOrder->status, ['awaiting_payment', 'paid', 'preparing']);
                    $canRefund = $currentOrder && in_array($currentOrder->status, ['paid', 'preparing']) && $currentOrder->payment_method !== 'CASH';
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
                @elseif ($showRefundConfirm)
                    {{-- Confirmação de reembolso --}}
                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-left space-y-2">
                        <p class="text-sm font-semibold text-amber-700">Solicitar reembolso deste pedido?</p>
                        <p class="text-xs text-amber-600">O valor será devolvido ao seu método de pagamento original. Essa ação não pode ser desfeita.</p>
                        <div class="flex gap-2">
                            <button wire:click="confirmRefund" wire:loading.attr="disabled" class="flex-1 bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold py-2 rounded-lg transition-colors">
                                Sim, reembolsar
                            </button>
                            <button wire:click="dismissRefund" class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm font-semibold py-2 rounded-lg transition-colors">
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
                    @if ($canRefund)
                        <button wire:click="requestRefund" class="w-full border border-amber-300 text-amber-700 hover:bg-amber-50 text-sm font-medium py-2 rounded-lg transition-colors">
                            Solicitar reembolso
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

            {{-- Chat de acompanhamento do pedido --}}
            @if ($orderId)
                <div class="border-t border-gray-100 pt-3 mt-1">
                    <div class="flex gap-2">
                        <input
                            wire:model="supportMessage"
                            wire:keydown.enter="sendSupportMessage"
                            type="text"
                            placeholder="Mensagem sobre o pedido..."
                            class="mc-input flex-3"
                            autocomplete="off"
                        />
                        <button
                            wire:click="sendSupportMessage"
                            class="mc-btn-primary px-4 flex-1"
                            wire:loading.attr="disabled"
                            wire:target="sendSupportMessage"
                        >
                            <span wire:loading.remove wire:target="sendSupportMessage">Enviar</span>
                            <span wire:loading wire:target="sendSupportMessage">...</span>
                        </button>
                    </div>
                    @error('supportMessage') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            @endif



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
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">
                        CEP
                        <span x-show="cepLoading" class="text-blue-500 font-normal normal-case ml-1">Buscando...</span>
                    </label>
                    <input
                        wire:model="cep"
                        type="text"
                        placeholder="00000-000"
                        class="mc-input"
                        autocomplete="postal-code"
                        inputmode="numeric"
                        maxlength="9"
                        x-on:input="
                            $event.target.value = formatCep($event.target.value);
                            if ($event.target.value.replace(/\D/g,'').length === 8) lookupCep($event.target.value, $wire);
                        "
                    />
                    @error('cep') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Rua e número</label>
                    <input wire:model="address" type="text" class="mc-input" autocomplete="street-address" />
                    @error('address') <p class="text-red-600 text-xs mt-0.5 flex items-center gap-1"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Complemento <span class="text-gray-400 font-normal">(opcional)</span></label>
                    <input wire:model="complement" type="text" placeholder="Apto 12, Bloco B..." class="mc-input" autocomplete="address-line2" />
                    @error('complement') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Bairro</label>
                        <input wire:model="neighborhood" type="text" class="mc-input" autocomplete="address-level3" />
                        @error('neighborhood') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Cidade</label>
                        <input wire:model="city" type="text" class="mc-input" autocomplete="address-level2" />
                        @error('city') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex gap-2 pt-1">
                    <button wire:click="cancelEditProfile" class="mc-btn-secondary flex-1">Cancelar</button>
                    <button wire:click="submitEditProfile" class="mc-btn-primary flex-1"
                        wire:loading.attr="disabled" wire:target="submitEditProfile">
                        <span wire:loading.remove wire:target="submitEditProfile">Salvar →</span>
                        <span wire:loading wire:target="submitEditProfile">Salvando...</span>
                    </button>
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

    {{-- Always-active support listeners (Echo + poll fallback) --}}
    @if ($supportTicketId)
        <div wire:poll.4s="pollAdminSupportMessages" class="hidden"></div>
        <div
            wire:ignore
            x-data
            x-init="
                if (window.Echo) {
                    window.Echo.channel('support.{{ $supportTicketId }}')
                        .listen('AdminSupportMessageSent', (data) => {
                            $wire.receiveAdminSupportMessage(data);
                        })
                        .listen('SupportTicketClosed', (data) => {
                            $wire.onSupportTicketClosed(data);
                        });
                }
            "
        ></div>
    @endif

    {{-- ═══════════════════════ SUPPORT MODAL ════════════════════════ --}}
    @if ($showSupportModal)
    <div
        class="absolute inset-0 z-40 flex flex-col bg-white overflow-hidden
               sm:rounded-2xl"
        x-data
        x-init="$nextTick(() => { const el = $el.querySelector('[data-support-msgs]'); if (el) el.scrollTop = el.scrollHeight; })"
        x-on:livewire:updated.window="$nextTick(() => { const el = $el.querySelector('[data-support-msgs]'); if (el) el.scrollTop = el.scrollHeight; })"
    >
        {{-- Header --}}
        <div class="shrink-0 px-4 py-3 flex items-center gap-3" style="background: linear-gradient(135deg, var(--mc-red-dark) 0%, var(--mc-red) 60%, var(--mc-red-light) 100%);">
            <div class="flex-1 min-w-0">
                <p class="text-white font-bold text-sm leading-tight">Suporte</p>
                <p class="text-white/70 text-xs mt-0.5">
                    @if ($supportTicketId)
                        Ticket #{{ $supportTicketId }} • Online
                    @else
                        Ticket encerrado
                    @endif
                </p>
            </div>
            <button
                wire:click="closeSupportModal"
                class="text-white/70 hover:text-white transition-colors p-1.5 rounded-lg hover:bg-white/10"
                title="Fechar suporte"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        {{-- Messages --}}
        <div
            data-support-msgs
            class="flex-1 overflow-y-auto px-3 py-4 space-y-3 mc-scrollbar"
        >
            @forelse ($supportConversation as $msg)
                @if ($msg['sender'] === 'system')
                    <div class="flex justify-center">
                        <p class="text-[11px] text-gray-400 bg-gray-100 rounded-full px-3 py-1">{{ $msg['message'] }}</p>
                    </div>
                @elseif ($msg['sender'] === 'customer')
                    <div class="flex justify-end">
                        <div class="max-w-[75%] mc-bubble-user text-white px-3 py-2">
                            <p class="text-sm leading-relaxed whitespace-pre-line">{{ $msg['message'] }}</p>
                            <span class="block text-[10px] mt-0.5 text-right text-white/60">{{ $msg['created_at'] }}</span>
                        </div>
                    </div>
                @else
                    <div class="flex justify-start">
                        <div class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0 mr-1.5 mt-0.5" style="background: var(--mc-red)">
                            🎩
                        </div>
                        <div class="max-w-[75%] mc-bubble-bot text-gray-800 px-3 py-2">
                            <p class="text-sm leading-relaxed whitespace-pre-line">{{ $msg['message'] }}</p>
                            <span class="block text-[10px] mt-0.5 text-right text-gray-400">{{ $msg['created_at'] }}</span>
                        </div>
                    </div>
                @endif
            @empty
                <div class="flex flex-col items-center justify-center h-full gap-2 py-12">
                    <span class="text-3xl">💬</span>
                    <p class="text-sm text-gray-400 text-center">Como podemos ajudar?<br>Digite sua mensagem abaixo.</p>
                </div>
            @endforelse
        </div>

        {{-- Input --}}
        @if ($supportTicketId)
            <div class="border-t border-gray-100 bg-white px-4 py-3 shrink-0 flex gap-2">
                <input
                    wire:model="generalSupportMessage"
                    wire:keydown.enter="sendGeneralSupportMessage"
                    type="text"
                    placeholder="Digite sua mensagem..."
                    class="mc-input flex-3"
                    autocomplete="off"
                />
                <button
                    wire:click="sendGeneralSupportMessage"
                    wire:loading.attr="disabled"
                    wire:target="sendGeneralSupportMessage"
                    class="mc-btn-primary shrink-0 px-4 flex-1"
                >
                
                    <span wire:loading.remove  wire:target="sendGeneralSupportMessage">
                        Enviar

                    </span>
                    <span wire:loading wire:target="sendGeneralSupportMessage">...</span>
                </button>
            </div>
            @error('generalSupportMessage')
                <p class="text-red-500 text-xs px-4 pb-2">{{ $message }}</p>
            @enderror
        @else
            <div class="border-t border-gray-100 bg-gray-50 px-4 py-3 shrink-0 text-center">
                <p class="text-xs text-gray-400">Ticket encerrado.</p>
            </div>
        @endif
    </div>
    @endif

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
            style="max-width:310px; background:linear-gradient(160deg,#180707 0%,#220b0b 50%,#1a0808 100%); border:1px solid rgba(255,255,255,.07)"
        >
            {{-- Card header --}}
            <div class="flex items-center justify-between px-3 py-2" style="border-bottom:1px solid rgba(255,255,255,.06)">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center text-base shrink-0" style="background:rgba(220,38,38,.2);border:1px solid rgba(220,38,38,.2)">🥟</div>
                    <div>
                        <p class="font-black text-sm leading-tight" style="color:#fff">Coxinha Dash</p>
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

</div>

@script
<script>
    Alpine.data('chatApp', () => ({
        copied: false,
        cepLoading: false,

        formatPhone(v) {
            v = v.replace(/\D/g, '').slice(0, 11);
            if (v.length === 0) return '';
            if (v.length <= 2) return '(' + v;
            if (v.length <= 6) return '(' + v.slice(0,2) + ') ' + v.slice(2);
            if (v.length <= 10) return '(' + v.slice(0,2) + ') ' + v.slice(2,6) + '-' + v.slice(6);
            return '(' + v.slice(0,2) + ') ' + v.slice(2,7) + '-' + v.slice(7);
        },

        formatCep(v) {
            v = v.replace(/\D/g, '').slice(0, 8);
            return v.length > 5 ? v.slice(0,5) + '-' + v.slice(5) : v;
        },

        formatCpf(v) {
            v = v.replace(/\D/g, '').slice(0, 11);
            if (v.length <= 3) return v;
            if (v.length <= 6) return v.slice(0,3) + '.' + v.slice(3);
            if (v.length <= 9) return v.slice(0,3) + '.' + v.slice(3,6) + '.' + v.slice(6);
            return v.slice(0,3) + '.' + v.slice(3,6) + '.' + v.slice(6,9) + '-' + v.slice(9);
        },

        async lookupCep(cep, wire) {
            const digits = cep.replace(/\D/g, '');
            if (digits.length !== 8) return;
            this.cepLoading = true;
            try {
                const res = await fetch('https://viacep.com.br/ws/' + digits + '/json/');
                const data = await res.json();
                if (!data.erro) {
                    if (data.logradouro) wire.set('address', data.logradouro);
                    if (data.bairro) wire.set('neighborhood', data.bairro);
                    if (data.localidade) wire.set('city', data.localidade);
                }
            } catch(e) {}
            this.cepLoading = false;
        },
    }));

    // ── Map Picker (pure JS) ─────────────────────────────────────────────────
    var _mapStates = {};

    function _mapEl(prefix, id) {
        return document.getElementById('map-' + prefix + '-' + id);
    }

    function _mapShow(el, visible) {
        if (el) el.style.display = visible ? '' : 'none';
    }

    function _mapSetLoading(prefix, v) {
        var s = _mapStates[prefix];
        if (!s) return;
        s.loading = v;
        var btn = _mapEl(prefix, 'loc-btn');
        if (btn) btn.disabled = v;
        _mapShow(_mapEl(prefix, 'span-default'), !v);
        _mapShow(_mapEl(prefix, 'span-loading'), v);
    }

    function _mapSetError(prefix, msg) {
        var el = _mapEl(prefix, 'error');
        if (!el) return;
        if (msg) { el.textContent = '⚠ ' + msg; _mapShow(el, true); }
        else _mapShow(el, false);
    }

    function _mapSetGeocoding(prefix, v) {
        var s = _mapStates[prefix];
        if (!s) return;
        s.reverseGeocoding = v;
        var btn = _mapEl(prefix, 'confirm-btn');
        if (btn) btn.disabled = v;
        _mapShow(_mapEl(prefix, 'span-confirm'), !v);
        _mapShow(_mapEl(prefix, 'span-geocoding'), v);
    }

    function _mapDestroy(prefix) {
        var s = _mapStates[prefix];
        if (s && s.map) { s.map.remove(); s.map = null; s.marker = null; }
    }

    function _mapBuild(prefix, lat, lng) {
        _mapDestroy(prefix);
        var container = _mapEl(prefix, 'el');
        if (!container) return;
        var s = _mapStates[prefix];
        s.map = L.map(container, { zoomControl: true }).setView([lat, lng], 17);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 19,
        }).addTo(s.map);
        s.marker = L.marker([lat, lng], { draggable: true }).addTo(s.map);
        s.marker.on('dragend', function () {
            var pos = s.marker.getLatLng();
            s.lat = pos.lat;
            s.lng = pos.lng;
        });
        // Duplo rAF garante que o browser já pintou o container antes de recalcular
        var mapRef = s.map;
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                if (mapRef) mapRef.invalidateSize();
            });
        });
    }

    window.mapPickerUseLocation = function (prefix) {
        if (!_mapStates[prefix]) _mapStates[prefix] = { map: null, marker: null, lat: null, lng: null, loading: false, reverseGeocoding: false };
        var s = _mapStates[prefix];
        if (!navigator.geolocation) {
            _mapSetError(prefix, 'Geolocalização não suportada pelo seu navegador.');
            return;
        }
        _mapSetLoading(prefix, true);
        _mapSetError(prefix, null);
        navigator.geolocation.getCurrentPosition(
            function (pos) {
                s.lat = pos.coords.latitude;
                s.lng = pos.coords.longitude;
                _mapSetLoading(prefix, false);
                _mapShow(_mapEl(prefix, 'container'), true);
                // rAF duplo: aguarda o browser renderizar o container antes de inicializar o Leaflet
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () {
                        _mapBuild(prefix, s.lat, s.lng);
                    });
                });
            },
            function () {
                _mapSetLoading(prefix, false);
                _mapSetError(prefix, 'Não foi possível obter sua localização. Verifique as permissões do navegador.');
            },
            { enableHighAccuracy: true, timeout: 12000 }
        );
    };

    window.mapPickerCloseMap = function (prefix) {
        _mapShow(_mapEl(prefix, 'container'), false);
        _mapDestroy(prefix);
    };

    window.mapPickerConfirmLocation = async function (prefix) {
        if (!_mapStates[prefix]) return;
        var s = _mapStates[prefix];
        if (!s.lat || !s.lng) return;
        if (s.marker) {
            var pos = s.marker.getLatLng();
            s.lat = pos.lat;
            s.lng = pos.lng;
        }
        _mapSetGeocoding(prefix, true);
        _mapSetError(prefix, null);
        try {
            var res = await fetch(
                'https://nominatim.openstreetmap.org/reverse?lat=' + s.lat + '&lon=' + s.lng + '&format=json&accept-language=pt-BR',
                { headers: { 'Accept-Language': 'pt-BR,pt;q=0.9' } }
            );
            var data = await res.json();
            var a = data.address || {};

            var street = a.road || a.pedestrian || a.footway || '';
            var num    = a.house_number || '';
            if (street) $wire.set('address', num ? street + ', ' + num : street);

            var bairro = a.suburb || a.neighbourhood || a.city_district || a.quarter || '';
            if (bairro) $wire.set('neighborhood', bairro);

            var cidade = a.city || a.town || a.village || a.municipality || '';
            if (cidade) $wire.set('city', cidade);

            if (a.postcode) {
                var digits = a.postcode.replace(/\D/g, '').slice(0, 8);
                $wire.set('cep', digits.length === 8 ? digits.slice(0,5) + '-' + digits.slice(5) : digits);
            }
        } catch (e) {
            _mapSetError(prefix, 'Erro ao buscar o endereço. Arraste o pin e tente novamente.');
            _mapSetGeocoding(prefix, false);
            return;
        }
        _mapSetGeocoding(prefix, false);
        window.mapPickerCloseMap(prefix);
    };
</script>
@endscript
