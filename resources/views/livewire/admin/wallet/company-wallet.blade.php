<div class="w-full space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Carteira</h1>

        <a
            href="{{ config('payments.vindi_portal_url') }}"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white text-sm font-semibold rounded-lg hover:bg-green-700 transition"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
            </svg>
            Ver Saldo
        </a>
    </div>

    {{--
    <div class="bg-white border rounded-xl shadow-sm p-6 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Disponível para Saque</h2>

        <p class="text-3xl font-bold text-neutral-900 dark:text-white mt-3">
            R$ {{ number_format($availableBalance, 2, ',', '.') }}
        </p>
        <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">Já liberado e pronto para saque</p>

        <a
            href="{{ config('payments.vindi_portal_url') }}"
            target="_blank"
            rel="noopener noreferrer"
            class="mt-4 w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-green-600 text-white text-sm font-semibold rounded-lg hover:bg-green-700 transition"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
            </svg>
            Acessar Painel Yapay
        </a>
    </div>
    --}}

    {{--
    <div class="bg-white border rounded-xl shadow-sm p-6 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Total a Receber</h2>
        <p class="text-3xl font-bold text-amber-600 dark:text-amber-400 mt-3">
            R$ {{ number_format($pendingBalance, 2, ',', '.') }}
        </p>
        <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">Em carência, liberado em breve</p>
    </div>
    --}}

    {{-- Histórico de movimentações (créditos, saques e estornos) --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300 mb-4">Histórico de Movimentações</h2>

        @if($entries->isEmpty())
            <p class="text-sm text-neutral-400 dark:text-neutral-500 text-center py-8">Nenhuma movimentação ainda.</p>
        @else
            <div class="space-y-2">
                @foreach($entries as $entry)
                    <div class="flex items-center justify-between py-2 border-b border-neutral-100 dark:border-zinc-700 last:border-0">
                        <div>
                            <p class="text-sm text-neutral-800 dark:text-neutral-200">{{ $entry->description }}</p>
                            <p class="text-xs text-neutral-400 dark:text-neutral-500">
                                {{ $entry->created_at->format('d/m/Y H:i') }}
                            </p>
                        </div>
                        <span @class([
                            'text-sm font-semibold',
                            'text-green-600 dark:text-green-400' => $entry->type === 'credit',
                            'text-red-500 dark:text-red-400' => in_array($entry->type, ['withdrawal', 'refund']),
                        ])>
                            {{ $entry->type === 'credit' ? '+' : '-' }}
                            R$ {{ number_format(abs($entry->amount), 2, ',', '.') }}
                        </span>
                    </div>
                @endforeach
            </div>

            @if($entries->hasPages())
                <div class="mt-4">
                    {{ $entries->links() }}
                </div>
            @endif
        @endif
    </div>

    {{-- Broadcast: atualiza saldo quando WalletBalanceUpdated disparar --}}
    <div
        wire:ignore
        x-data
        x-init="
            if (window.Echo) {
                window.Echo.private('wallet.{{ $companyId }}')
                    .listen('WalletBalanceUpdated', () => {
                        $wire.refreshWallet();
                    });
            }
        "
    ></div>

</div>
