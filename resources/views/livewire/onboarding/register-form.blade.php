<div class="flex flex-col gap-8">

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
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

                {{-- FREE --}}
                <label class="relative flex flex-col cursor-pointer rounded-2xl border-2 p-5 transition-all duration-200 select-none
                    {{ $plan === 'free'
                        ? 'border-[#7A00A3] bg-purple-50 shadow-md shadow-purple-100'
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
                    <p class="mt-1 text-xs text-zinc-400">+ R$ 99 taxa de ativação</p>
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
                        ? 'border-[#7A00A3] bg-purple-50 shadow-md shadow-purple-100'
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
                        ? 'border-[#7A00A3] bg-purple-50 shadow-md shadow-purple-100'
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

            </div>

            @error('plan')
                <p class="text-sm text-red-600 -mt-2">{{ $message }}</p>
            @enderror

            {{-- Setup fee notice --}}
            <div class="rounded-xl bg-zinc-50 border border-zinc-200 p-4 text-sm text-zinc-600">
                <p class="font-semibold text-zinc-800 mb-1">💳 Taxa de ativação única</p>
                <p>Todos os planos incluem uma taxa de ativação de <strong class="text-zinc-900">R$ 99,00</strong> cobrada uma única vez para configuração da sua conta de pagamentos.</p>
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
                    class="flex-1 flex items-center justify-center gap-2 rounded-xl px-6 py-2.5 text-sm font-bold text-white shadow-sm transition-all duration-200 disabled:opacity-75"
                    style="background: linear-gradient(135deg, #5c0079, #7A00A3);"
                >
                    <span wire:loading.remove wire:target="submit">
                        @if($plan === 'pro')
                            🚀 Criar conta PRO — R$ 99 + R$ 119/mês
                        @elseif($plan === 'essencial')
                            📦 Criar conta Essencial — R$ 99 + R$ 59/mês
                        @else
                            Criar conta Grátis — R$ 99 ativação
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

</div>
