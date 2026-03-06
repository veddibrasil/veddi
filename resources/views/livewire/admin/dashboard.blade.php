<div class="space-y-6">

    {{-- ══════════════ CARD BOAS-VINDAS / EXPLICAÇÃO DO SISTEMA ══════════════ --}}
    <div class="rounded-2xl overflow-hidden shadow-sm border border-red-100 dark:border-red-900/30">
        {{-- Header com gradiente da empresa --}}
        <div class="px-6 py-5 text-white" style="background: linear-gradient(135deg, var(--mc-red-dark, #7F1D1D) 0%, var(--mc-red, #B91C1C) 60%, var(--mc-red-light, #C2410C) 100%);">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-white/15 border border-white/25 flex items-center justify-center shrink-0 overflow-hidden">
                    @if(isset($currentCompany) && $currentCompany->logo_path)
                        <img src="{{ asset('storage/' . $currentCompany->logo_path) }}" class="w-full h-full object-cover" alt="">
                    @else
                        <img src="{{ asset('logo.png') }}" class="w-full h-full object-cover" alt="">
                    @endif
                </div>
                <div>
                    <h2 class="text-xl font-black leading-tight">
                        {{ isset($currentCompany) ? $currentCompany->name : config('app.name') }}
                    </h2>
                    @if(isset($currentCompany) && $currentCompany->tagline)
                        <p class="text-white/70 text-sm mt-0.5">{{ $currentCompany->tagline }}</p>
                    @endif
                </div>
            </div>
        </div>

        {{-- Corpo: explicação do sistema + atalhos --}}
        <div class="bg-white dark:bg-zinc-800 px-6 py-5">
            <p class="text-sm font-semibold text-neutral-700 dark:text-neutral-200 mb-4">O que você pode fazer neste painel:</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <a href="{{ route('admin.orders.index') }}" class="flex items-start gap-3 p-3 rounded-xl border border-gray-100 hover:border-red-200 hover:bg-red-50 transition-all dark:border-zinc-700 dark:hover:border-red-800/50 dark:hover:bg-red-900/20">
                    <div class="w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center shrink-0 text-base">📋</div>
                    <div>
                        <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100 leading-tight">Pedidos</p>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">Acompanhe e gerencie pedidos em tempo real.</p>
                    </div>
                </a>
                <a href="{{ route('admin.branches.index') }}" class="flex items-start gap-3 p-3 rounded-xl border border-gray-100 hover:border-red-200 hover:bg-red-50 transition-all dark:border-zinc-700 dark:hover:border-red-800/50 dark:hover:bg-red-900/20">
                    <div class="w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center shrink-0 text-base">🏪</div>
                    <div>
                        <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100 leading-tight">Filiais</p>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">Cadastre filiais com horário de funcionamento.</p>
                    </div>
                </a>
                <a href="{{ route('admin.products.index') }}" class="flex items-start gap-3 p-3 rounded-xl border border-gray-100 hover:border-red-200 hover:bg-red-50 transition-all dark:border-zinc-700 dark:hover:border-red-800/50 dark:hover:bg-red-900/20">
                    <div class="w-8 h-8 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center shrink-0 text-base">🥟</div>
                    <div>
                        <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100 leading-tight">Produtos</p>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">Gerencie cardápio, preços e disponibilidade por filial.</p>
                    </div>
                </a>
                <a href="{{ route('admin.settings') }}" class="flex items-start gap-3 p-3 rounded-xl border border-gray-100 hover:border-red-200 hover:bg-red-50 transition-all dark:border-zinc-700 dark:hover:border-red-800/50 dark:hover:bg-red-900/20">
                    <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center shrink-0 text-base">⚙️</div>
                    <div>
                        <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100 leading-tight">Configurações</p>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">Personalize cores, logo, slogan e pagamento PIX.</p>
                    </div>
                </a>
            </div>

            {{-- Link direto para o chat --}}
            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-zinc-700 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
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
                    style="background: #B91C1C; hover: background: #7F1D1D;"
                    onmouseover="this.style.background='#7F1D1D'" onmouseout="this.style.background='#B91C1C'"
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
</div>
