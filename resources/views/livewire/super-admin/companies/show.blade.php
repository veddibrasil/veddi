<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            @if($company->logo_path)
                <img src="{{ $company->logo_url }}" class="w-10 h-10 rounded-full object-cover">
            @else
                <div class="w-10 h-10 rounded-full flex items-center justify-center text-white text-sm font-bold"
                     style="background: {{ $company->primary_color }}">
                    {{ mb_substr($company->name, 0, 1) }}
                </div>
            @endif
            <div>
                <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">{{ $company->name }}</h1>
                <p class="text-xs text-neutral-500 font-mono dark:text-neutral-400">{{ $company->slug }}</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('superadmin.companies.edit', $company) }}"
                class="inline-flex items-center px-4 py-2 bg-white border text-neutral-700 text-sm font-medium rounded-lg hover:bg-neutral-50 transition dark:bg-zinc-800 dark:border-zinc-700 dark:text-neutral-200 dark:hover:bg-zinc-700">
                Editar
            </a>
            <a href="{{ route('superadmin.companies.index') }}"
                class="inline-flex items-center px-4 py-2 bg-amber-500 text-white text-sm font-medium rounded-lg hover:bg-amber-600 transition">
                Voltar
            </a>
        </div>
    </div>

    {{-- Status geral --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Status</p>
            <p class="mt-1">
                <span class="px-2 py-0.5 rounded-full text-xs font-medium
                    {{ $company->status === 'ACTIVE' ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400'
                       : ($company->status === 'PENDING_PAYMENT' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-400'
                       : 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-400') }}">
                    {{ $company->status }}
                </span>
            </p>
            <p class="text-xs text-neutral-500 mt-1 dark:text-neutral-400">{{ $company->active ? 'Ativa' : 'Inativa' }}</p>
        </div>
        <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Plano</p>
            <p class="text-xl font-bold mt-1 text-neutral-800 dark:text-neutral-100">{{ $company->plan?->label() ?? '—' }}</p>
        </div>
        <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Filiais</p>
            <p class="text-2xl font-bold mt-1 text-neutral-800 dark:text-neutral-100">{{ $branchesCount }}</p>
        </div>
        <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Produtos</p>
            <p class="text-2xl font-bold mt-1 text-neutral-800 dark:text-neutral-100">{{ $productsCount }}</p>
        </div>
    </div>

    {{-- Pedidos --}}
    <div>
        <h2 class="text-lg font-semibold text-neutral-700 dark:text-neutral-200 mb-3">Pedidos</h2>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Total de pedidos</p>
                <p class="text-2xl font-bold mt-1 text-neutral-800 dark:text-neutral-100">{{ $totalOrders }}</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Pedidos este mês</p>
                <p class="text-2xl font-bold mt-1 text-amber-600 dark:text-amber-400">{{ $ordersThisMonth }}</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Ticket médio</p>
                <p class="text-2xl font-bold mt-1 text-neutral-800 dark:text-neutral-100">R$ {{ number_format($avgTicket, 2, ',', '.') }}</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Pedidos pagos</p>
                <p class="text-2xl font-bold mt-1 text-neutral-800 dark:text-neutral-100">{{ $paidOrdersCount }}</p>
            </div>
        </div>

        <div class="bg-white border rounded-xl shadow-sm mt-4 overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
            <div class="px-4 py-3 border-b dark:border-zinc-700">
                <h3 class="font-semibold text-sm text-neutral-700 dark:text-neutral-200">Pedidos por status</h3>
            </div>
            <div class="p-4 flex flex-wrap gap-3">
                @forelse($ordersByStatus as $status => $count)
                    <span class="px-3 py-1.5 rounded-lg bg-neutral-50 border text-xs font-medium text-neutral-700 dark:bg-zinc-700/50 dark:border-zinc-700 dark:text-neutral-200">
                        {{ $status }}: <span class="font-bold">{{ $count }}</span>
                    </span>
                @empty
                    <p class="text-sm text-neutral-400 dark:text-neutral-500">Nenhum pedido registrado.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Faturamento --}}
    <div>
        <h2 class="text-lg font-semibold text-neutral-700 dark:text-neutral-200 mb-3">Faturamento</h2>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Receita bruta total</p>
                <p class="text-xl font-bold mt-1 text-green-600 dark:text-green-400">R$ {{ number_format($totalRevenue, 2, ',', '.') }}</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Receita este mês</p>
                <p class="text-xl font-bold mt-1 text-green-600 dark:text-green-400">R$ {{ number_format($revenueThisMonth, 2, ',', '.') }}</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Taxa da plataforma</p>
                <p class="text-xl font-bold mt-1 text-neutral-800 dark:text-neutral-100">R$ {{ number_format($totalPlatformFee, 2, ',', '.') }}</p>
                <p class="text-[11px] text-neutral-400 mt-1 dark:text-neutral-500">Taxa do plano + margem PIX Vindi (VINDI_PIX_PLATFORM_RATE).</p>
                <div class="mt-2 pt-2 border-t border-neutral-100 dark:border-zinc-700 space-y-1">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-neutral-500 dark:text-neutral-400">Taxa do plano</span>
                        <span class="font-semibold text-neutral-700 dark:text-neutral-200">R$ {{ number_format($totalPlanFee, 2, ',', '.') }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-neutral-500 dark:text-neutral-400">Margem PIX (Vindi)</span>
                        <span class="font-semibold text-neutral-700 dark:text-neutral-200">R$ {{ number_format($totalPixPlatformFee, 2, ',', '.') }}</span>
                    </div>
                </div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Valor líquido à empresa</p>
                <p class="text-xl font-bold mt-1 text-neutral-800 dark:text-neutral-100">R$ {{ number_format($totalNetValue, 2, ',', '.') }}</p>
            </div>
        </div>

        @if($monthlyHistory->isNotEmpty())
            <div class="bg-white border rounded-xl shadow-sm mt-4 overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
                <div class="px-4 py-3 border-b dark:border-zinc-700">
                    <h3 class="font-semibold text-sm text-neutral-700 dark:text-neutral-200">Histórico (últimos 6 meses)</h3>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                        <tr>
                            <th class="px-4 py-2 text-left">Mês</th>
                            <th class="px-4 py-2 text-center">Pedidos</th>
                            <th class="px-4 py-2 text-right">Receita</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                        @foreach($monthlyHistory as $row)
                            <tr>
                                <td class="px-4 py-2 text-neutral-700 dark:text-neutral-300">{{ \Carbon\Carbon::createFromFormat('Y-m', $row->month)->translatedFormat('M/Y') }}</td>
                                <td class="px-4 py-2 text-center text-neutral-700 dark:text-neutral-300">{{ $row->orders_count }}</td>
                                <td class="px-4 py-2 text-right font-medium text-neutral-800 dark:text-neutral-100">R$ {{ number_format($row->revenue, 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Carteira / Assinatura --}}
    <div>
        <h2 class="text-lg font-semibold text-neutral-700 dark:text-neutral-200 mb-3">Carteira e assinatura</h2>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-2">
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Saldo disponível</p>
                <p class="text-xl font-bold mt-1 text-emerald-600 dark:text-emerald-400">R$ {{ number_format($balance['available_balance'], 2, ',', '.') }}</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Assinatura</p>
                @if($subscription)
                    <p class="text-sm font-bold mt-1 text-neutral-800 dark:text-neutral-100">{{ $subscription->status }}</p>
                    <p class="text-xs text-neutral-500 mt-0.5 dark:text-neutral-400">Próx. venc.: {{ $subscription->next_due_date?->format('d/m/Y') ?? '—' }}</p>
                @else
                    <p class="text-sm text-neutral-400 mt-1 dark:text-neutral-500">Sem assinatura</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Usuários --}}
    <div>
        <h2 class="text-lg font-semibold text-neutral-700 dark:text-neutral-200 mb-3">Usuários ({{ $users->count() }})</h2>
        <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                    <tr>
                        <th class="px-4 py-2 text-left">Nome</th>
                        <th class="px-4 py-2 text-left">E-mail</th>
                        <th class="px-4 py-2 text-left">Papel</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                    @forelse($users as $user)
                        <tr>
                            <td class="px-4 py-2 font-medium text-neutral-800 dark:text-neutral-100">{{ $user->name }}</td>
                            <td class="px-4 py-2 text-neutral-500 dark:text-neutral-400">{{ $user->email }}</td>
                            <td class="px-4 py-2 text-neutral-700 dark:text-neutral-300">{{ $user->pivot->role }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-6 text-center text-neutral-400 dark:text-neutral-500">Nenhum usuário vinculado.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
