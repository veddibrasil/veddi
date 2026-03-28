<div class="space-y-6">

    {{-- ══════════════ CHECKLIST DE CONFIGURAÇÃO INICIAL ══════════════ --}}
    <livewire:admin.setup-checklist />

    {{-- ══════════════ BANNER DE USO MENSAL (somente plano FREE) ══════════════ --}}
    @if($monthlyOrderLimit !== null)
        @php
            $used       = $monthlyOrderCount ?? 0;
            $limit      = $monthlyOrderLimit;
            $remaining  = max(0, $limit - $used);
            $percentage = $limit > 0 ? min(100, round($used / $limit * 100)) : 0;
            $isNearLimit = $percentage >= 80;
            $isAtLimit   = $used >= $limit;
        @endphp
        <div class="rounded-xl border p-4 flex flex-col sm:flex-row sm:items-center gap-4
            {{ $isAtLimit ? 'bg-red-50 border-red-200 dark:bg-red-900/20 dark:border-red-700/50'
             : ($isNearLimit ? 'bg-amber-50 border-amber-200 dark:bg-amber-900/20 dark:border-amber-700/50'
             : 'bg-white border-neutral-200 dark:bg-zinc-800 dark:border-zinc-700') }}">

            <div class="flex-1 space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold {{ $isAtLimit ? 'text-red-700 dark:text-red-400' : ($isNearLimit ? 'text-amber-700 dark:text-amber-400' : 'text-neutral-700 dark:text-neutral-200') }}">
                        @if($isAtLimit)
                            🚫 Limite de pedidos atingido este mês
                        @elseif($isNearLimit)
                            ⚠️ Você está próximo do limite mensal
                        @else
                            📊 Pedidos este mês — Plano Grátis
                        @endif
                    </p>
                    <span class="text-sm font-bold {{ $isAtLimit ? 'text-red-700 dark:text-red-400' : 'text-neutral-700 dark:text-neutral-200' }}">
                        {{ $used }}/{{ $limit }}
                    </span>
                </div>

                <div class="h-2 w-full rounded-full bg-neutral-200 dark:bg-zinc-700 overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-500
                        {{ $isAtLimit ? 'bg-red-500' : ($isNearLimit ? 'bg-amber-500' : 'bg-[#7A00A3]') }}"
                        style="width: {{ $percentage }}%">
                    </div>
                </div>

                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                    @if($isAtLimit)
                        Novos pedidos estão bloqueados. Faça upgrade ou aguarde o próximo mês.
                    @else
                        {{ $remaining }} pedido{{ $remaining !== 1 ? 's' : '' }} restante{{ $remaining !== 1 ? 's' : '' }} este mês.
                    @endif
                </p>

                <p class="text-xs text-neutral-500 dark:text-neutral-400 border-t border-neutral-100 dark:border-zinc-700 pt-2 mt-1">
                    💡 <span class="font-medium">Taxa por volume:</span>
                    até 50 pedidos no mês → <span class="font-semibold {{ $used > 50 ? 'line-through text-neutral-400 dark:text-zinc-500' : 'text-[#7A00A3] dark:text-purple-400' }}">1%</span>
                    &nbsp;·&nbsp;
                    acima de 50 pedidos → <span class="font-semibold {{ $used > 50 ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-500 dark:text-neutral-400' }}">3%</span>
                    @if($used > 50)
                        <span class="ml-1 text-amber-600 dark:text-amber-400 font-medium">(taxa atual)</span>
                    @endif
                </p>
            </div>

            <a href="{{ route('admin.billing') }}"
               class="shrink-0 inline-flex items-center gap-1.5 text-xs font-bold text-white px-4 py-2 rounded-lg transition-colors whitespace-nowrap"
               style="background: #7A00A3;"
               onmouseover="this.style.background='#5c0079'" onmouseout="this.style.background='#7A00A3'">
                🚀 Fazer upgrade
            </a>
        </div>
    @endif

    {{-- ══════════════ CARD BOAS-VINDAS / EXPLICAÇÃO DO SISTEMA ══════════════ --}}
    <div class="rounded-2xl overflow-hidden shadow-sm border border-purple-100 dark:border-purple-900/30">
        {{-- Header com gradiente da empresa --}}
        <div class="px-6 py-5 text-white" style="background: linear-gradient(135deg, #5c0079 0%, #7A00A3 60%, #9B10C8 100%);">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-white/15 border border-white/25 flex items-center justify-center shrink-0 overflow-hidden">
                    @if(isset($currentCompany) && $currentCompany->logo_path)
                        <img src="{{ $currentCompany->logo_url }}" class="w-full h-full object-cover" alt="">
                    @else
                        <img src="{{ asset('logo_branca.png') }}" class="w-full h-full object-cover" alt="">
                    @endif
                </div>
                <div>
                    <h2 class="text-xl font-black leading-tight">
                        {{ isset($currentCompany) ? $currentCompany->name : config('app.name') }}
                    </h2>
                    @if(isset($currentCompany) && $currentCompany->tagline)
                        <p class="text-white/70 text-sm mt-0.5">{{ $currentCompany->tagline }}</p>
                    @endif
                    @if(isset($currentCompany) && $currentCompany->plan)
                        <span class="inline-flex items-center gap-1 text-xs font-bold px-2 py-0.5 rounded-full mt-1
                            {{ $currentCompany->isPro() ? 'bg-amber-400/30 text-amber-200' :
                               ($currentCompany->isEssencial() ? 'bg-purple-300/30 text-purple-100' :
                               'bg-white/20 text-white/80') }}">
                            {{ $currentCompany->isPro() ? '⭐' : ($currentCompany->isEssencial() ? '📦' : '🆓') }}
                            Plano {{ $currentCompany->plan->label() }}
                        </span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Corpo: explicação do sistema + atalhos --}}
        <div class="bg-white dark:bg-zinc-800 px-6 py-5">
            @php
                $hasAnyQuickAction = $canViewOrders || $canViewBranches || $canViewProducts || $canSettings;
            @endphp

            @if($hasAnyQuickAction)
                <p class="text-sm font-semibold text-neutral-700 dark:text-neutral-200 mb-4">O que você pode fazer neste painel:</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    @if($canViewOrders)
                        <a href="{{ route('admin.orders.index') }}" class="flex items-start gap-3 p-3 rounded-xl border border-gray-100 hover:border-purple-200 transition-all dark:border-zinc-700 dark:hover:border-purple-800/50 dark:hover:bg-purple-900/20">
                            <div class="w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center shrink-0 text-base">📋</div>
                            <div>
                                <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100 leading-tight">Pedidos</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">Acompanhe e gerencie pedidos em tempo real.</p>
                            </div>
                        </a>
                    @endif
                    @if($canViewBranches)
                        <a href="{{ route('admin.branches.index') }}" class="flex items-start gap-3 p-3 rounded-xl border border-gray-100 hover:border-purple-200 transition-all dark:border-zinc-700 dark:hover:border-purple-800/50 dark:hover:bg-purple-900/20 ">
                            <div class="w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center shrink-0 text-base">🏪</div>
                            <div>
                                <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100 leading-tight">Filiais</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">Cadastre filiais com horário de funcionamento.</p>
                            </div>
                        </a>
                    @endif
                    @if($canViewProducts)
                        <a href="{{ route('admin.products.index') }}" class="flex items-start gap-3 p-3 rounded-xl border border-gray-100 hover:border-purple-200 transition-all dark:border-zinc-700 dark:hover:border-purple-800/50 dark:hover:bg-purple-900/20">
                            <div class="w-8 h-8 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center shrink-0 text-base">🥟</div>
                            <div>
                                <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100 leading-tight">Produtos</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">Gerencie cardápio, preços e disponibilidade por filial.</p>
                            </div>
                        </a>
                    @endif
                    @if($canSettings)
                        <a href="{{ route('admin.settings') }}" class="flex items-start gap-3 p-3 rounded-xl border border-gray-100 hover:border-purple-200 transition-all dark:border-zinc-700 dark:hover:border-purple-800/50 dark:hover:bg-purple-900/20">
                            <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center shrink-0 text-base">⚙️</div>
                            <div>
                                <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100 leading-tight">Configurações</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">Personalize cores, logo, slogan e pagamento PIX.</p>
                            </div>
                        </a>
                    @endif
                </div>
            @endif

            {{-- Link direto para o chat --}}
            <div class="{{ $hasAnyQuickAction ? 'mt-4 pt-4 border-t border-gray-100 dark:border-zinc-700' : '' }} flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400">Link do chat de pedidos:</p>
                    <p class="text-sm font-mono font-semibold text-neutral-700 dark:text-neutral-200 mt-0.5">
                        {{ url('/') }}/{{ $currentCompany->slug ?? '' }}
                    </p>
                </div>
                <a
                    href="{{ url('/' . ($currentCompany->slug ?? '')) }}"
                    target="_blank"
                    class="shrink-0 inline-flex items-center gap-1.5 text-xs font-bold text-white px-4 py-2 rounded-lg transition-colors"
                    style="background: #7A00A3;"
                    onmouseover="this.style.background='#5c0079'" onmouseout="this.style.background='#7A00A3'"
                >
                    Abrir chat de pedidos
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
                </a>
            </div>
        </div>
    </div>

    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Dashboard</h1>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    @if(isset($currentCompany) && $currentCompany->plan && $canSettings)
        <a href="{{ route('admin.billing') }}"
           class="flex items-center justify-between bg-white border rounded-xl p-4 shadow-sm hover:border-purple-300 transition-colors dark:bg-zinc-800 dark:border-zinc-700 dark:hover:border-purple-700">
            <div>
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Plano atual</p>
                <p class="text-xl font-bold mt-1
                    {{ $currentCompany->isPro() ? 'text-amber-600 dark:text-amber-400' :
                       ($currentCompany->isEssencial() ? 'text-purple-700 dark:text-purple-400' :
                       'text-neutral-600 dark:text-neutral-300') }}">
                    {{ $currentCompany->plan->label() }}
                </p>
                @if(! $currentCompany->isPro())
                    <p class="text-xs text-purple-600 dark:text-purple-400 mt-0.5">Ver planos →</p>
                @endif
            </div>
            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-xl shrink-0
                {{ $currentCompany->isPro() ? 'bg-amber-100 dark:bg-amber-900/30' :
                   ($currentCompany->isEssencial() ? 'bg-purple-100 dark:bg-purple-900/30' :
                   'bg-neutral-100 dark:bg-zinc-700') }}">
                {{ $currentCompany->isPro() ? '⭐' : ($currentCompany->isEssencial() ? '📦' : '🆓') }}
            </div>
        </a>
    @endif

    @if($walletBalance !== null)
        <a href="{{ route('admin.wallet') }}"
           class="flex items-center justify-between bg-white border rounded-xl p-4 shadow-sm hover:border-purple-300 transition-colors dark:bg-zinc-800 dark:border-zinc-700 dark:hover:border-purple-700">
            <div>
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Saldo na carteira</p>
                <p class="text-2xl font-bold mt-1 {{ $walletBalance < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                    R$ {{ number_format($walletBalance, 2, ',', '.') }}
                </p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center text-xl shrink-0">
                💰
            </div>
        </a>
    @endif

    @if($canViewOrders)
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Pedidos hoje</p>
                <p class="text-3xl font-bold text-amber-600 mt-1 dark:text-amber-400">{{ $todayOrders->count() }}</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Receita hoje</p>
                <p class="text-2xl font-bold text-green-600 mt-1 dark:text-green-400">R$ {{ number_format($todayRevenue, 2, ',', '.') }}</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Em preparo</p>
                <p class="text-3xl font-bold text-blue-600 mt-1 dark:text-blue-400">{{ $pendingOrders }}</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Total pedidos</p>
                <p class="text-3xl font-bold text-neutral-800 mt-1 dark:text-neutral-100">{{ $totalOrders }}</p>
            </div>
        </div>

        <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
            <div class="px-4 py-3 border-b flex items-center justify-between dark:border-zinc-700">
                <h2 class="font-semibold text-neutral-700 dark:text-neutral-200">Pedidos de hoje</h2>
                <a href="{{ route('admin.orders.index') }}" class="text-xs text-amber-600 hover:underline dark:text-amber-400">Ver todos</a>
            </div>
            <div class="divide-y dark:divide-zinc-700">
                @forelse ($todayOrders->sortByDesc('created_at')->take(10) as $order)
                    <a href="{{ route('admin.orders.show', $order) }}"
                        class="flex items-center justify-between px-4 py-3 hover:bg-neutral-50 transition-colors dark:hover:bg-zinc-700/50">
                        <div>
                            <p class="font-mono text-sm font-semibold dark:text-neutral-100">{{ $order->order_number }}</p>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $order->customer->name ?? '—' }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($order->total, 2, ',', '.') }}</p>
                            <span class="text-xs px-2 py-0.5 rounded-full
                                @if($order->status === 'paid') bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400
                                @elseif($order->status === 'preparing') bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-400
                                @elseif($order->status === 'cancelled') bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-400
                                @else bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300 @endif">
                                {{ $order->status_label }}
                            </span>
                        </div>
                    </a>
                @empty
                    <p class="px-4 py-6 text-sm text-neutral-500 dark:text-neutral-400 text-center">Nenhum pedido hoje.</p>
                @endforelse
            </div>
        </div>
    @endif
</div>
