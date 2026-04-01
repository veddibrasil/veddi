<div class="w-full space-y-6">

    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Carteira</h1>

    @if(session('success'))
        <div class="bg-green-50 border border-green-200 rounded-lg px-4 py-3 text-sm text-green-800 dark:bg-green-900/20 dark:border-green-700/50 dark:text-green-300">
            {{ session('success') }}
        </div>
    @endif

    {{-- Saldos: disponível e a receber --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

        {{-- Disponível para saque --}}
        <div class="bg-white border rounded-xl shadow-sm p-6 dark:bg-zinc-800 dark:border-zinc-700">
            <div class="flex items-center gap-2 mb-1">
                <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Disponível para Saque</h2>
                <div class="group relative">
                    <svg class="w-4 h-4 text-neutral-400 cursor-help" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div class="absolute left-0 bottom-6 z-10 hidden group-hover:block w-64 bg-neutral-900 text-white text-xs rounded-lg p-3 shadow-lg">
                        Pagamentos via PIX ficam disponíveis <strong>1 dia após</strong> a confirmação do pagamento. Cartão de crédito em 15 dias.
                    </div>
                </div>
            </div>

            <p class="text-3xl font-bold text-neutral-900 dark:text-white mt-3">
                R$ {{ number_format($availableBalance, 2, ',', '.') }}
            </p>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">Já liberado e pronto para saque</p>

            <button
                wire:click="openWithdrawalModal"
                @if($availableBalance < 10) disabled @endif
                class="mt-4 w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-green-600 text-white text-sm font-semibold rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Solicitar Saque
            </button>

            @if($availableBalance < 10 && $availableBalance > 0)
                <p class="text-xs text-amber-600 dark:text-amber-400 mt-2">Valor mínimo para saque: R$ 10,00</p>
            @endif
        </div>

        {{-- Total a receber (em carência) --}}
        <div class="bg-white border rounded-xl shadow-sm p-6 dark:bg-zinc-800 dark:border-zinc-700">
            <div class="flex items-center gap-2 mb-1">
                <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Total a Receber</h2>
                <div class="group relative">
                    <svg class="w-4 h-4 text-neutral-400 cursor-help" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div class="absolute left-0 bottom-6 z-10 hidden group-hover:block w-64 bg-neutral-900 text-white text-xs rounded-lg p-3 shadow-lg">
                        Valores confirmados mas ainda em carência. PIX fica disponível em <strong>1 dia</strong>; cartão de crédito em <strong>15 dias</strong>.
                    </div>
                </div>
            </div>

            <p class="text-3xl font-bold text-amber-600 dark:text-amber-400 mt-3">
                R$ {{ number_format($pendingBalance, 2, ',', '.') }}
            </p>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">Em carência, liberado em breve</p>

            <button
                wire:click="openAnticipationModal"
                wire:loading.attr="disabled"
                wire:target="openAnticipationModal"
                @if($pendingBalance <= 0) disabled @endif
                class="mt-4 w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-amber-500 text-white text-sm font-semibold rounded-lg hover:bg-amber-600 disabled:opacity-50 disabled:cursor-not-allowed transition"
            >
                <span wire:loading.remove wire:target="openAnticipationModal">
                    <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    Antecipar Recebíveis
                </span>
                <span wire:loading wire:target="openAnticipationModal">Carregando...</span>
            </button>
        </div>

    </div>

    {{-- Últimos saques --}}
    @if(!empty($withdrawals))
        <div class="bg-white border rounded-xl shadow-sm p-6 dark:bg-zinc-800 dark:border-zinc-700">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300 mb-4">Saques Recentes</h2>

            <div class="space-y-3">
                @foreach($withdrawals as $w)
                    <div class="flex items-center justify-between py-2 border-b border-neutral-100 dark:border-zinc-700 last:border-0">
                        <div>
                            <p class="text-sm font-medium text-neutral-800 dark:text-neutral-200">
                                R$ {{ number_format($w['amount'], 2, ',', '.') }}
                                <span class="text-neutral-400 dark:text-neutral-500 font-normal">({{ $w['payout_type'] }})</span>
                                @if($w['payout_type'] === 'PIX' && ($w['pix_fee'] ?? 0) > 0)
                                    <span class="text-xs text-neutral-400 dark:text-neutral-500">— taxa R$ {{ number_format($w['pix_fee'], 2, ',', '.') }}</span>
                                @endif
                            </p>
                            <p class="text-xs text-neutral-400 dark:text-neutral-500">
                                {{ \Carbon\Carbon::parse($w['created_at'])->format('d/m/Y H:i') }}
                            </p>
                        </div>
                        <span @class([
                            'inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold',
                            'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300' => $w['status'] === 'pending',
                            'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' => $w['status'] === 'processing',
                            'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' => $w['status'] === 'done',
                            'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' => $w['status'] === 'failed',
                        ])>
                            {{ match($w['status']) {
                                'pending'    => 'Pendente',
                                'processing' => 'Processando',
                                'done'       => 'Concluído',
                                'failed'     => 'Falhou',
                                default      => $w['status'],
                            } }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Histórico de movimentações --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300 mb-4">Histórico de Movimentações</h2>

        @if(empty($entries))
            <p class="text-sm text-neutral-400 dark:text-neutral-500 text-center py-8">Nenhuma movimentação ainda.</p>
        @else
            <div class="space-y-2">
                @foreach($entries as $entry)
                    <div class="flex items-center justify-between py-2 border-b border-neutral-100 dark:border-zinc-700 last:border-0">
                        <div>
                            <p class="text-sm text-neutral-800 dark:text-neutral-200">{{ $entry['description'] }}</p>
                            <p class="text-xs text-neutral-400 dark:text-neutral-500">
                                {{ \Carbon\Carbon::parse($entry['created_at'])->format('d/m/Y H:i') }}
                            </p>
                        </div>
                        <span @class([
                            'text-sm font-semibold',
                            'text-green-600 dark:text-green-400' => $entry['type'] === 'credit',
                            'text-red-500 dark:text-red-400' => in_array($entry['type'], ['fee', 'withdrawal', 'anticipation_fee', 'refund']),
                        ])>
                            {{ $entry['type'] === 'credit' ? '+' : '-' }}
                            R$ {{ number_format(abs($entry['amount']), 2, ',', '.') }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Modal de antecipação --}}
    @if($showAnticipationModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="bg-white dark:bg-zinc-800 rounded-xl shadow-xl w-full max-w-2xl mx-4 flex flex-col max-h-[90vh]">

                {{-- Header --}}
                <div class="flex items-center justify-between p-6 border-b border-neutral-100 dark:border-zinc-700 shrink-0">
                    <div>
                        <h3 class="text-lg font-bold text-neutral-800 dark:text-white">Antecipar Recebíveis</h3>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">Selecione as transações que deseja antecipar</p>
                    </div>
                    <button wire:click="closeAnticipationModal" class="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 ml-4">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                @if(!empty($eligibleTransactions))
                    {{-- Tabela de taxas por faixa --}}
                    @if(!empty($anticipationRates))
                        <div class="px-6 pt-4 pb-4">
                            <div class="grid grid-cols-4 gap-2 text-center text-xs">
                                @foreach([
                                    ['label' => 'Até 2 dias', 'key' => 'd2',  'color' => 'red'],
                                    ['label' => 'Até 7 dias', 'key' => 'd7',  'color' => 'orange'],
                                    ['label' => 'Até 15 dias','key' => 'd15', 'color' => 'amber'],
                                    ['label' => '+15 dias',   'key' => 'd30', 'color' => 'green'],
                                ] as $faixa)
                                    <div class="rounded-lg border border-neutral-200 dark:border-zinc-600 p-2">
                                        <p class="text-neutral-500 dark:text-neutral-400">
                                            {{ $faixa['label'] }}
                                        </p>

                                        <p class="font-bold text-sm mt-0.5
                                            {{ $faixa['color'] === 'red'    ? 'text-red-600 dark:text-red-400' : '' }}
                                            {{ $faixa['color'] === 'orange' ? 'text-orange-600 dark:text-orange-400' : '' }}
                                            {{ $faixa['color'] === 'amber'  ? 'text-amber-600 dark:text-amber-400' : '' }}
                                            {{ $faixa['color'] === 'green'  ? 'text-green-600 dark:text-green-400' : '' }}
                                        ">
                                            {{ $anticipationRates[$faixa['key']] }}%
                                        </p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Lista de transações --}}
                    <div class="overflow-y-auto flex-1">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-neutral-50 dark:bg-zinc-700/80 backdrop-blur">
                                <tr>
                                    <th class="px-4 py-3 text-left w-10">
                                        <input type="checkbox"
                                            wire:click="toggleAll"
                                            @checked(count($selectedTransactionIds) === count($eligibleTransactions))
                                            class="rounded accent-amber-500 cursor-pointer">
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wide">Transação</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wide">A receber</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wide">Liberação</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wide">Taxa</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wide">Líquido</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                                @foreach($eligibleTransactions as $tx)
                                    <tr @class([
                                        'transition-colors',
                                        'bg-amber-50 dark:bg-amber-900/10' => in_array((string)$tx['id'], $selectedTransactionIds),
                                        'hover:bg-neutral-50 dark:hover:bg-zinc-700/50' => !in_array((string)$tx['id'], $selectedTransactionIds),
                                    ])>
                                        <td class="px-4 py-3">
                                            <input type="checkbox"
                                                wire:model.live="selectedTransactionIds"
                                                value="{{ $tx['id'] }}"
                                                class="rounded accent-amber-500 cursor-pointer">
                                        </td>
                                        <td class="px-4 py-3">
                                            <p class="font-medium text-neutral-800 dark:text-neutral-200 truncate max-w-40">{{ $tx['description'] }}</p>
                                            <span @class([
                                                'inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium mt-0.5',
                                                'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' => $tx['type'] === 'cartao',
                                                'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' => $tx['type'] === 'pix',
                                                'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300' => $tx['type'] === 'boleto',
                                            ])>
                                                {{ match($tx['type']) { 'cartao' => 'Cartão', 'pix' => 'PIX', default => 'Boleto' } }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right font-medium text-neutral-800 dark:text-neutral-200 whitespace-nowrap">
                                            R$ {{ number_format($tx['net_value'], 2, ',', '.') }}
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <span class="text-neutral-700 dark:text-neutral-300">{{ \Carbon\Carbon::parse($tx['release_date'])->format('d/m/Y') }}</span>
                                            <span class="block text-xs text-neutral-400 dark:text-neutral-500">{{ $tx['days_remaining'] }}d restantes</span>
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <span class="text-red-600 dark:text-red-400 font-medium">{{ $tx['rate_pct'] }}%</span>
                                            <span class="block text-xs text-neutral-400 dark:text-neutral-500">− R$ {{ number_format($tx['fee'], 2, ',', '.') }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold text-green-600 dark:text-green-400 whitespace-nowrap">
                                            R$ {{ number_format($tx['net_after_fee'], 2, ',', '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Resumo reativo --}}
                    @php $summary = $this->anticipationSummary; @endphp
                    <div class="p-6 border-t border-neutral-100 dark:border-zinc-700 bg-neutral-50 dark:bg-zinc-700/50 rounded-b-xl shrink-0 space-y-4">
                        @if($summary['has_eligible'])
                            <div class="grid grid-cols-3 gap-4 text-sm">
                                <div>
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $summary['transactions_count'] }} selecionada(s)</p>
                                    <p class="font-semibold text-neutral-800 dark:text-white mt-0.5">R$ {{ number_format($summary['gross_amount'], 2, ',', '.') }}</p>
                                    <p class="text-xs text-neutral-400">valor bruto</p>
                                </div>
                                <div>
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400">Taxa total</p>
                                    <p class="font-semibold text-red-600 dark:text-red-400 mt-0.5">− R$ {{ number_format($summary['fee_amount'], 2, ',', '.') }}</p>
                                    <p class="text-xs text-neutral-400">antecipação</p>
                                </div>
                                <div>
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400">Você recebe</p>
                                    <p class="text-lg font-bold text-green-600 dark:text-green-400 mt-0.5">R$ {{ number_format($summary['net_amount'], 2, ',', '.') }}</p>
                                    <p class="text-xs text-neutral-400">disponível agora</p>
                                </div>
                            </div>
                        @else
                            <p class="text-sm text-neutral-500 dark:text-neutral-400 text-center">Selecione ao menos uma transação.</p>
                        @endif

                        <div class="flex gap-3">
                            <button wire:click="closeAnticipationModal"
                                    class="flex-1 px-4 py-2 border border-neutral-300 dark:border-zinc-600 text-neutral-700 dark:text-neutral-300 text-sm font-medium rounded-lg hover:bg-neutral-100 dark:hover:bg-zinc-700 transition">
                                Cancelar
                            </button>
                            <button wire:click="confirmAnticipation"
                                    wire:loading.attr="disabled"
                                    @if(!$summary['has_eligible']) disabled @endif
                                    class="flex-1 px-4 py-2 bg-amber-500 text-white text-sm font-semibold rounded-lg hover:bg-amber-600 disabled:opacity-50 disabled:cursor-not-allowed transition">
                                <span wire:loading.remove wire:target="confirmAnticipation">Confirmar Antecipação</span>
                                <span wire:loading wire:target="confirmAnticipation">Processando...</span>
                            </button>
                        </div>
                    </div>

                @else
                    <div class="p-8 text-center text-sm text-neutral-500 dark:text-neutral-400">
                        Nenhuma transação elegível para antecipação no momento.
                    </div>
                    <div class="p-4 border-t border-neutral-100 dark:border-zinc-700">
                        <button wire:click="closeAnticipationModal"
                                class="w-full px-4 py-2 border border-neutral-300 dark:border-zinc-600 text-neutral-700 dark:text-neutral-300 text-sm font-medium rounded-lg hover:bg-neutral-50 dark:hover:bg-zinc-700 transition">
                            Fechar
                        </button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Modal de saque --}}
    @if($showWithdrawalModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white dark:bg-zinc-800 rounded-xl shadow-xl w-full max-w-md mx-4 p-6 space-y-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-neutral-800 dark:text-white">Solicitar Saque</h3>
                    <button wire:click="closeWithdrawalModal" class="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div>
                    <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                        Valor do saque <span class="text-neutral-400">(disponível: R$ {{ number_format($availableBalance, 2, ',', '.') }})</span>
                    </label>
                    <input type="number" step="0.01" min="10" max="{{ $availableBalance }}"
                           wire:model.live="withdrawalAmount"
                           class="w-full border border-neutral-300 dark:border-zinc-600 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-transparent dark:bg-zinc-700 dark:text-white"
                           placeholder="0,00">
                    @error('withdrawalAmount') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Tipo de transferência</label>
                    <div class="flex gap-3">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model.live="payoutType" value="PIX" class="accent-green-600">
                            <span class="text-sm text-neutral-700 dark:text-neutral-300">PIX</span>
                        </label>
                    </div>
                </div>

                {{-- Aviso taxa PIX --}}
                @if($payoutType === 'PIX' && $withdrawalAmount > $pixWithdrawalFee)
                    <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 rounded-lg px-4 py-3 text-sm text-amber-800 dark:text-amber-300">
                        <div class="flex items-start gap-2">
                            <svg class="w-4 h-4 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <div>
                                Uma taxa de <strong>R$ {{ number_format($pixWithdrawalFee, 2, ',', '.') }}</strong> será descontada do saque via PIX.<br>
                                Você receberá: <strong>R$ {{ number_format(max(0, $withdrawalAmount - $pixWithdrawalFee), 2, ',', '.') }}</strong>
                            </div>
                        </div>
                    </div>
                @elseif($payoutType === 'PIX')
                    <div class="bg-neutral-50 dark:bg-zinc-700/50 border border-neutral-200 dark:border-zinc-600 rounded-lg px-4 py-3 text-xs text-neutral-500 dark:text-neutral-400 flex items-center gap-2">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Saques via PIX têm uma taxa de R$ {{ number_format($pixWithdrawalFee, 2, ',', '.') }}.
                    </div>
                @endif

                {{-- PIX fields --}}
                @if($payoutType === 'PIX')
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Tipo da chave PIX</label>
                            <select wire:model="pixKeyType"
                                    class="w-full border border-neutral-300 dark:border-zinc-600 rounded-lg px-3 py-2 text-sm dark:bg-zinc-700 dark:text-white">
                                <option value="EVP">Chave aleatória</option>
                                <option value="CPF">CPF</option>
                                <option value="CNPJ">CNPJ</option>
                                <option value="EMAIL">E-mail</option>
                                <option value="PHONE">Telefone</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Chave PIX</label>
                            <input type="text" wire:model="pixKey"
                                   class="w-full border border-neutral-300 dark:border-zinc-600 rounded-lg px-3 py-2 text-sm dark:bg-zinc-700 dark:text-white"
                                   placeholder="Informe a chave PIX">
                            @error('pixKey') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif

                <div class="flex gap-3 pt-2">
                    <button wire:click="closeWithdrawalModal"
                            class="flex-1 px-4 py-2 border border-neutral-300 dark:border-zinc-600 text-neutral-700 dark:text-neutral-300 text-sm font-medium rounded-lg hover:bg-neutral-50 dark:hover:bg-zinc-700 transition">
                        Cancelar
                    </button>
                    <button wire:click="requestWithdrawal" wire:loading.attr="disabled"
                            class="flex-1 px-4 py-2 bg-green-600 text-white text-sm font-semibold rounded-lg hover:bg-green-700 disabled:opacity-50 transition">
                        <span wire:loading.remove wire:target="requestWithdrawal">Confirmar Saque</span>
                        <span wire:loading wire:target="requestWithdrawal">Processando...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Broadcast: atualiza saldo automaticamente quando o job de balanço rodar --}}
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
