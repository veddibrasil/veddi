<div class="w-full space-y-6">

    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Carteira</h1>

    @if(session('success'))
        <div class="bg-green-50 border border-green-200 rounded-lg px-4 py-3 text-sm text-green-800 dark:bg-green-900/20 dark:border-green-700/50 dark:text-green-300">
            {{ session('success') }}
        </div>
    @endif

    {{-- Saldo disponível --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300 mb-4">Saldo Disponível</h2>

        <div class="flex items-end justify-between">
            <div>
                <p class="text-4xl font-bold text-neutral-900 dark:text-white">
                    R$ {{ number_format($balance, 2, ',', '.') }}
                </p>
                <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                    Valor acumulado dos seus pedidos pagos
                </p>
            </div>

            <button
                wire:click="openWithdrawalModal"
                @if($balance < 10) disabled @endif
                class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white text-sm font-semibold rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Solicitar Saque
            </button>
        </div>

        @if($balance < 10 && $balance > 0)
            <p class="text-xs text-amber-600 dark:text-amber-400 mt-2">Valor mínimo para saque: R$ 10,00</p>
        @endif
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
                            'text-red-500 dark:text-red-400' => in_array($entry['type'], ['fee', 'withdrawal']),
                        ])>
                            {{ $entry['type'] === 'credit' ? '+' : '-' }}
                            R$ {{ number_format($entry['amount'], 2, ',', '.') }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

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
                        Valor do saque <span class="text-neutral-400">(saldo: R$ {{ number_format($balance, 2, ',', '.') }})</span>
                    </label>
                    <input type="number" step="0.01" min="10" max="{{ $balance }}"
                           wire:model="withdrawalAmount"
                           class="w-full border border-neutral-300 dark:border-zinc-600 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-transparent dark:bg-zinc-700 dark:text-white"
                           placeholder="0,00">
                    @error('withdrawalAmount') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Tipo de transferência</label>
                    <div class="flex gap-3">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="payoutType" value="PIX" class="accent-green-600">
                            <span class="text-sm text-neutral-700 dark:text-neutral-300">PIX</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model="payoutType" value="TED" class="accent-green-600">
                            <span class="text-sm text-neutral-700 dark:text-neutral-300">TED (conta bancária)</span>
                        </label>
                    </div>
                </div>

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

                {{-- TED fields --}}
                @if($payoutType === 'TED')
                    <div class="space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Código do banco</label>
                                <input type="text" wire:model="bankCode"
                                       class="w-full border border-neutral-300 dark:border-zinc-600 rounded-lg px-3 py-2 text-sm dark:bg-zinc-700 dark:text-white"
                                       placeholder="Ex: 341">
                                @error('bankCode') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Agência</label>
                                <input type="text" wire:model="bankAgency"
                                       class="w-full border border-neutral-300 dark:border-zinc-600 rounded-lg px-3 py-2 text-sm dark:bg-zinc-700 dark:text-white"
                                       placeholder="0001">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Conta</label>
                                <input type="text" wire:model="bankAccount"
                                       class="w-full border border-neutral-300 dark:border-zinc-600 rounded-lg px-3 py-2 text-sm dark:bg-zinc-700 dark:text-white"
                                       placeholder="12345">
                                @error('bankAccount') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Dígito</label>
                                <input type="text" wire:model="bankAccountDigit"
                                       class="w-full border border-neutral-300 dark:border-zinc-600 rounded-lg px-3 py-2 text-sm dark:bg-zinc-700 dark:text-white"
                                       placeholder="6">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Tipo de conta</label>
                            <select wire:model="bankAccountType"
                                    class="w-full border border-neutral-300 dark:border-zinc-600 rounded-lg px-3 py-2 text-sm dark:bg-zinc-700 dark:text-white">
                                <option value="CONTA_CORRENTE">Conta Corrente</option>
                                <option value="CONTA_POUPANCA">Conta Poupança</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">CPF/CNPJ do titular</label>
                            <input type="text" wire:model="bankOwnerCpfCnpj"
                                   class="w-full border border-neutral-300 dark:border-zinc-600 rounded-lg px-3 py-2 text-sm dark:bg-zinc-700 dark:text-white"
                                   placeholder="00.000.000/0001-00">
                            @error('bankOwnerCpfCnpj') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Nome do titular</label>
                            <input type="text" wire:model="bankOwnerName"
                                   class="w-full border border-neutral-300 dark:border-zinc-600 rounded-lg px-3 py-2 text-sm dark:bg-zinc-700 dark:text-white"
                                   placeholder="Nome completo ou razão social">
                            @error('bankOwnerName') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
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

</div>
