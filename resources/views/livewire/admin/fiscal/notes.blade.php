<div class="w-full space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Notas Fiscais (NF-e e NFC-e)</h1>
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

    @if($canIssue)
        <flux:button wire:click="openInvalidation" size="sm">Inutilizar numeração de NF-e</flux:button>
    @endif
    @if($pendingOrders->isNotEmpty())
        <div class="rounded-xl border border-amber-200 p-4 space-y-2">
            <h2 class="font-semibold">Pendências fiscais</h2>
            @foreach($pendingOrders as $pendingOrder)
                <div class="text-sm">
                    <a class="text-blue-600 underline" href="{{ route('admin.orders.show', $pendingOrder) }}" wire:navigate>{{ $pendingOrder->order_number }} — Completar dados fiscais</a>
                    <p class="text-xs text-amber-700">{{ $pendingOrder->fiscal_error }}</p>
                    @if($canIssue)<button wire:click="issuePendingOrder({{ $pendingOrder->id }})" class="text-blue-600 text-xs">Tentar emissão novamente</button>@endif
                </div>
            @endforeach
        </div>
    @endif
    {{-- Filters --}}
    <div class="flex flex-col sm:flex-row gap-3">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Buscar por chave, ref ou pedido..." class="sm:max-w-xs" />
        <flux:select wire:model.live="modelFilter" class="sm:max-w-xs">
            <option value="">Todos os modelos</option><option value="55">NF-e</option><option value="65">NFC-e</option>
        </flux:select>
        <flux:select wire:model.live="statusFilter" class="sm:max-w-xs">
            <option value="">Todos os status</option>
            <option value="pending">Pendente</option>
            <option value="authorized">Autorizada</option>
            <option value="rejected">Rejeitada</option>
            <option value="cancelled">Cancelada</option>
            <option value="error">Erro</option>
        </flux:select>
    </div>

    {{-- Table --}}
    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
        <table class="min-w-full divide-y divide-neutral-200 dark:divide-zinc-700">
            <thead class="bg-neutral-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Pedido</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Chave de Acesso</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Emitida em</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($notes as $note)
                    @php
                        $statusColors = [
                            'pending'    => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300',
                            'authorized' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
                            'rejected'   => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
                            'cancelled'  => 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-400',
                            'error'      => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                        ];
                        $statusLabels = [
                            'pending'    => 'Pendente',
                            'authorized' => 'Autorizada',
                            'rejected'   => 'Rejeitada',
                            'cancelled'  => 'Cancelada',
                            'error'      => 'Erro',
                        ];
                    @endphp
                    <tr class="hover:bg-neutral-50 dark:hover:bg-zinc-750 transition-colors">
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            @if($note->order)
                                <a href="{{ route('admin.orders.show', $note->order) }}" class="font-medium hover:underline" wire:navigate>
                                    {{ $note->order->order_number }} · {{ $note->model_label }}
                                </a>
                            @else
                                <span class="text-neutral-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs font-medium {{ $statusColors[$note->status] ?? '' }}">
                                {{ $statusLabels[$note->status] ?? $note->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-neutral-500 dark:text-neutral-400 font-mono truncate max-w-xs">
                            {{ $note->access_key ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-500 dark:text-neutral-400">
                            {{ $note->created_at->format('d/m/Y H:i') }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm">
                            <div class="flex justify-end gap-2 flex-wrap">
                                @if($canIssue)
                                    <button wire:click="consult({{ $note->id }})" class="text-blue-600 text-xs">Consultar</button>
                                    @if($note->model === '55' && $note->status === 'authorized')
                                        <button wire:click="openCorrection({{ $note->id }})" class="text-blue-600 text-xs">Carta de correção</button>
                                    @endif
                                @endif
                                @if($note->danfe_url)
                                    <a href="{{ route('admin.fiscal.document', ['note' => $note->id, 'type' => 'danfe']) }}" target="_blank"
                                        class="text-blue-600 hover:text-blue-800 dark:text-blue-400 font-medium text-xs">
                                        DANFE
                                    </a>
                                @endif
                                @if($note->xml_url)
                                    <a href="{{ route('admin.fiscal.document', ['note' => $note->id, 'type' => 'xml']) }}" target="_blank"
                                        class="text-blue-600 hover:text-blue-800 dark:text-blue-400 font-medium text-xs">
                                        XML
                                    </a>
                                @endif
                                @if($canIssue && $note->isCancellable())
                                    <button wire:click="openCancelModal({{ $note->id }})"
                                        class="text-red-500 hover:text-red-700 dark:text-red-400 font-medium text-xs">
                                        Cancelar
                                    </button>
                                @endif
                                @if($canIssue && $note->order_id && in_array($note->status, ['error', 'rejected']))
                                    <button wire:click="reissue({{ $note->id }})"
                                        wire:confirm="Reemitir a nota fiscal para este pedido?"
                                        class="text-blue-600 hover:text-blue-800 dark:text-blue-400 font-medium text-xs">
                                        Reemitir
                                    </button>
                                @endif
                                @if($note->error_message)
                                    <button wire:click="openErrorModal({{ $note->id }})"
                                        class="text-red-500 hover:text-red-700 dark:text-red-400 font-medium text-xs">
                                        Ver erro
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-neutral-400 dark:text-neutral-500">
                            Nenhuma nota fiscal encontrada.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($notes->hasPages())
            <div class="px-4 py-3 border-t dark:border-zinc-700">
                {{ $notes->links() }}
            </div>
        @endif
    </div>

    {{-- Error detail modal --}}
    @if($showErrorModal && $errorNote)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="bg-white dark:bg-zinc-800 rounded-xl shadow-xl p-6 max-w-2xl w-full mx-4 max-h-[85vh] overflow-y-auto">
                <h3 class="text-lg font-semibold text-neutral-800 dark:text-neutral-100 mb-4">Detalhe do erro — Nota #{{ $errorNote->id }}</h3>

                <div class="space-y-4">
                    <div>
                        <h4 class="text-xs font-medium text-neutral-500 uppercase tracking-wide dark:text-neutral-400 mb-1">Mensagem</h4>
                        <p class="text-sm text-red-600 dark:text-red-400 whitespace-pre-wrap">{{ $errorNote->error_message ?? '—' }}</p>
                    </div>

                    @if(!empty($errorNote->request_payload))
                        <div>
                            <h4 class="text-xs font-medium text-neutral-500 uppercase tracking-wide dark:text-neutral-400 mb-1">Parâmetros enviados à Focus NFe</h4>
                            <pre class="text-xs bg-neutral-50 dark:bg-zinc-900 border dark:border-zinc-700 rounded-lg p-3 overflow-x-auto">{{ json_encode($errorNote->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    @endif

                    @if(!empty($errorNote->raw_response))
                        <div>
                            <h4 class="text-xs font-medium text-neutral-500 uppercase tracking-wide dark:text-neutral-400 mb-1">Resposta bruta da Focus NFe</h4>
                            <pre class="text-xs bg-neutral-50 dark:bg-zinc-900 border dark:border-zinc-700 rounded-lg p-3 overflow-x-auto">{{ json_encode($errorNote->raw_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    @endif

                    @if(empty($errorNote->request_payload) && empty($errorNote->raw_response))
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">
                            Sem parâmetros/resposta registrados para esta tentativa (falha ocorreu antes do envio à Focus NFe).
                        </p>
                    @endif
                </div>

                <div class="flex justify-end mt-6">
                    <flux:button wire:click="closeErrorModal" variant="ghost">Fechar</flux:button>
                </div>
            </div>
        </div>
    @endif

    @if($fiscalEvents->isNotEmpty())
        <details class="border rounded-xl p-4">
            <summary class="cursor-pointer">Histórico de eventos fiscais</summary>
            @foreach($fiscalEvents as $event)
                <p class="text-xs mt-2">#{{ $event->id }} · {{ ['correction' => 'Carta de correção', 'invalidation' => 'Inutilização', 'cancellation' => 'Cancelamento'][$event->type] ?? $event->type }} · {{ $event->status }} · {{ $event->created_at->format('d/m/Y H:i') }}</p>
                @if($canIssue && $event->status === 'pending')<button wire:click="consultEvent({{ $event->id }})" class="text-xs text-blue-600">Consultar evento</button>@endif
                <p class="text-xs text-neutral-500">{{ $event->data['response']['mensagem_sefaz'] ?? $event->data['response']['mensagem'] ?? $event->data['error'] ?? '' }}</p>
            @endforeach
        </details>
    @endif
    @if($showCorrectionModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="bg-white dark:bg-zinc-800 rounded-xl p-6 w-full max-w-lg space-y-3">
                <h2 class="font-semibold">Carta de correção da NF-e</h2>
                <p class="text-xs text-neutral-500">Use apenas para alterações permitidas. Mudanças de valores, impostos e identidade do destinatário exigem outro procedimento fiscal.</p>
                <flux:textarea wire:model="correctionText" label="Correção" rows="4" />
                @error('correctionText') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                <flux:button wire:click="$set('showCorrectionModal', false)">Voltar</flux:button>
                <flux:button wire:click="confirmCorrection" wire:loading.attr="disabled" variant="primary">Enviar correção</flux:button>
            </div>
        </div>
    @endif
    @if($showInvalidationModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="bg-white dark:bg-zinc-800 rounded-xl p-6 w-full max-w-lg space-y-3">
                <h2 class="font-semibold">Inutilizar numeração de NF-e</h2>
                <flux:select wire:model="invalidationConfigId" label="Emitente">
                    <option value="">Selecione</option>
                    @foreach($emitterConfigs as $emitter)<option value="{{ $emitter->id }}">{{ $emitter->branch?->name }} · {{ $emitter->branch?->cnpj }}</option>@endforeach
                </flux:select>
                @error('invalidationConfigId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                @foreach(['serie' => 'Série', 'numero_inicial' => 'Número inicial', 'numero_final' => 'Número final', 'justificativa' => 'Justificativa'] as $field => $label)
                    <flux:input wire:model="invalidation.{{ $field }}" :label="$label" />
                    @error($field) <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                @endforeach
                <flux:button wire:click="$set('showInvalidationModal', false)">Voltar</flux:button>
                <flux:button wire:click="confirmInvalidation" wire:loading.attr="disabled" variant="danger">Confirmar inutilização</flux:button>
            </div>
        </div>
    @endif

    {{-- Cancel modal --}}
    @if($showCancelModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white dark:bg-zinc-800 rounded-xl shadow-xl p-6 max-w-md w-full mx-4">
                <h3 class="text-lg font-semibold text-neutral-800 dark:text-neutral-100 mb-4">Cancelar Nota Fiscal</h3>
                <p class="text-sm text-neutral-500 dark:text-neutral-400 mb-4">
                    Informe a justificativa do cancelamento (mínimo 15 caracteres, conforme exigência da SEFAZ).
                </p>
                <flux:textarea wire:model="cancelJustification" label="Justificativa" rows="3" placeholder="Descreva o motivo do cancelamento..." />
                @error('cancelJustification') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                <div class="flex justify-end gap-3 mt-4">
                    <flux:button wire:click="closeCancelModal" variant="ghost">Voltar</flux:button>
                    <flux:button wire:click="confirmCancel" variant="danger">Confirmar Cancelamento</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
