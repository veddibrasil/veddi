<div class="w-full space-y-6">
    <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Controle de notas fiscais</h1>
        <p class="text-sm text-neutral-500 dark:text-neutral-400">
            Pedidos pagos e a situação fiscal de cada um. A nota só é emitida quando o caixa marca "Emitir e imprimir nota fiscal" no PDV ou quando você emite por aqui.
        </p>
    </div>

    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    @if(session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm dark:bg-red-900/30 dark:border-red-700 dark:text-red-400">
            {{ session('error') }}
        </div>
    @endif

    {{-- Progresso do lote --}}
    @if($batch)
        <div @unless($batch->finished()) wire:poll.3s @endunless
            class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm dark:border-blue-800 dark:bg-blue-900/20">
            @if($batch->finished())
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-blue-800 dark:text-blue-200">
                        Lote concluído: {{ \App\Services\Fiscal\FiscalNoteService::describeBatchOutcome($batchOutcome ?? []) }}.
                    </p>
                    <div class="flex gap-2">
                        @if(($batchOutcome['rejected'] ?? 0) + ($batchOutcome['not_sent'] ?? 0) > 0)
                            <flux:button wire:click="$set('situationFilter', 'failed')" size="sm">Ver com erro</flux:button>
                        @endif
                        <flux:button wire:click="dismissBatch" size="sm" variant="ghost">Fechar</flux:button>
                    </div>
                </div>
            @else
                <p class="mb-2 text-blue-800 dark:text-blue-200">
                    Emitindo notas em lote: {{ $batch->processedJobs() }} de {{ $batch->totalJobs }}…
                </p>
                <div class="h-2 w-full overflow-hidden rounded-full bg-blue-100 dark:bg-blue-950">
                    <div class="h-2 rounded-full bg-blue-600 transition-all" style="width: {{ $batch->progress() }}%"></div>
                </div>
            @endif
        </div>
    @endif

    {{-- Filtros --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <flux:input type="date" wire:model.live="dateFrom" label="De" />
        <flux:input type="date" wire:model.live="dateTo" label="Até" />
        <flux:select wire:model.live="situationFilter" label="Situação fiscal">
            <option value="to_issue">A emitir (sem nota ou com erro)</option>
            <option value="none">Sem nota</option>
            <option value="failed">Com erro / rejeitada</option>
            <option value="pending">Em processamento</option>
            <option value="authorized">Autorizada</option>
            <option value="cancelled">Cancelada</option>
            <option value="all">Todas</option>
        </flux:select>
        <flux:select wire:model.live="channelFilter" label="Canal">
            <option value="">Todos os canais</option>
            <option value="balcao">PDV balcão</option>
            <option value="comanda">Comanda / mesa</option>
            <option value="chat">Chat</option>
            <option value="ifood">iFood</option>
            <option value="99food">99Food</option>
        </flux:select>
        <flux:select wire:model.live="paymentFilter" label="Pagamento">
            <option value="">Todas as formas</option>
            <option value="pix">PIX</option>
            <option value="card">Cartão</option>
            <option value="cash">Dinheiro</option>
            <option value="split">Dividido</option>
        </flux:select>
        @if($branches->count() > 1)
            <flux:select wire:model.live="branchFilter" label="Filial">
                <option value="">Todas as filiais</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </flux:select>
        @endif
        <flux:input wire:model.live.debounce.300ms="search" label="Buscar" placeholder="Pedido, cliente ou CPF/CNPJ" class="lg:col-span-2" />
    </div>

    {{-- Barra de seleção --}}
    @if($canIssue)
        <div class="flex flex-wrap items-center gap-2 rounded-xl border bg-white px-4 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-800">
            <span class="font-medium text-neutral-700 dark:text-neutral-200">{{ count($selected) }} selecionado(s)</span>
            <span class="text-xs text-neutral-400">(máx. {{ \App\Livewire\Admin\Fiscal\IssueControl::MAX_BATCH }} por lote)</span>
            <div class="ml-auto flex flex-wrap gap-2">
                <flux:button wire:click="selectPage" size="sm" variant="ghost">Selecionar página</flux:button>
                <flux:button wire:click="selectAllFiltered" size="sm" variant="ghost">Selecionar todos do filtro</flux:button>
                @if(count($selected) > 0)
                    <flux:button wire:click="clearSelection" size="sm" variant="ghost">Limpar</flux:button>
                @endif
                <flux:button wire:click="prepareIssue" wire:loading.attr="disabled" wire:target="prepareIssue" size="sm" variant="primary" :disabled="count($selected) === 0 || ($batch && ! $batch->finished())">
                    Emitir selecionadas
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Tabela --}}
    <div class="overflow-x-auto rounded-xl border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <table class="min-w-full divide-y divide-neutral-200 dark:divide-zinc-700">
            <thead class="bg-neutral-50 dark:bg-zinc-900">
                <tr>
                    @if($canIssue)<th class="w-10 px-2 py-3 sm:px-4"></th>@endif
                    <th class="px-2 py-3 sm:px-4 text-left text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Pedido</th>
                    <th class="hidden px-2 py-3 sm:px-4 text-left text-xs font-medium uppercase tracking-wide text-neutral-500 lg:table-cell dark:text-neutral-400">Canal</th>
                    <th class="hidden px-2 py-3 sm:px-4 text-left text-xs font-medium uppercase tracking-wide text-neutral-500 md:table-cell dark:text-neutral-400">Cliente</th>
                    <th class="hidden px-2 py-3 sm:px-4 text-right text-xs font-medium uppercase tracking-wide text-neutral-500 sm:table-cell dark:text-neutral-400">Total</th>
                    <th class="px-2 py-3 sm:px-4 text-left text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Situação</th>
                    <th class="hidden px-2 py-3 sm:px-4 text-right text-xs font-medium uppercase tracking-wide text-neutral-500 sm:table-cell dark:text-neutral-400">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($orders as $order)
                    @php
                        $note = $order->latestFiscalNote;
                        $situation = $note?->status ?? ($order->fiscal_error ? 'error' : 'none');
                        $situationColors = [
                            'none' => 'bg-neutral-100 text-neutral-700 dark:bg-zinc-700 dark:text-neutral-300',
                            'pending' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300',
                            'authorized' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
                            'rejected' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
                            'error' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                            'cancelled' => 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-400',
                        ];
                        $situationLabels = [
                            'none' => 'Sem nota',
                            'pending' => 'Em processamento',
                            'authorized' => 'Autorizada',
                            'rejected' => 'Rejeitada',
                            'error' => 'Erro',
                            'cancelled' => 'Cancelada',
                        ];
                        $errorMessage = in_array($situation, ['error', 'rejected'], true) ? ($note?->error_message ?? $order->fiscal_error) : null;
                        $document = $order->fiscal_recipient['document'] ?? null;
                    @endphp
                    <tr wire:key="order-{{ $order->id }}" class="transition-colors hover:bg-neutral-50 dark:hover:bg-zinc-750">
                        @if($canIssue)
                            <td class="px-2 py-3 sm:px-4">
                                @if($selectable[$order->id])
                                    <input type="checkbox" wire:model.live="selected" value="{{ $order->id }}"
                                        aria-label="Selecionar pedido {{ $order->order_number }}"
                                        class="size-4 rounded border-neutral-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-900" />
                                @endif
                            </td>
                        @endif
                        <td class="px-2 py-3 sm:px-4 text-sm text-neutral-700 dark:text-neutral-200">
                            <a href="{{ route('admin.orders.show', $order) }}" class="whitespace-nowrap font-medium hover:underline" wire:navigate>{{ $order->order_number }}</a>
                            <p class="text-xs text-neutral-400">
                                {{ $order->created_at->format('d/m/Y H:i') }}
                                @if($branches->count() > 1 && $order->branch) · {{ $order->branch->name }} @endif
                            </p>
                            {{-- Em tela estreita Canal/Cliente/Total viram sublinha do pedido --}}
                            <p class="text-xs text-neutral-500 lg:hidden dark:text-neutral-400">
                                {{ $channels[$order->id] }}<span class="md:hidden"> · {{ $order->customer?->name ?? 'Consumidor' }} · {{ $models[$order->id] === '55' ? 'NF-e' : 'NFC-e' }}</span><span class="sm:hidden"> · R$ {{ number_format((float) $order->total, 2, ',', '.') }}</span>
                            </p>
                        </td>
                        <td class="hidden px-2 py-3 sm:px-4 text-sm text-neutral-600 lg:table-cell dark:text-neutral-300">{{ $channels[$order->id] }}</td>
                        <td class="hidden px-2 py-3 sm:px-4 text-sm text-neutral-600 md:table-cell dark:text-neutral-300">
                            {{ $order->customer?->name ?? 'Consumidor' }}
                            <p class="text-xs text-neutral-400">
                                {{ $models[$order->id] === '55' ? 'NF-e' : 'NFC-e' }}@if($document) · {{ $document }}@endif
                            </p>
                        </td>
                        <td class="hidden whitespace-nowrap px-2 py-3 sm:px-4 text-right text-sm font-medium text-neutral-700 sm:table-cell dark:text-neutral-200">
                            R$ {{ number_format((float) $order->total, 2, ',', '.') }}
                        </td>
                        <td class="px-2 py-3 sm:px-4">
                            <span class="rounded-full px-2 py-1 text-xs font-medium {{ $situationColors[$situation] ?? '' }}">
                                {{ $situationLabels[$situation] ?? $situation }}
                            </span>
                            @if($errorMessage)
                                <p class="mt-1 max-w-[9rem] truncate text-xs text-red-600 md:max-w-[12rem] xl:max-w-xs dark:text-red-400" title="{{ $errorMessage }}">{{ $errorMessage }}</p>
                            @endif
                            <div class="mt-1 flex flex-wrap gap-2 sm:hidden">
                                @include('livewire.admin.fiscal._issue-control-actions')
                            </div>
                        </td>
                        <td class="hidden px-2 py-3 sm:px-4 text-right text-sm sm:table-cell">
                            <div class="flex flex-wrap justify-end gap-2">
                                @include('livewire.admin.fiscal._issue-control-actions')
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $canIssue ? 7 : 6 }}" class="px-4 py-8 text-center text-sm text-neutral-400 dark:text-neutral-500">
                            Nenhum pedido encontrado para os filtros.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($orders->hasPages())
            <div class="border-t px-4 py-3 dark:border-zinc-700">
                {{ $orders->links() }}
            </div>
        @endif
    </div>

    {{-- Confirmação do lote --}}
    @if($showConfirmModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="max-h-[85vh] w-full max-w-lg space-y-4 overflow-y-auto rounded-xl bg-white p-6 shadow-xl dark:bg-zinc-800">
                <h3 class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">Emitir notas fiscais</h3>

                @if(($confirmSummary['ready'] ?? 0) > 0)
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">
                        <strong>{{ $confirmSummary['ready'] }}</strong> nota(s) prontas para emissão
                        ({{ $confirmSummary['nfce'] }} NFC-e, {{ $confirmSummary['nfe'] }} NF-e),
                        somando <strong>R$ {{ number_format($confirmSummary['total'], 2, ',', '.') }}</strong>.
                    </p>
                @else
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">Nenhum dos pedidos selecionados está pronto para emissão.</p>
                @endif

                @if(($confirmSummary['older'] ?? 0) > 0)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                        {{ $confirmSummary['older'] }} pedido(s) são de dias anteriores. A nota sai com a data de hoje (data da emissão), não com a data da venda.
                    </div>
                @endif

                @if(! empty($confirmSummary['blocked']))
                    <div class="space-y-1">
                        <p class="text-sm font-medium text-red-700 dark:text-red-400">{{ count($confirmSummary['blocked']) }} pedido(s) com pendência (não serão emitidos):</p>
                        <ul class="max-h-40 space-y-1 overflow-y-auto text-xs text-neutral-600 dark:text-neutral-300">
                            @foreach($confirmSummary['blocked'] as $item)
                                <li><strong>{{ $item['order_number'] }}</strong>: {{ $item['reason'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="flex justify-end gap-3">
                    <flux:button wire:click="closeConfirmModal" variant="ghost">Voltar</flux:button>
                    @if(($confirmSummary['ready'] ?? 0) > 0)
                        <flux:button wire:click="issueSelected" wire:loading.attr="disabled" wire:target="issueSelected" variant="primary">
                            Emitir {{ $confirmSummary['ready'] }} nota(s)
                        </flux:button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Confirmação de um pedido (botão da linha) --}}
    @if($showIssueOneModal && $issueOneOrder)
        @php
            $lastNote = $issueOneOrder->latestFiscalNote;
            $isRetry = $lastNote !== null || filled($issueOneOrder->fiscal_error);
            $recipientDocument = preg_replace('/\D/', '', $issueOneOrder->fiscal_recipient['document'] ?? '');
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="max-h-[85vh] w-full max-w-lg space-y-4 overflow-y-auto rounded-xl bg-white p-6 shadow-xl dark:bg-zinc-800">
                <h3 class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">{{ $isRetry ? 'Reemitir nota fiscal' : 'Emitir nota fiscal' }}</h3>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <dt class="text-neutral-500 dark:text-neutral-400">Pedido</dt>
                    <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ $issueOneOrder->order_number }} · {{ $issueOneChannel }}</dd>
                    <dt class="text-neutral-500 dark:text-neutral-400">Nota</dt>
                    <dd class="text-neutral-700 dark:text-neutral-200">{{ $issueOneModel === '55' ? 'NF-e' : 'NFC-e' }} · R$ {{ number_format((float) $issueOneOrder->total, 2, ',', '.') }}</dd>
                    <dt class="text-neutral-500 dark:text-neutral-400">CPF/CNPJ na nota</dt>
                    <dd class="text-neutral-700 dark:text-neutral-200">{{ $recipientDocument !== '' ? \App\Services\Fiscal\FiscalRecipient::formatDocument($recipientDocument) : 'Não informado' }}</dd>
                    @if($lastNote)
                        <dt class="text-neutral-500 dark:text-neutral-400">Última tentativa</dt>
                        <dd class="text-neutral-700 dark:text-neutral-200">{{ $lastNote->created_at->format('d/m/Y H:i') }} · {{ ['rejected' => 'Rejeitada', 'error' => 'Erro', 'cancelled' => 'Cancelada'][$lastNote->status] ?? $lastNote->status }}</dd>
                    @endif
                </dl>

                @include('livewire.admin.fiscal._issue-warnings', [
                    'errorMessage' => $lastNote?->error_message ?? $issueOneOrder->fiscal_error,
                    'sefazStatus' => $lastNote?->raw_response['status_sefaz'] ?? null,
                    'orderDate' => $issueOneOrder->created_at,
                ])

                @if($issueOneBlockedReason)
                    <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-700 dark:bg-red-900/30 dark:text-red-400">
                        <strong>Pendência:</strong> {{ $issueOneBlockedReason }}
                    </div>
                @else
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">A nota será enviada à SEFAZ agora.</p>
                @endif

                <div class="flex justify-end gap-3">
                    <flux:button wire:click="closeIssueOneModal" variant="ghost">Voltar</flux:button>
                    @unless($issueOneBlockedReason)
                        <flux:button wire:click="issueOne" wire:loading.attr="disabled" wire:target="issueOne" variant="primary">
                            {{ $isRetry ? 'Reemitir nota' : 'Emitir nota' }}
                        </flux:button>
                    @endunless
                </div>
            </div>
        </div>
    @endif
</div>
