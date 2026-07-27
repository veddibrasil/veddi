<div class="space-y-6">
    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Dashboard</h1>

    {{-- Empresas --}}
    <div>
        <h2 class="text-lg font-semibold text-neutral-700 dark:text-neutral-200 mb-3">Empresas</h2>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Total</p>
                <p class="text-2xl font-bold mt-1 text-neutral-800 dark:text-neutral-100">{{ $totalCompanies }}</p>
                <p class="text-[11px] text-neutral-400 mt-1 dark:text-neutral-500">Total de empresas cadastradas na plataforma, em qualquer status.</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Ativas</p>
                <p class="text-2xl font-bold mt-1 text-green-600 dark:text-green-400">{{ $activeCompanies }}</p>
                <p class="text-[11px] text-neutral-400 mt-1 dark:text-neutral-500">Empresas com acesso liberado ao painel (campo "active" = verdadeiro).</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Aguard. pagamento</p>
                <p class="text-2xl font-bold mt-1 text-amber-600 dark:text-amber-400">{{ $pendingPaymentCompanies }}</p>
                <p class="text-[11px] text-neutral-400 mt-1 dark:text-neutral-500">Empresas com status PENDING_PAYMENT, aguardando confirmação de cobrança (Asaas).</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Bloqueadas</p>
                <p class="text-2xl font-bold mt-1 text-red-600 dark:text-red-400">{{ $blockedCompanies }}</p>
                <p class="text-[11px] text-neutral-400 mt-1 dark:text-neutral-500">Empresas com status BLOCKED, sem acesso ao painel administrativo.</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Novas este mês</p>
                <p class="text-2xl font-bold mt-1 text-neutral-800 dark:text-neutral-100">{{ $newCompaniesThisMonth }}</p>
                <p class="text-[11px] text-neutral-400 mt-1 dark:text-neutral-500">Empresas cadastradas no mês corrente (data de criação).</p>
            </div>
        </div>

        <div class="bg-white border rounded-xl shadow-sm mt-4 overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
            <div class="px-4 py-3 border-b dark:border-zinc-700">
                <h3 class="font-semibold text-sm text-neutral-700 dark:text-neutral-200">Empresas por plano</h3>
                <p class="text-[11px] text-neutral-400 mt-0.5 dark:text-neutral-500">Distribuição do total de empresas agrupadas pelo plano contratado (Grátis, Essencial, PRO).</p>
            </div>
            <div class="p-4 flex flex-wrap gap-3">
                @forelse($companiesByPlan as $plan => $count)
                    <span class="px-3 py-1.5 rounded-lg bg-neutral-50 border text-xs font-medium text-neutral-700 dark:bg-zinc-700/50 dark:border-zinc-700 dark:text-neutral-200">
                        {{ \App\Enums\Plan::tryFrom($plan)?->label() ?? $plan }}: <span class="font-bold">{{ $count }}</span>
                    </span>
                @empty
                    <p class="text-sm text-neutral-400 dark:text-neutral-500">Nenhuma empresa cadastrada.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Financeiro --}}
    <div>
        <h2 class="text-lg font-semibold text-neutral-700 dark:text-neutral-200 mb-3">Financeiro</h2>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">MRR estimado</p>
                <p class="text-xl font-bold mt-1 text-emerald-600 dark:text-emerald-400">R$ {{ number_format($mrr, 2, ',', '.') }}</p>
                <p class="text-[11px] text-neutral-400 mt-1 dark:text-neutral-500">Receita recorrente mensal somando todos os módulos abaixo, das empresas ativas.</p>
                <div class="mt-2 pt-2 border-t border-neutral-100 dark:border-zinc-700 space-y-1">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-neutral-500 dark:text-neutral-400">Planos (Essencial/PRO)</span>
                        <span class="font-semibold text-neutral-700 dark:text-neutral-200">R$ {{ number_format($mrrPlans, 2, ',', '.') }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-neutral-500 dark:text-neutral-400">Módulo Fiscal (NF-e)</span>
                        <span class="font-semibold text-neutral-700 dark:text-neutral-200">R$ {{ number_format($mrrFiscalAddon, 2, ',', '.') }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-neutral-500 dark:text-neutral-400">Módulo PDV</span>
                        <span class="font-semibold text-neutral-700 dark:text-neutral-200">R$ {{ number_format($mrrPdvAddon, 2, ',', '.') }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-neutral-500 dark:text-neutral-400">Módulo Garçom</span>
                        <span class="font-semibold text-neutral-700 dark:text-neutral-200">R$ {{ number_format($mrrWaiterAddon, 2, ',', '.') }}</span>
                    </div>
                </div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Taxa plataforma (mês)</p>
                <p class="text-xl font-bold mt-1 text-neutral-800 dark:text-neutral-100">R$ {{ number_format($platformFeeThisMonth, 2, ',', '.') }}</p>
                <p class="text-[11px] text-neutral-400 mt-1 dark:text-neutral-500">Taxa do plano (campo "fee") + margem PIX Vindi (VINDI_PIX_PLATFORM_RATE) nos pedidos pagos/entregues no mês corrente.</p>
                <div class="mt-2 pt-2 border-t border-neutral-100 dark:border-zinc-700 space-y-1">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-neutral-500 dark:text-neutral-400">Taxa do plano</span>
                        <span class="font-semibold text-neutral-700 dark:text-neutral-200">R$ {{ number_format($planFeeThisMonth, 2, ',', '.') }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-neutral-500 dark:text-neutral-400">Margem PIX (Vindi)</span>
                        <span class="font-semibold text-neutral-700 dark:text-neutral-200">R$ {{ number_format($pixPlatformFeeThisMonth, 2, ',', '.') }}</span>
                    </div>
                </div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Taxa plataforma (total)</p>
                <p class="text-xl font-bold mt-1 text-neutral-800 dark:text-neutral-100">R$ {{ number_format($totalPlatformFee, 2, ',', '.') }}</p>
                <p class="text-[11px] text-neutral-400 mt-1 dark:text-neutral-500">Taxa do plano + margem PIX Vindi, soma histórica desde sempre.</p>
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
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">GMV total</p>
                <p class="text-xl font-bold mt-1 text-neutral-800 dark:text-neutral-100">R$ {{ number_format($totalRevenue, 2, ',', '.') }}</p>
                <p class="text-[11px] text-neutral-400 mt-1 dark:text-neutral-500">Volume bruto transacionado: soma do total de todos os pedidos pagos/entregues já realizados na plataforma.</p>
            </div>
        </div>
    </div>

    {{-- Pedidos --}}
    <div>
        <h2 class="text-lg font-semibold text-neutral-700 dark:text-neutral-200 mb-3">Pedidos</h2>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Hoje</p>
                <p class="text-2xl font-bold mt-1 text-amber-600 dark:text-amber-400">{{ $ordersToday }}</p>
                <p class="text-[11px] text-neutral-400 mt-1 dark:text-neutral-500">Pedidos criados hoje, em qualquer status.</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Este mês</p>
                <p class="text-2xl font-bold mt-1 text-neutral-800 dark:text-neutral-100">{{ $ordersThisMonth }}</p>
                <p class="text-[11px] text-neutral-400 mt-1 dark:text-neutral-500">Pedidos criados no mês corrente, em qualquer status.</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Total histórico</p>
                <p class="text-2xl font-bold mt-1 text-neutral-800 dark:text-neutral-100">{{ $totalOrders }}</p>
                <p class="text-[11px] text-neutral-400 mt-1 dark:text-neutral-500">Total de pedidos já criados na plataforma desde sempre, em qualquer status.</p>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Receita este mês</p>
                <p class="text-xl font-bold mt-1 text-green-600 dark:text-green-400">R$ {{ number_format($revenueThisMonth, 2, ',', '.') }}</p>
                <p class="text-[11px] text-neutral-400 mt-1 dark:text-neutral-500">Soma do valor total dos pedidos pagos/entregues criados no mês corrente.</p>
            </div>
        </div>
    </div>

    {{-- Top empresas --}}
    <div>
        <h2 class="text-lg font-semibold text-neutral-700 dark:text-neutral-200">Top 5 empresas — receita do mês</h2>
        <p class="text-[11px] text-neutral-400 mb-3 dark:text-neutral-500">Ranking das 5 empresas com maior soma de pedidos pagos/entregues no mês corrente.</p>
        <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                    <tr>
                        <th class="px-4 py-2 text-left">Empresa</th>
                        <th class="px-4 py-2 text-left">Plano</th>
                        <th class="px-4 py-2 text-right">Receita (mês)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                    @forelse($topCompanies as $company)
                        <tr class="hover:bg-neutral-50 transition dark:hover:bg-zinc-700/50">
                            <td class="px-4 py-2 font-medium text-neutral-800 dark:text-neutral-100">
                                <a href="{{ route('superadmin.companies.show', $company) }}" class="hover:text-amber-600 dark:hover:text-amber-400">
                                    {{ $company->name }}
                                </a>
                            </td>
                            <td class="px-4 py-2 text-neutral-500 dark:text-neutral-400">{{ $company->plan?->label() ?? '—' }}</td>
                            <td class="px-4 py-2 text-right font-medium text-neutral-800 dark:text-neutral-100">R$ {{ number_format($company->revenue_this_month, 2, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-6 text-center text-neutral-400 dark:text-neutral-500">Nenhum pedido pago este mês.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
