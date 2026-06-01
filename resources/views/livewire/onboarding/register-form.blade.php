<div class="flex flex-col gap-8" x-data="{ showTerms: false, termsAccepted: false }">

    {{-- ── Header ── --}}
    <div>
        <p class="text-xs font-semibold uppercase tracking-widest text-[#7A00A3] mb-2">Cadastro gratuito</p>
        <h2 class="text-2xl lg:text-3xl font-extrabold text-zinc-900 leading-tight" style="font-family: 'Montserrat', sans-serif;">
            @if($currentStep === 1) Dados da empresa
            @elseif($currentStep === 2) Sua primeira filial
            @elseif($currentStep === 3) Sua conta de acesso
            @else Escolha seu plano
            @endif
        </h2>
        <p class="mt-1 text-sm text-zinc-500">
            @if($currentStep === 1) Como sua empresa será identificada na plataforma.
            @elseif($currentStep === 2) Você pode adicionar mais filiais depois.
            @elseif($currentStep === 3) Esses são os dados para acessar o painel.
            @else Sem surpresas. Mude de plano quando quiser.
            @endif
        </p>
    </div>

    {{-- ── Step progress ── --}}
    <div class="flex items-center gap-2">
        @foreach(['Empresa', 'Filial', 'Usuário', 'Plano'] as $i => $label)
            @php $s = $i + 1; @endphp
            <div class="flex items-center {{ $s < 4 ? 'flex-1' : '' }}">
                <div class="flex flex-col items-center gap-1">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold transition-all duration-200
                        {{ $currentStep > $s ? 'bg-[#5c0079] text-white' : ($currentStep === $s ? 'bg-[#7A00A3] text-white ring-4 ring-purple-100' : 'bg-zinc-100 text-zinc-400') }}">
                        @if($currentStep > $s)
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                        @else
                            {{ $s }}
                        @endif
                    </div>
                    <span class="text-[10px] font-medium {{ $currentStep === $s ? 'text-[#5c0079]' : 'text-zinc-400' }}">{{ $label }}</span>
                </div>
                @if($s < 4)
                    <div class="flex-1 h-0.5 mx-2 mb-4 rounded-full transition-all duration-300
                        {{ $currentStep > $s ? 'bg-[#5c0079]' : 'bg-zinc-200' }}"></div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- ── Error ── --}}
    @if($errorMessage)
        <div class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <svg class="h-5 w-5 shrink-0 mt-0.5 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
            <span>{{ $errorMessage }}</span>
        </div>
    @endif

    {{-- ════════════════════════════════════
         STEP 1 — Company
    ════════════════════════════════════ --}}
    @if($currentStep === 1)
        <div class="flex flex-col gap-5">
            <div>
                <flux:input
                    wire:model.live.debounce.400ms="companyName"
                    label="Nome da empresa"
                    placeholder="Ex: Hamburgueria do João"
                    required
                    autofocus
                />
            </div>

            <div>
                <flux:input
                    wire:model.blur="slug"
                    label="Endereço da loja (slug)"
                    placeholder="hamburgueria-do-joao"
                    required
                />
                @if($slug)
                    <p class="mt-2 flex items-center gap-1.5 text-xs text-zinc-500">
                        <svg class="h-3.5 w-3.5 text-[#7A00A3]" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                        </svg>
                        Sua loja ficará em:
                        <code class="font-mono font-medium text-[#5c0079] bg-purple-50 px-1.5 py-0.5 rounded">/{{ $slug }}</code>
                    </p>
                @endif
                @error('slug') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <flux:button wire:click="nextStep" variant="primary" class="w-full bg-[#7A00A3]! hover:bg-[#5c0079]! text-white! mt-2">
               Continuar →
            </flux:button>
        </div>
    @endif

    {{-- ════════════════════════════════════
         STEP 2 — Branch
    ════════════════════════════════════ --}}
    @if($currentStep === 2)
        <div class="flex flex-col gap-5">
            <flux:input
                wire:model="branchName"
                label="Nome da filial principal"
                placeholder="Ex: Loja Centro"
                required
                autofocus
            />

            <div>
                <flux:input
                    wire:model.live="branchPhone"
                    label="Telefone da filial"
                    placeholder="(11) 99999-9999"
                    required
                    maxlength="15"
                />
                @if($branchPhoneValid)
                    <p class="mt-1.5 flex items-center gap-1.5 text-xs text-green-600">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        Telefone válido
                    </p>
                @elseif($branchPhoneError)
                    <p class="mt-1.5 flex items-center gap-1.5 text-xs text-red-600">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                        </svg>
                        {{ $branchPhoneError }}
                    </p>
                @endif
                @error('branchPhone')
                    <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex gap-3 mt-2">
                <button wire:click="prevStep"
                    class="flex-1 flex items-center justify-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-600 hover:bg-zinc-50 transition-colors">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Voltar
                </button>
                <flux:button wire:click="nextStep" variant="primary" class="flex-1 bg-[#7A00A3]! hover:bg-[#5c0079]! text-white!">
                    Continuar →
                </flux:button>
            </div>
        </div>
    @endif

    {{-- ════════════════════════════════════
         STEP 3 — User
    ════════════════════════════════════ --}}
    @if($currentStep === 3)
        <div class="flex flex-col gap-5">
            <flux:input
                wire:model="userName"
                label="Seu nome completo"
                placeholder="João Silva"
                required
                autofocus
            />

            <flux:input
                wire:model="userEmail"
                label="E-mail"
                type="email"
                placeholder="joao@empresa.com.br"
                required
                autocomplete="email"
            />

            <div>
                <flux:input
                    wire:model.live="userPassword"
                    label="Senha"
                    type="password"
                    placeholder="Mínimo 8 caracteres"
                    required
                    viewable
                />

                @if($passwordStrength > 0)
                    {{-- Strength bar --}}
                    <div class="mt-2.5 flex gap-1">
                        @for($i = 1; $i <= 3; $i++)
                            <div class="h-1 flex-1 rounded-full transition-all duration-300 {{ $passwordStrength >= $i
                                ? ($passwordStrength === 1 ? 'bg-red-400' : ($passwordStrength === 2 ? 'bg-amber-400' : 'bg-green-500'))
                                : 'bg-zinc-200' }}"></div>
                        @endfor
                    </div>
                    <p class="mt-1 text-xs font-medium
                        {{ $passwordStrength === 1 ? 'text-red-500' : ($passwordStrength === 2 ? 'text-amber-500' : 'text-green-600') }}">
                        {{ $passwordStrength === 1 ? 'Senha fraca' : ($passwordStrength === 2 ? 'Senha média' : 'Senha forte') }}
                    </p>

                    {{-- Checklist --}}
                    <ul class="mt-2.5 space-y-1">
                        @foreach([
                            [$passwordHasMin,     '8 caracteres no mínimo'],
                            [$passwordHasLetter,  'Contém letras'],
                            [$passwordHasNumber,  'Contém números'],
                            [$passwordHasSpecial, 'Contém caractere especial'],
                        ] as [$ok, $label])
                            <li class="flex items-center gap-2 text-xs {{ $ok ? 'text-green-600' : 'text-zinc-400' }}">
                                @if($ok)
                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                @else
                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                @endif
                                {{ $label }}
                            </li>
                        @endforeach
                    </ul>
                @endif

                @error('userPassword')
                    <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex gap-3 mt-2">
                <button wire:click="prevStep"
                    class="flex-1 flex items-center justify-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-600 hover:bg-zinc-50 transition-colors">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Voltar
                </button>
                <flux:button wire:click="nextStep" variant="primary" class="flex-1 bg-[#7A00A3]! hover:bg-[#5c0079]! text-white!">
                   Continuar →
                </flux:button>
            </div>
        </div>
    @endif

    {{-- ════════════════════════════════════
         STEP 4 — Plan Selection
    ════════════════════════════════════ --}}
    @if($currentStep === 4)
        <div class="flex flex-col gap-6">

            {{-- Plan cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                {{-- FREE --}}
                <label class="relative flex flex-col cursor-pointer rounded-2xl border-2 p-5 transition-all duration-200 select-none
                    {{ $plan === 'free'
                        ? 'border-[#7A00A3] shadow-md shadow-purple-100'
                        : 'border-zinc-200 bg-white hover:border-zinc-300 hover:shadow-sm' }}">
                    <input type="radio" wire:model.live="plan" value="free" class="sr-only" />

                    <div class="absolute top-3.5 right-3.5 h-5 w-5 rounded-full border-2 transition-all
                        {{ $plan === 'free' ? 'border-[#7A00A3] bg-[#7A00A3]' : 'border-zinc-300 bg-white' }}
                        flex items-center justify-center">
                        @if($plan === 'free')
                            <svg class="h-2.5 w-2.5 text-white" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3"/></svg>
                        @endif
                    </div>

                    <span class="text-2xl mb-3">🆓</span>
                    <p class="font-bold text-zinc-900 text-base">Grátis</p>
                    <p class="mt-0.5 text-2xl font-extrabold text-zinc-900" style="font-family: 'Montserrat', sans-serif;">
                        R$ 0<span class="text-sm font-normal text-zinc-400">/mês</span>
                    </p>
                    <p class="mt-1 text-xs text-green-600 font-medium">Sem taxa de ativação</p>
                    <div class="mt-4 space-y-2 text-sm text-zinc-600">
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            Sem mensalidade
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            Até 50 pedidos/mês
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                            <span class="text-amber-700 font-medium">1% por pedido</span>
                        </div>
                    </div>
                </label>

                {{-- ESSENCIAL --}}
                <label class="relative flex flex-col cursor-pointer rounded-2xl border-2 p-5 transition-all duration-200 select-none
                    {{ $plan === 'essencial'
                        ? 'border-[#7A00A3]  shadow-md shadow-purple-100'
                        : 'border-zinc-200 bg-white hover:border-purple-200 hover:shadow-sm' }}">
                    <input type="radio" wire:model.live="plan" value="essencial" class="sr-only" />

                    {{-- Recommended badge --}}
                    <div class="absolute -top-3 left-1/2 -translate-x-1/2">
                        <span class="inline-flex items-center gap-1 rounded-full px-3 py-0.5 text-xs font-bold text-white shadow-sm"
                              style="background: linear-gradient(90deg, #5c0079, #7A00A3);">
                            ⭐ Recomendado
                        </span>
                    </div>

                    <div class="absolute top-3.5 right-3.5 h-5 w-5 rounded-full border-2 transition-all
                        {{ $plan === 'essencial' ? 'border-[#7A00A3] bg-[#7A00A3]' : 'border-zinc-300 bg-white' }}
                        flex items-center justify-center">
                        @if($plan === 'essencial')
                            <svg class="h-2.5 w-2.5 text-white" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3"/></svg>
                        @endif
                    </div>

                    <span class="text-2xl mb-3">📦</span>
                    <p class="font-bold text-zinc-900 text-base">Essencial</p>
                    <p class="mt-0.5 text-2xl font-extrabold text-zinc-900" style="font-family: 'Montserrat', sans-serif;">
                        R$ 59<span class="text-sm font-normal text-zinc-400">/mês</span>
                    </p>
                    <p class="mt-1 text-xs text-zinc-400">+ R$ 99 taxa de ativação</p>
                    <div class="mt-4 space-y-2 text-sm text-zinc-600">
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            Pedidos ilimitados
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            <span class="font-semibold text-green-700">Zero taxas por pedido</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            1 filial
                        </div>
                    </div>
                </label>

                {{-- PRO --}}
                <label class="relative flex flex-col cursor-pointer rounded-2xl border-2 p-5 transition-all duration-200 select-none
                    {{ $plan === 'pro'
                        ? 'border-[#7A00A3] shadow-md shadow-purple-100'
                        : 'border-zinc-200 bg-white hover:border-zinc-300 hover:shadow-sm' }}">
                    <input type="radio" wire:model.live="plan" value="pro" class="sr-only" />

                    <div class="absolute top-3.5 right-3.5 h-5 w-5 rounded-full border-2 transition-all
                        {{ $plan === 'pro' ? 'border-[#7A00A3] bg-[#7A00A3]' : 'border-zinc-300 bg-white' }}
                        flex items-center justify-center">
                        @if($plan === 'pro')
                            <svg class="h-2.5 w-2.5 text-white" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3"/></svg>
                        @endif
                    </div>

                    <span class="text-2xl mb-3">🚀</span>
                    <p class="font-bold text-zinc-900 text-base">PRO</p>
                    <p class="mt-0.5 text-2xl font-extrabold text-zinc-900" style="font-family: 'Montserrat', sans-serif;">
                        R$ 119<span class="text-sm font-normal text-zinc-400">/mês</span>
                    </p>
                    <p class="mt-1 text-xs text-zinc-400">+ R$ 99 taxa de ativação</p>
                    <div class="mt-4 space-y-2 text-sm text-zinc-600">
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            Pedidos ilimitados
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            <span class="font-semibold text-green-700">Zero taxas por pedido</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            Até 3 filiais
                        </div>
                    </div>
                </label>

                {{-- PDV --}}
                <label class="relative flex flex-col cursor-pointer rounded-2xl border-2 p-5 transition-all duration-200 select-none
                    {{ $plan === 'pdv'
                        ? 'border-[#7A00A3] shadow-md shadow-purple-100'
                        : 'border-zinc-200 bg-white hover:border-zinc-300 hover:shadow-sm' }}">
                    <input type="radio" wire:model.live="plan" value="pdv" class="sr-only" />

                    <div class="absolute top-3.5 right-3.5 h-5 w-5 rounded-full border-2 transition-all
                        {{ $plan === 'pdv' ? 'border-[#7A00A3] bg-[#7A00A3]' : 'border-zinc-300 bg-white' }}
                        flex items-center justify-center">
                        @if($plan === 'pdv')
                            <svg class="h-2.5 w-2.5 text-white" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3"/></svg>
                        @endif
                    </div>

                    <span class="text-2xl mb-3">🖥️</span>
                    <p class="font-bold text-zinc-900 text-base">PDV</p>
                    <p class="mt-0.5 text-2xl font-extrabold text-zinc-900" style="font-family: 'Montserrat', sans-serif;">
                        R$ 199<span class="text-sm font-normal text-zinc-400">/mês</span>
                    </p>
                    <p class="mt-1 text-xs text-zinc-400">+ R$ 99 taxa de ativação</p>
                    <div class="mt-4 space-y-2 text-sm text-zinc-600">
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            Pedidos ilimitados
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            <span class="font-semibold text-green-700">Terminal PDV</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            Até 5 filiais
                        </div>
                    </div>
                </label>

            </div>

            @error('plan')
                <p class="text-sm text-red-600 -mt-2">{{ $message }}</p>
            @enderror

            {{-- Breakdown do pagamento --}}
            <div class="rounded-xl bg-zinc-50 border border-zinc-200 p-4 text-sm text-zinc-600 space-y-2">
                @if($this->planHasMonthly)
                    <p class="font-semibold text-zinc-800">💳 Resumo do pagamento inicial</p>
                    <div class="flex justify-between text-zinc-600">
                        <span>Taxa de ativação (única)</span>
                        <span class="font-medium">R$ {{ number_format(App\Enums\Plan::tryFrom($plan)?->setupFee() ?? 99, 2, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between text-zinc-600">
                        <span>1º mês — {{ App\Enums\Plan::tryFrom($plan)?->label() }}</span>
                        <span class="font-medium">R$ {{ number_format($this->planMonthlyPrice, 2, ',', '.') }}</span>
                    </div>
                    <div class="flex justify-between font-bold text-zinc-900 border-t border-zinc-200 pt-2">
                        <span>Total hoje</span>
                        <span>R$ {{ number_format($this->firstPaymentTotal, 2, ',', '.') }}</span>
                    </div>
                    <p class="text-xs text-zinc-400">A partir do 2º mês: R$ {{ number_format($this->planMonthlyPrice, 2, ',', '.') }}/mês</p>
                @elseif($plan === 'free')
                    <p class="font-semibold text-zinc-800 mb-1">🎉 Plano Grátis — sem pagamento</p>
                    <p>Nenhuma cobrança na ativação. Sua conta será criada imediatamente após o cadastro.</p>
                @else
                    <p class="font-semibold text-zinc-800 mb-1">💳 Taxa de ativação única</p>
                    <p>Taxa de <strong class="text-zinc-900">R$ {{ number_format(App\Enums\Plan::tryFrom($plan)?->setupFee() ?? 99, 2, ',', '.') }}</strong> cobrada uma única vez.</p>
                @endif
            </div>

            {{-- CPF/CNPJ --}}
            <div>
                <flux:input
                    wire:model.live="asaasCpfCnpj"
                    label="CPF ou CNPJ do responsável"
                    placeholder="000.000.000-00 ou 00.000.000/0001-00"
                    required
                    maxlength="18"
                />
                @if($asaasCpfCnpjValid)
                    <p class="mt-1.5 flex items-center gap-1.5 text-xs text-green-600">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        {{ strlen(preg_replace('/\D/', '', $asaasCpfCnpj)) === 11 ? 'CPF válido' : 'CNPJ válido' }}
                    </p>
                @elseif($asaasCpfCnpjError)
                    <p class="mt-1.5 flex items-center gap-1.5 text-xs text-red-600">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                        </svg>
                        {{ $asaasCpfCnpjError }}
                    </p>
                @else
                    <p class="mt-1.5 text-xs text-zinc-400 flex items-center gap-1">
                        <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                        </svg>
                        Necessário para processar pagamentos. Seus dados são protegidos.
                    </p>
                @endif
                @error('asaasCpfCnpj')
                    <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Payment Method fixo em cartão de crédito --}}
            @if($plan !== 'free')
            <div class="flex items-center gap-3 rounded-xl border-2 border-[#7A00A3] shadow-sm shadow-purple-100 p-4 bg-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-[#7A00A3] shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                </svg>
                <div>
                    <p class="text-sm font-semibold text-[#5c0079]">Cartão de crédito</p>
                    <p class="text-xs text-zinc-400">Pagamento processado com segurança via link</p>
                </div>
            </div>
            @endif

            {{-- Termos de Responsabilidade --}}
            @if($plan !== 'free')
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" x-model="termsAccepted" class="mt-0.5 accent-[#7A00A3] shrink-0">
                <span class="text-sm text-zinc-600">
                    Li e aceito os
                    <button type="button" x-on:click.prevent="showTerms = true"
                            class="text-[#7A00A3] hover:underline font-medium">
                        Termos de Responsabilidade
                    </button>
                </span>
            </label>
            @endif

            {{-- Submit --}}
            <div class="flex gap-3 pt-2">
                <button wire:click="prevStep"
                    class="flex items-center justify-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-5 py-2.5 text-sm font-medium text-zinc-600 hover:bg-zinc-50 transition-colors">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Voltar
                </button>

                <button
                    wire:click="submit"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-75 cursor-not-allowed"
                    :disabled="{{ $plan !== 'free' ? 'true' : 'false' }} && !termsAccepted"
                    :class="{{ $plan !== 'free' ? 'true' : 'false' }} && !termsAccepted ? 'opacity-50 cursor-not-allowed' : ''"
                    class="flex-1 flex items-center justify-center gap-2 rounded-xl px-6 py-2.5 text-sm font-bold text-white shadow-sm transition-all duration-200 disabled:opacity-75"
                    style="background: linear-gradient(135deg, #5c0079, #7A00A3);"
                >
                    <span wire:loading.remove wire:target="submit">
                        @if($plan === 'pro')
                            🚀 Criar conta PRO — R$ {{ number_format($this->firstPaymentTotal, 2, ',', '.') }}
                        @elseif($plan === 'essencial')
                            📦 Criar conta Essencial — R$ {{ number_format($this->firstPaymentTotal, 2, ',', '.') }}
                        @else
                            Criar conta Grátis — sem cobrança
                        @endif
                    </span>
                    <span wire:loading wire:target="submit" class="flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Criando sua conta...
                    </span>
                </button>
            </div>

        </div>
    @endif

    {{-- ════════════════════════════════════
         MODAL — Dados do cartão de crédito
    ════════════════════════════════════ --}}
<div
    x-data="{ show: false }"
    x-on:open-card-modal.window="show = true"
    x-show="show"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    style="display:none"
    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
>
    <div
        @click.outside="show = false"
        x-data="{
            maskCardNumber(e) {
                let v = e.target.value.replace(/\D/g, '').substring(0, 16);
                v = v.replace(/(\d{4})(?=\d)/g, '$1 ');
                e.target.value = v;
                $wire.set('cardNumber', v);
            },
            maskExpiry(e) {
                let v = e.target.value.replace(/\D/g, '').substring(0, 4);
                if (v.length >= 3) v = v.substring(0, 2) + '/' + v.substring(2);
                e.target.value = v;
                $wire.set('cardExpiry', v);
            },
            maskCvv(e) {
                let v = e.target.value.replace(/\D/g, '').substring(0, 4);
                e.target.value = v;
                $wire.set('cardCvv', v);
            },
            maskCpfCnpj(e) {
                let v = e.target.value.replace(/\D/g, '');
                if (v.length <= 11) {
                    v = v.substring(0, 11)
                         .replace(/(\d{3})(\d)/, '$1.$2')
                         .replace(/(\d{3})(\d)/, '$1.$2')
                         .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
                } else {
                    v = v.substring(0, 14)
                         .replace(/(\d{2})(\d)/, '$1.$2')
                         .replace(/(\d{3})(\d)/, '$1.$2')
                         .replace(/(\d{3})(\d)/, '$1/$2')
                         .replace(/(\d{4})(\d{1,2})$/, '$1-$2');
                }
                e.target.value = v;
                $wire.set('cardCpfCnpj', v);
            },
            maskCep(e) {
                let v = e.target.value.replace(/\D/g, '').substring(0, 8);
                if (v.length > 5) v = v.substring(0, 5) + '-' + v.substring(5);
                e.target.value = v;
                $wire.set('cardPostalCode', v);
            },
        }"
        class="w-full max-w-2xl bg-white rounded-2xl shadow-2xl overflow-y-auto max-h-[90vh]"
    >

        {{-- Header --}}
        <div class="flex items-center justify-between p-6 border-b border-zinc-100">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-[#7A00A3] mb-1">Pagamento inicial</p>
                <h2 class="text-xl font-extrabold text-zinc-900" style="font-family: 'Montserrat', sans-serif;">Dados do cartão</h2>
                @if($this->planHasMonthly)
                    <p class="text-sm text-zinc-500 mt-0.5">R$ {{ number_format($this->firstPaymentTotal, 2, ',', '.') }} (ativação + 1º mês)</p>
                @else
                    <p class="text-sm text-zinc-500 mt-0.5">Taxa única de ativação — R$ {{ number_format($this->firstPaymentTotal, 2, ',', '.') }}</p>
                @endif
            </div>
            <button @click="show = false" class="rounded-lg p-1.5 text-zinc-400 hover:text-zinc-600 hover:bg-zinc-100 transition-colors">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        {{-- Formulário --}}
        <div class="p-6 space-y-5">

            @if($cardSuccess)
                <div class="flex flex-col items-center gap-3 py-8 text-center">
                    <div class="flex h-14 w-14 items-center justify-center rounded-full bg-green-100">
                        <svg class="h-7 w-7 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    </div>
                    <p class="font-semibold text-zinc-800">Pagamento confirmado!</p>
                    <p class="text-sm text-zinc-500">Redirecionando para o login...</p>
                </div>
            @else

                {{-- Erro --}}
                @if($cardError)
                    <div class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                        <svg class="h-5 w-5 shrink-0 mt-0.5 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        <span>{{ $cardError }}</span>
                    </div>
                @endif

                {{-- Número do cartão --}}
                <div>
                    <flux:input
                        wire:model="cardNumber"
                        x-on:input="maskCardNumber($event)"
                        label="Número do cartão"
                        placeholder="0000 0000 0000 0000"
                        maxlength="19"
                        inputmode="numeric"
                        autocomplete="cc-number"
                    />
                    @error('cardNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Validade e CVV --}}
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:input
                            wire:model="cardExpiry"
                            x-on:input="maskExpiry($event)"
                            label="Validade"
                            placeholder="MM/AA"
                            maxlength="5"
                            inputmode="numeric"
                            autocomplete="cc-exp"
                        />
                        @error('cardExpiry') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:input
                            wire:model="cardCvv"
                            x-on:input="maskCvv($event)"
                            label="CVV"
                            placeholder="123"
                            maxlength="4"
                            inputmode="numeric"
                            autocomplete="cc-csc"
                            type="password"
                            viewable
                        />
                        @error('cardCvv') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Nome no cartão --}}
                <div>
                    <flux:input
                        wire:model="cardHolderName"
                        x-on:input="$wire.set('cardHolderName', $event.target.value.toUpperCase()); $event.target.value = $event.target.value.toUpperCase()"
                        label="Nome conforme no cartão"
                        placeholder="NOME SOBRENOME"
                        autocomplete="cc-name"
                    />
                    @error('cardHolderName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <hr class="border-zinc-100">
                <p class="text-xs text-zinc-400 -mt-2">Dados do titular para cobrança</p>

                {{-- CPF/CNPJ --}}
                <div>
                    <flux:input
                        wire:model="cardCpfCnpj"
                        x-on:input="maskCpfCnpj($event)"
                        label="CPF ou CNPJ do titular"
                        placeholder="000.000.000-00"
                        maxlength="18"
                        inputmode="numeric"
                    />
                    @error('cardCpfCnpj') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- CEP e Número --}}
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:input
                            wire:model="cardPostalCode"
                            x-on:input="maskCep($event)"
                            label="CEP"
                            placeholder="00000-000"
                            maxlength="9"
                            inputmode="numeric"
                        />
                        @error('cardPostalCode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:input
                            wire:model="cardAddressNumber"
                            label="Número"
                            placeholder="123"
                        />
                        @error('cardAddressNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Botão pagar --}}
                <button
                    wire:click="submitCard"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-75 cursor-not-allowed"
                    @if($cardProcessing) disabled @endif
                    class="w-full flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-bold text-white shadow-sm transition-all duration-200 disabled:opacity-75"
                    style="background: linear-gradient(135deg, #5c0079, #7A00A3);"
                >
                    <span wire:loading.remove wire:target="submitCard">
                        <svg class="h-4 w-4 inline mr-1" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                        </svg>
                        Pagar R$ {{ number_format($this->firstPaymentTotal, 2, ',', '.') }}
                    </span>
                    <span wire:loading wire:target="submitCard" class="flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        Processando...
                    </span>
                </button>

                @if($this->planHasMonthly)
                    <p class="text-center text-xs text-zinc-500">
                        A partir do 2º mês: R$ {{ number_format($this->planMonthlyPrice, 2, ',', '.') }}/mês
                    </p>
                @endif

                <p class="text-center text-xs text-zinc-400 flex items-center justify-center gap-1">
                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                    Pagamento processado com segurança via Asaas
                </p>

            @endif

        </div>
    </div>
</div>

{{-- Modal de Termos de Responsabilidade --}}
<div
    x-show="showTerms"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    style="display:none"
    class="fixed inset-0 z-50 overflow-y-auto bg-black/60 backdrop-blur-sm p-4 sm:p-6"
>
    <div class="flex min-h-full items-center justify-center">
        
        <div
            @click.outside="showTerms = false"
            class="relative w-full max-w-2xl transform rounded-2xl bg-white shadow-2xl transition-all"
        >
            <div class="flex items-center justify-between p-6 border-b border-zinc-100">
                <h2 class="text-xl font-extrabold text-zinc-900" style="font-family: 'Montserrat', sans-serif;">Termos de Responsabilidade</h2>
                <button @click="showTerms = false" class="rounded-lg p-1.5 text-zinc-400 hover:text-zinc-600 hover:bg-zinc-100 transition-colors">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="p-6 space-y-4 text-sm text-zinc-700">
                <p><strong>Última atualização:</strong> 30/03/2026</p>

                <p>Ao assinar um plano pago na plataforma <strong>Veddi</strong>, você declara que leu, compreendeu e concorda com os termos e condições descritos abaixo.</p>

                <div>
                    <h3 class="font-semibold text-zinc-900 mb-1">1. Contratação e Pagamento</h3>
                    <p>A assinatura é cobrada mensalmente de forma recorrente, na data de vencimento estabelecida no momento da contratação. O não pagamento dentro do prazo resultará no bloqueio temporário do acesso à plataforma até a regularização do débito.</p>
                </div>

                <div>
                    <h3 class="font-semibold text-zinc-900 mb-1">2. Dados de Pagamento</h3>
                    <p>Os dados do cartão de crédito são transmitidos diretamente à operadora de pagamentos (<strong>Asaas</strong>) via checkout transparente e não são armazenados em nossos servidores.</p>
                </div>

                <div>
                    <h3 class="font-semibold text-zinc-900 mb-1">3. Limites de Uso</h3>
                    <p>Cada plano possui limites específicos de uso, incluindo número de pedidos mensais, quantidade de filiais e funcionalidades disponíveis. Pedidos que excedam 50 por mês estão sujeitos a uma taxa adicional de <strong>3%</strong>.</p>
                </div>

                <div>
                    <h3 class="font-semibold text-zinc-900 mb-1">4. Cancelamento</h3>
                    <p>O cancelamento pode ser solicitado a qualquer momento diretamente pelo painel. Não há reembolso proporcional de períodos parciais utilizados.</p>
                </div>

                <div>
                    <h3 class="font-semibold text-zinc-900 mb-1">5. Responsabilidades do Contratante</h3>
                    <p>É de responsabilidade exclusiva do contratante: manter dados atualizados, garantir uso adequado e zelar pela segurança das credenciais.</p>
                </div>

                <div>
                    <h3 class="font-semibold text-zinc-900 mb-1">6. Responsabilidades da Plataforma</h3>
                    <p>A Veddi se compromete a manter a plataforma funcional, mas não se responsabiliza por falhas de terceiros ou mau uso do contratante.</p>
                </div>

                <div>
                    <h3 class="font-semibold text-zinc-900 mb-1">7. Proteção de Dados (LGPD)</h3>
                    <p>Ao contratar, você consente com o tratamento de dados para fins de cobrança e suporte, conforme a <strong>Lei nº 13.709/2018</strong>.</p>
                </div>

                <div>
                    <h3 class="font-semibold text-zinc-900 mb-1">8. Alterações nos Termos</h3>
                    <p>Notificaremos por e-mail com antecedência mínima de <strong>15 dias</strong> sobre alterações relevantes.</p>
                </div>

                <p class="text-xs text-zinc-400 pt-2 border-t border-zinc-50">Em caso de dúvidas, entre em contato com nosso suporte.</p>
            </div>

            <div class=" flex-col sm:flex-row items-center justify-between gap-3 p-6 border-t border-zinc-100 bg-zinc-50/50 rounded-b-2xl">
                <button
                    @click="termsAccepted = true; showTerms = false"
                    class="w-full sm:flex-1 rounded-xl px-6 py-3 text-sm font-bold text-white shadow-md transition-all duration-200 active:scale-95"
                    style="background: linear-gradient(135deg, #5c0079, #7A00A3);"
                >
                    Li e aceito os termos
                </button>
                <button
                    @click="showTerms = false"
                    class="w-full sm:w-auto rounded-xl border border-zinc-200 bg-white px-8 py-3 text-sm font-medium text-zinc-600 hover:bg-zinc-50 transition-colors"
                >
                    Fechar
                </button>
            </div>
        </div>
    </div>
</div>
</div>
