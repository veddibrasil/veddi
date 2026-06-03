<div class="w-full space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Notas Fiscais (NFC-e)</h1>
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

    {{-- Filters --}}
    <div class="flex flex-col sm:flex-row gap-3">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Buscar por chave, ref ou pedido..." class="sm:max-w-xs" />
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
                                    {{ $note->order->order_number }}
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
                            <div class="flex justify-end gap-2">
                                @if($note->danfe_url)
                                    <a href="{{ $note->danfe_url }}" target="_blank"
                                        class="text-blue-600 hover:text-blue-800 dark:text-blue-400 font-medium text-xs">
                                        DANFE
                                    </a>
                                @endif
                                @if($note->xml_url)
                                    <a href="{{ $note->xml_url }}" target="_blank"
                                        class="text-blue-600 hover:text-blue-800 dark:text-blue-400 font-medium text-xs">
                                        XML
                                    </a>
                                @endif
                                @if($canIssue && $note->isActive())
                                    <button wire:click="openCancelModal({{ $note->id }})"
                                        class="text-red-500 hover:text-red-700 dark:text-red-400 font-medium text-xs">
                                        Cancelar
                                    </button>
                                @endif
                                @if($note->error_message)
                                    <span class="text-red-500 dark:text-red-400 text-xs truncate max-w-xs" title="{{ $note->error_message }}">
                                        Erro
                                    </span>
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
