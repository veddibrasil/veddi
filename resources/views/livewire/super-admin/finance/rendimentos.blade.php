<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Rendimentos CDI</h1>
    </div>

    {{-- Cards de resumo --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white border rounded-xl shadow-sm p-5 dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase tracking-wide mb-1">Saldo Stark</p>
            <p class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">
                R$ {{ number_format($this->currentStarkBalance, 2, ',', '.') }}
            </p>
        </div>

        <div class="bg-white border rounded-xl shadow-sm p-5 dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase tracking-wide mb-1">CDI acumulado no mês</p>
            <p class="text-2xl font-bold text-green-600 dark:text-green-400">
                R$ {{ number_format($this->monthlyYield, 2, ',', '.') }}
            </p>
        </div>

        <div class="bg-white border rounded-xl shadow-sm p-5 dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase tracking-wide mb-1">Projeção anual</p>
            <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">
                R$ {{ number_format($this->annualProjection, 2, ',', '.') }}
            </p>
        </div>

        <div class="bg-white border rounded-xl shadow-sm p-5 dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase tracking-wide mb-1">Taxa CDI (fallback diário)</p>
            <p class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">
                {{ number_format(config('services.stark.cdi_rate_fallback_daily', 0) * 100, 6) }}%
            </p>
        </div>
    </div>

    {{-- Navegação de mês --}}
    <div class="flex items-center gap-4">
        <button wire:click="previousMonth"
            class="px-3 py-1.5 text-sm border rounded-lg hover:bg-neutral-50 dark:border-zinc-600 dark:hover:bg-zinc-700 dark:text-neutral-300 transition">
            ← Mês anterior
        </button>
        <span class="font-semibold text-neutral-700 dark:text-neutral-200">
            {{ \Carbon\Carbon::createFromDate($year, $month, 1)->translatedFormat('F Y') }}
        </span>
        <button wire:click="nextMonth"
            class="px-3 py-1.5 text-sm border rounded-lg hover:bg-neutral-50 dark:border-zinc-600 dark:hover:bg-zinc-700 dark:text-neutral-300 transition">
            Próximo mês →
        </button>
    </div>

    {{-- Tabela de snapshots --}}
    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3 text-left">Data</th>
                    <th class="px-4 py-3 text-right">Float total</th>
                    <th class="px-4 py-3 text-right">CDI diário</th>
                    <th class="px-4 py-3 text-right">Rendimento</th>
                    <th class="px-4 py-3 text-right">Acumulado mês</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($snapshots as $snap)
                    <tr class="hover:bg-neutral-50 transition dark:hover:bg-zinc-700/50">
                        <td class="px-4 py-3 text-neutral-700 dark:text-neutral-300">
                            {{ $snap->snapshot_date->format('d/m/Y') }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-neutral-700 dark:text-neutral-300">
                            R$ {{ number_format($snap->total_float, 2, ',', '.') }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-neutral-500 dark:text-neutral-400">
                            {{ number_format($snap->cdi_rate_daily * 100, 6) }}%
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-green-600 dark:text-green-400">
                            R$ {{ number_format($snap->yield_amount, 2, ',', '.') }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-amber-600 dark:text-amber-400">
                            R$ {{ number_format($snap->cumulative_yield_month, 2, ',', '.') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-neutral-400 dark:text-neutral-500">
                            Nenhum snapshot registrado para este mês.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
