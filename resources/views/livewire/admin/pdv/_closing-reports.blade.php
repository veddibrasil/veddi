{{-- Relatórios de fechamento de caixa (lista e detalhe da sessão), aberto sobre o PDV. Incluída por terminal.blade.php (mesmo escopo de variáveis e $this do componente). --}}
<div class="fixed inset-0 z-30 flex flex-col bg-white dark:bg-zinc-900">
    <div class="shrink-0 px-4 py-3 flex items-center gap-3 border-b dark:border-zinc-700 bg-white dark:bg-zinc-900">
        @if ($viewingClosedSessionId)
            <flux:button wire:click="backToClosingReportsList" variant="ghost" icon="arrow-left" size="sm" aria-label="Voltar para a lista de fechamentos" />
            <h2 class="text-base font-bold text-neutral-800 dark:text-neutral-100">Relatório de fechamento</h2>
        @else
            <flux:button wire:click="backFromClosingReports" variant="ghost" icon="arrow-left" size="sm" aria-label="Voltar" />
            <h2 class="text-base font-bold text-neutral-800 dark:text-neutral-100">Fechamentos de caixa</h2>
        @endif
    </div>
    <div class="flex-1 overflow-y-auto p-4">
        @if ($viewingClosedSessionId)
            @php $closedSession = $this->viewingClosedSession; @endphp
            @if (! $closedSession)
                <p class="text-sm text-neutral-500 dark:text-neutral-400 text-center py-12">Fechamento não encontrado.</p>
            @else
                @php
                    $closedBreakdown = $this->cashSessionBreakdown($closedSession);
                    $closedStats = $this->closedSessionStats($closedSession);
                    $closedDiff = round($closedSession->closing_amount - $closedSession->expected_amount, 2);
                @endphp
                <div class="max-w-sm mx-auto space-y-5">
                    <div class="text-center">
                        <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100">{{ $closedSession->terminal_name ?: 'Terminal' }}</p>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">
                            {{ $closedSession->closed_at->format('d/m/Y H:i') }} · {{ $closedStats['operator'] }}
                        </p>
                    </div>

                    <div class="bg-amber-50 rounded-xl p-3 border border-amber-100 dark:bg-amber-900/10 dark:border-amber-900/20 text-xs text-amber-700 dark:text-amber-400 space-y-1">
                        <div class="flex justify-between">
                            <span>Duração do turno</span>
                            <span class="font-medium">{{ $closedStats['duration'] }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Pedidos no turno</span>
                            <span class="font-medium">{{ $closedStats['orders'] }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Faturamento total</span>
                            <span class="font-medium">R$ {{ number_format($closedStats['revenue'], 2, ',', '.') }}</span>
                        </div>
                    </div>

                    <div class="bg-neutral-50 rounded-xl p-4 border dark:bg-zinc-800 dark:border-zinc-700 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-neutral-500">Abertura</span>
                            <span class="font-medium">R$ {{ number_format($closedBreakdown['opening'], 2, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-neutral-500">Vendas em dinheiro</span>
                            <span class="font-medium text-green-600">+ R$ {{ number_format($closedBreakdown['cash_sales'], 2, ',', '.') }}</span>
                        </div>
                        @if ($closedBreakdown['supplies'] > 0)
                            <div class="flex justify-between">
                                <span class="text-neutral-500">Suprimentos</span>
                                <span class="font-medium text-green-600">+ R$ {{ number_format($closedBreakdown['supplies'], 2, ',', '.') }}</span>
                            </div>
                        @endif
                        @if ($closedBreakdown['withdrawals'] > 0)
                            <div class="flex justify-between">
                                <span class="text-neutral-500">Sangrias</span>
                                <span class="font-medium text-red-600">- R$ {{ number_format($closedBreakdown['withdrawals'], 2, ',', '.') }}</span>
                            </div>
                        @endif
                        <div class="border-t pt-2 flex justify-between font-bold dark:border-zinc-600">
                            <span>Esperado no caixa</span>
                            <span class="text-amber-500 dark:text-amber-400">R$ {{ number_format($closedBreakdown['expected'], 2, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-neutral-500">Contado no caixa</span>
                            <span class="font-medium">R$ {{ number_format($closedSession->closing_amount, 2, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between font-bold">
                            <span>{{ $closedDiff >= 0 ? 'Sobra' : 'Falta' }}</span>
                            <span class="{{ abs($closedDiff) < 0.01 ? 'text-neutral-500' : ($closedDiff >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400') }}">
                                R$ {{ number_format(abs($closedDiff), 2, ',', '.') }}
                            </span>
                        </div>
                    </div>

                    @if ($closedSession->reconciliation_notes)
                        <div class="bg-neutral-50 rounded-xl p-3 border dark:bg-zinc-800 dark:border-zinc-700 text-sm">
                            <p class="text-xs text-neutral-500 dark:text-neutral-400 mb-1">Justificativa da diferença</p>
                            <p>{{ $closedSession->reconciliation_notes }}</p>
                        </div>
                    @endif

                    @unless ($isCaixa)
                        <flux:button
                            href="{{ route('admin.pdv.cash-session.print', $closedSession) }}"
                            target="_blank"
                            variant="ghost"
                            icon="printer"
                            class="w-full"
                        >
                            Imprimir fechamento
                        </flux:button>
                    @endunless
                </div>
            @endif
        @else
            @if ($this->closedSessions->isEmpty())
                <div class="text-center py-12 text-neutral-500 dark:text-neutral-400">
                    <flux:icon.document-text class="size-10 mx-auto mb-2 opacity-40" />
                    <p class="text-sm">Nenhum fechamento registrado ainda.</p>
                </div>
            @else
                <div class="space-y-2 max-w-2xl mx-auto">
                    @foreach ($this->closedSessions as $closedItem)
                        @php $itemDiff = round($closedItem->closing_amount - $closedItem->expected_amount, 2); @endphp
                        <button
                            wire:click="viewClosedSession({{ $closedItem->id }})"
                            class="w-full text-left bg-white border rounded-xl px-4 py-3 dark:bg-zinc-800 dark:border-zinc-700 hover:border-amber-400 dark:hover:border-amber-500 transition-colors"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                                        {{ $closedItem->closed_at->format('d/m/Y H:i') }}
                                        @if ($closedItem->terminal_name)
                                            · {{ $closedItem->terminal_name }}
                                        @endif
                                    </p>
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $closedItem->user?->name ?? '—' }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($closedItem->closing_amount, 2, ',', '.') }}</p>
                                    <p class="text-xs {{ abs($itemDiff) < 0.01 ? 'text-neutral-400' : ($itemDiff >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400') }}">
                                        {{ abs($itemDiff) < 0.01 ? 'Conferido' : ($itemDiff >= 0 ? 'Sobra' : 'Falta') . ' R$ ' . number_format(abs($itemDiff), 2, ',', '.') }}
                                    </p>
                                </div>
                            </div>
                        </button>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</div>
