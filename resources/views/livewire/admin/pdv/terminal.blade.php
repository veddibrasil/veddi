<div class="flex flex-col flex-1 min-h-0 bg-zinc-50 text-neutral-900 dark:bg-[#0f1926] dark:text-neutral-100" x-data="pdvApp()" x-effect="watchPdvStep($wire.step)">

    {{-- ══ Toast: produto adicionado ══ --}}
    <div
        x-show="toastMessage"
        x-transition
        x-text="toastMessage"
        class="fixed top-4 right-4 z-50 rounded-lg bg-neutral-900 text-white text-sm font-semibold px-4 py-2.5 shadow-lg dark:bg-amber-500 dark:text-white"
        style="display: none;"
    ></div>

    {{-- ══ Header ══ --}}
    <div class="flex items-center justify-between gap-4 px-5 py-3 bg-white border-b border-neutral-200 shadow-sm dark:bg-zinc-900 dark:border-zinc-800 shrink-0">
        <div class="flex items-center gap-3 min-w-0">
            <div class="size-9 rounded-lg bg-amber-500 text-white flex items-center justify-center shadow-sm shadow-amber-500/20 dark:bg-amber-400 dark:text-white">
                <flux:icon.computer-desktop class="size-5" />
            </div>
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h1 class="text-base font-bold text-neutral-900 dark:text-neutral-100">Terminal PDV</h1>
                    @if ($cashSessionId)
                        <span class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">
                            <span class="size-1.5 rounded-full bg-green-500"></span>
                            Caixa aberto
                        </span>
                    @endif
                </div>
                <p class="hidden sm:block text-xs text-neutral-500 dark:text-neutral-400 truncate">
                    {{ $this->branches->firstWhere('id', $selectedBranchId)?->name ?? $this->branches->first()?->name ?? 'Filial' }}
                    @if ($cashSessionId && $this->shiftStats['terminal'])
                        · {{ $this->shiftStats['terminal'] }}
                    @endif
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            @if ($this->branches->count() > 1)
                <flux:select wire:model.live="selectedBranchId" class="w-56">
                    @foreach ($this->branches as $branch)
                        <flux:select.option value="{{ $branch->id }}">{{ $branch->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <span class="text-sm text-neutral-500 dark:text-neutral-400">
                    {{ $this->branches->first()?->name ?? '—' }}
                </span>
            @endif

            @unless ($isWaiter)
                <flux:button wire:click="openClosingReports" variant="outline" size="sm" icon="document-text" class="hidden sm:flex" title="Relatórios de fechamento" />
            @endunless

            @if ($cashSessionId && !in_array($step, ['open_cash', 'close_cash']))
                <flux:button wire:click="proceedToCloseCash" variant="outline" size="sm" icon="lock-closed" class="hover:text-red-600">
                    Fechar caixa
                </flux:button>
            @endif

            <button
                @click="toggleSidebar()"
                :title="sidebarHidden ? 'Mostrar menu lateral' : 'Ocultar menu lateral'"
                class="p-2 rounded-lg border border-neutral-200 text-neutral-500 hover:text-neutral-800 hover:bg-neutral-50 dark:border-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-neutral-200 transition-colors"
            >
                <svg x-show="!sidebarHidden" xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7M4 12h16" />
                </svg>
                <svg x-show="sidebarHidden" xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 5l7 7-7 7M20 12H4" />
                </svg>
            </button>
        </div>
    </div>

    {{-- ══ Relatórios de fechamento (overlay) ══ --}}
    @if ($showClosingReports)
        <div class="fixed inset-0 z-30 flex flex-col bg-white dark:bg-zinc-900">
            <div class="shrink-0 px-4 py-3 flex items-center gap-3 border-b dark:border-zinc-700 bg-white dark:bg-zinc-900">
                @if ($viewingClosedSessionId)
                    <flux:button wire:click="backToClosingReportsList" variant="ghost" icon="arrow-left" size="sm" />
                    <h2 class="text-base font-bold text-neutral-800 dark:text-neutral-100">Relatório de fechamento</h2>
                @else
                    <flux:button wire:click="backFromClosingReports" variant="ghost" icon="arrow-left" size="sm" />
                    <h2 class="text-base font-bold text-neutral-800 dark:text-neutral-100">Fechamentos de caixa</h2>
                @endif
            </div>
            <div class="flex-1 overflow-y-auto p-4">
                @if ($viewingClosedSessionId)
                    @php $closedSession = $this->viewingClosedSession; @endphp
                    @if (! $closedSession)
                        <p class="text-sm text-neutral-400 dark:text-neutral-500 text-center py-12">Fechamento não encontrado.</p>
                    @else
                        @php
                            $closedBreakdown = $this->cashSessionBreakdown($closedSession);
                            $closedStats = $this->closedSessionStats($closedSession);
                            $closedDiff = round($closedSession->closing_amount - $closedSession->expected_amount, 2);
                        @endphp
                        <div class="max-w-sm mx-auto space-y-5">
                            <div class="text-center">
                                <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100">{{ $closedSession->terminal_name ?: 'Terminal' }}</p>
                                <p class="text-xs text-neutral-400 dark:text-neutral-500">
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
                                    <p class="text-xs text-neutral-400 dark:text-neutral-500 mb-1">Justificativa da diferença</p>
                                    <p>{{ $closedSession->reconciliation_notes }}</p>
                                </div>
                            @endif

                            <flux:button
                                href="{{ route('admin.pdv.cash-session.print', $closedSession) }}"
                                target="_blank"
                                variant="ghost"
                                icon="printer"
                                class="w-full"
                            >
                                Imprimir fechamento
                            </flux:button>
                        </div>
                    @endif
                @else
                    @if ($this->closedSessions->isEmpty())
                        <div class="text-center py-12 text-neutral-400 dark:text-neutral-500">
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
                                            <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $closedItem->user?->name ?? '—' }}</p>
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
    @endif

    {{-- ══ Barra de turno ══ --}}
    @if ($cashSessionId && !in_array($step, ['open_cash', 'close_cash']))
        @php $stats = $this->shiftStats; @endphp
        <div class="shrink-0 px-5 py-2 bg-amber-600 text-white border-b border-amber-700 dark:bg-amber-900/40 dark:border-amber-800 flex items-center gap-3 text-xs overflow-x-auto">
            <span class="flex items-center gap-1">
                <flux:icon.user-circle class="size-3.5" />
                {{ $stats['operator'] }}
            </span>
            <span class="text-white/30">|</span>
            <span class="flex items-center gap-1">
                <flux:icon.clock class="size-3.5" />
                Turno aberto há {{ $stats['duration'] }}
            </span>
            <span class="text-white/30">|</span>
            <span class="flex items-center gap-1">
                <flux:icon.shopping-bag class="size-3.5" />
                {{ $stats['orders'] }} pedido{{ $stats['orders'] !== 1 ? 's' : '' }}
            </span>
            @if ($stats['revenue'] > 0)
                <span class="text-white/30">|</span>
                    <span class="flex items-center gap-1 font-bold text-amber-100">
                    R$ {{ number_format($stats['revenue'], 2, ',', '.') }}
                </span>
            @endif
            <span class="text-white/30">|</span>
            <button
                type="button"
                wire:click="toggleCashMovementForm('supply')"
                class="inline-flex items-center gap-1 rounded-md bg-white/10 px-2 py-1 font-semibold text-white transition hover:bg-white/20"
            >
                <flux:icon.plus class="size-3.5" />
                Suprimento
            </button>
            <button
                type="button"
                wire:click="toggleCashMovementForm('withdrawal')"
                class="inline-flex items-center gap-1 rounded-md bg-white/10 px-2 py-1 font-semibold text-white transition hover:bg-white/20"
            >
                <flux:icon.minus class="size-3.5" />
                Sangria
            </button>
        </div>
        @if ($showCashMovementForm)
            <div class="shrink-0 border-b border-amber-100 bg-amber-50 px-5 py-3 dark:border-amber-900/30 dark:bg-amber-900/10">
                <div class="flex flex-col gap-2 lg:flex-row lg:items-start">
                    <div class="min-w-36">
                        <p class="text-xs font-bold uppercase tracking-wider text-amber-700 dark:text-amber-300">
                            {{ $cashMovementType === 'withdrawal' ? 'Sangria' : 'Suprimento' }}
                        </p>
                        <p class="text-xs text-amber-600/80 dark:text-amber-300/80">Movimentação manual do caixa.</p>
                    </div>
                    <div class="grid flex-1 gap-2 sm:grid-cols-[10rem_minmax(0,1fr)_auto]">
                        <div>
                            <flux:input
                                wire:model="cashMovementAmountInput"
                                placeholder="R$ 0,00"
                                type="number"
                                step="0.01"
                                min="0"
                            />
                            @error('cash_movement_amount')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <flux:input
                                wire:model="cashMovementReason"
                                wire:keydown.enter="registerCashMovement"
                                placeholder="Motivo"
                            />
                            @error('cash_movement_reason')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="flex gap-2">
                            <flux:button wire:click="registerCashMovement" variant="primary" size="sm">
                                Registrar
                            </flux:button>
                            <flux:button wire:click="toggleCashMovementForm('{{ $cashMovementType }}')" variant="ghost" size="sm">
                                Cancelar
                            </flux:button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- ══ Abertura de caixa ══ --}}
    @if ($step === 'open_cash')
        <div class="flex-1 flex items-center justify-center p-8">
            <div class="w-full max-w-sm space-y-5">
                <div class="text-center">
                    <div class="mx-auto size-16 rounded-full bg-amber-100 flex items-center justify-center dark:bg-amber-900/40 mb-4">
                        <flux:icon.banknotes class="size-8 text-amber-500 dark:text-amber-400" />
                    </div>
                    <h2 class="text-xl font-bold text-neutral-800 dark:text-neutral-100">Abrir caixa</h2>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Informe o troco inicial e identifique este terminal.</p>
                </div>

                <div class="space-y-3">
                    <div class="space-y-2">
                        <flux:label>Nome do terminal (opcional)</flux:label>
                        <flux:input
                            wire:model="terminalName"
                            placeholder="Ex: Caixa 1, PDV Balcão..."
                        />
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">Útil quando há múltiplos caixas na mesma filial.</p>
                    </div>

                    <div class="space-y-2">
                        <flux:label>Valor de abertura (troco inicial)</flux:label>
                        <flux:input
                            wire:model="openingAmountInput"
                            wire:keydown.enter="openCashSession"
                            placeholder="R$ 0,00"
                            type="number"
                            step="0.01"
                            min="0"
                        />
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">Deixe em branco se o caixa começa sem troco.</p>
                    </div>
                </div>

                <flux:button wire:click="openCashSession" variant="primary" size="base" class="w-full">
                    Abrir caixa e começar
                </flux:button>
            </div>
        </div>

    {{-- ══ Fechamento de caixa ══ --}}
    @elseif ($step === 'close_cash')
        @php
            $session = $this->cashSession;
            $cashBreakdown = $this->cashSessionBreakdown($session);
            $expectedCash = $cashBreakdown['expected'];
            $closingVal = filled($closingAmountInput) ? (float) str_replace(',', '.', $closingAmountInput) : null;
            $closingDiff = $closingVal !== null ? round($closingVal - $expectedCash, 2) : null;
            $needsNotes = $closingDiff !== null && abs($closingDiff) > 5.0;
        @endphp
        <div class="flex-1 flex items-center justify-center p-8 overflow-y-auto">
            <div class="w-full max-w-sm space-y-5">
                <div class="text-center">
                    <div class="mx-auto size-16 rounded-full bg-amber-100 flex items-center justify-center dark:bg-amber-900/40 mb-4">
                        <flux:icon.lock-closed class="size-8 text-amber-600 dark:text-amber-400" />
                    </div>
                    <h2 class="text-xl font-bold text-neutral-800 dark:text-neutral-100">Fechar caixa</h2>
                    @if ($session?->terminal_name)
                        <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">{{ $session->terminal_name }}</p>
                    @endif
                </div>

                @if ($session)
                    @php $turnoStats = $this->shiftStats; @endphp
                    <div class="bg-amber-50 rounded-xl p-3 border border-amber-100 dark:bg-amber-900/10 dark:border-amber-900/20 text-xs text-amber-700 dark:text-amber-400 space-y-1">
                        <div class="flex justify-between">
                            <span>Duração do turno</span>
                            <span class="font-medium">{{ $turnoStats['duration'] }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Pedidos no turno</span>
                            <span class="font-medium">{{ $turnoStats['orders'] }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Faturamento total</span>
                            <span class="font-medium">R$ {{ number_format($turnoStats['revenue'], 2, ',', '.') }}</span>
                        </div>
                    </div>

                    <div class="bg-neutral-50 rounded-xl p-4 border dark:bg-zinc-800 dark:border-zinc-700 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-neutral-500">Abertura</span>
                            <span class="font-medium">R$ {{ number_format($cashBreakdown['opening'], 2, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-neutral-500">Vendas em dinheiro</span>
                            <span class="font-medium text-green-600">+ R$ {{ number_format($cashBreakdown['cash_sales'], 2, ',', '.') }}</span>
                        </div>
                        @if ($cashBreakdown['supplies'] > 0)
                            <div class="flex justify-between">
                                <span class="text-neutral-500">Suprimentos</span>
                                <span class="font-medium text-green-600">+ R$ {{ number_format($cashBreakdown['supplies'], 2, ',', '.') }}</span>
                            </div>
                        @endif
                        @if ($cashBreakdown['withdrawals'] > 0)
                            <div class="flex justify-between">
                                <span class="text-neutral-500">Sangrias</span>
                                <span class="font-medium text-red-600">- R$ {{ number_format($cashBreakdown['withdrawals'], 2, ',', '.') }}</span>
                            </div>
                        @endif
                        <div class="border-t pt-2 flex justify-between font-bold dark:border-zinc-600">
                            <span>Esperado no caixa</span>
                            <span class="text-amber-500 dark:text-amber-400">R$ {{ number_format($expectedCash, 2, ',', '.') }}</span>
                        </div>
                    </div>
                @endif

                <div class="space-y-2">
                    <flux:label>Valor contado no caixa</flux:label>
                    <flux:input
                        wire:model.live="closingAmountInput"
                        placeholder="R$ 0,00"
                        type="number"
                        step="0.01"
                        min="0"
                    />
                    @error('closingAmountInput')
                        <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                    @if ($closingDiff !== null)
                        @if (abs($closingDiff) < 0.01)
                            <p class="text-sm text-green-600 dark:text-green-400">Caixa conferido exatamente.</p>
                        @else
                            <p class="text-sm {{ $closingDiff >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                {{ $closingDiff >= 0 ? 'Sobra' : 'Falta' }}: R$ {{ number_format(abs($closingDiff), 2, ',', '.') }}
                                @if ($needsNotes)
                                    — <span class="font-semibold">justificativa obrigatória</span>
                                @endif
                            </p>
                        @endif
                    @endif
                </div>

                @if ($needsNotes)
                    <div class="space-y-2">
                        <flux:label>Justificativa da diferença <span class="text-red-500">*</span></flux:label>
                        <flux:textarea
                            wire:model="reconciliationNotes"
                            placeholder="Explique o motivo da diferença..."
                            rows="2"
                            class="resize-none"
                        />
                        @error('reconciliation_notes')
                            <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                <div class="flex gap-2">
                    <flux:button wire:click="cancelCloseCash" variant="ghost" size="base" class="flex-1">
                        Cancelar
                    </flux:button>
                    <flux:button wire:click="closeCashSession" variant="primary" size="base" class="flex-1">
                        Confirmar fechamento
                    </flux:button>
                </div>
            </div>
        </div>

    {{-- ══ Layout 3 colunas: catálogo + painel direito ══ --}}
    @else
        <div class="flex flex-col lg:flex-row flex-1 overflow-hidden relative p-3 gap-3">

            {{-- ── Overlay: Histórico de sessão ── --}}
            @if ($showSessionHistory)
                <div class="absolute inset-0 z-20 flex flex-col bg-white dark:bg-zinc-900">
                    <div class="shrink-0 px-4 py-3 flex items-center gap-3 border-b dark:border-zinc-700 bg-white dark:bg-zinc-900">
                        <flux:button wire:click="backFromSessionHistory" variant="ghost" icon="arrow-left" size="sm" />
                        <h2 class="text-base font-bold text-neutral-800 dark:text-neutral-100">Pedidos da sessão</h2>
                    </div>
                    <div class="flex-1 overflow-y-auto p-4">
                        @if ($this->sessionOrders->isEmpty())
                            <div class="text-center py-12 text-neutral-400 dark:text-neutral-500">
                                <flux:icon.shopping-bag class="size-10 mx-auto mb-2 opacity-40" />
                                <p class="text-sm">Nenhum pedido nesta sessão ainda.</p>
                            </div>
                        @else
                            <div class="space-y-2">
                                @foreach ($this->sessionOrders as $sessionOrder)
                                    @php
                                        $isCancelled = in_array($sessionOrder->status, ['cancelled', 'refunded']);
                                        $methodLabel = match(strtolower($sessionOrder->payment_method ?? '')) {
                                            'pix' => 'PIX',
                                            'credit_card' => 'Cartão',
                                            'cash' => 'Dinheiro',
                                            default => $sessionOrder->payment_method,
                                        };
                                    @endphp
                                    <div class="bg-white border rounded-xl px-4 py-3 dark:bg-zinc-800 dark:border-zinc-700 {{ $isCancelled ? 'opacity-60' : '' }}">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <span class="font-mono text-sm font-semibold text-amber-500 dark:text-amber-400">{{ $sessionOrder->order_number }}</span>
                                                    <span class="text-xs px-1.5 py-0.5 rounded-full font-medium
                                                        {{ $isCancelled ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' }}">
                                                        {{ $isCancelled ? 'Cancelado' : 'Pago' }}
                                                    </span>
                                                </div>
                                                <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-0.5">
                                                    {{ $sessionOrder->created_at->format('H:i') }}
                                                    · {{ $methodLabel }}
                                                    @if ($sessionOrder->customer && $sessionOrder->customer->phone !== 'pdv-guest')
                                                        · {{ $sessionOrder->customer->name }}
                                                    @endif
                                                </p>
                                                @if (($sessionOrder->discount > 0 || $sessionOrder->manual_discount > 0) && !$isCancelled)
                                                    <p class="text-xs text-green-600 dark:text-green-400 mt-0.5">
                                                        Desconto: R$ {{ number_format($sessionOrder->discount + $sessionOrder->manual_discount, 2, ',', '.') }}
                                                    </p>
                                                @endif
                                            </div>
                                            <div class="text-right shrink-0">
                                                <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100">
                                                    R$ {{ number_format($sessionOrder->total, 2, ',', '.') }}
                                                </p>
                                                @if (!$isCancelled)
                                                    @if ($confirmingCancelSessionOrderId === $sessionOrder->id)
                                                        <div class="mt-1 space-y-1">
                                                            <p class="text-xs text-red-600 dark:text-red-400">Confirmar cancelamento?</p>
                                                            <div class="flex gap-1">
                                                                <flux:button wire:click="$set('confirmingCancelSessionOrderId', null)" variant="ghost" size="sm" class="text-xs px-2 py-0.5">Não</flux:button>
                                                                <flux:button wire:click="cancelPdvOrder({{ $sessionOrder->id }})" variant="danger" size="sm" class="text-xs px-2 py-0.5">Sim</flux:button>
                                                            </div>
                                                        </div>
                                                    @else
                                                        <button
                                                            wire:click="$set('confirmingCancelSessionOrderId', {{ $sessionOrder->id }})"
                                                            class="text-xs text-red-400 hover:text-red-600 mt-1"
                                                        >
                                                            Cancelar
                                                        </button>
                                                    @endif
                                                @endif
                                            </div>
                                        </div>
                                        @error('cancel')
                                            <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endif


            {{-- ── Categorias: chips horizontais no mobile, coluna fixa no desktop ── --}}
            <div class="flex w-full lg:w-48 shrink-0 flex-col rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                <div class="hidden lg:block px-3 py-3 border-b border-neutral-100 dark:border-zinc-800 shrink-0">
                    <p class="text-[11px] font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider">Categorias</p>
                </div>
                <div class="flex flex-row lg:flex-col gap-1.5 lg:gap-1 overflow-x-auto lg:overflow-x-visible lg:overflow-y-auto p-2 lg:flex-1">
                    <button
                        wire:click="selectCategory(null)"
                        class="shrink-0 lg:w-full lg:shrink text-left px-3 py-2 lg:py-2.5 rounded-lg text-sm whitespace-nowrap transition-colors {{ $activeCategoryId === null ? 'bg-amber-500 text-white font-bold shadow-sm shadow-amber-500/20 dark:bg-amber-400 dark:text-white' : 'bg-neutral-50 lg:bg-transparent text-neutral-600 hover:bg-amber-50 hover:text-amber-700 dark:bg-zinc-800 dark:lg:bg-transparent dark:text-neutral-300 dark:hover:bg-amber-900/20 dark:hover:text-amber-300' }}"
                    >
                        Todos
                    </button>
                    @foreach ($this->categories as $category)
                        <button
                            wire:click="selectCategory({{ $category->id }})"
                            class="shrink-0 lg:w-full lg:shrink text-left px-3 py-2 lg:py-2.5 rounded-lg text-sm whitespace-nowrap transition-colors {{ $activeCategoryId === $category->id ? 'bg-amber-500 text-white font-bold shadow-sm shadow-amber-500/20 dark:bg-amber-400 dark:text-white' : 'bg-neutral-50 lg:bg-transparent text-neutral-600 hover:bg-amber-50 hover:text-amber-700 dark:bg-zinc-800 dark:lg:bg-transparent dark:text-neutral-300 dark:hover:bg-amber-900/20 dark:hover:text-amber-300' }}"
                        >
                            {{ $category->name }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- ── Coluna central: Produtos ── --}}
            <div class="flex flex-col flex-1 min-h-0 min-w-0 overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 relative">

                {{-- Busca e código de barras --}}
                <div class="px-4 py-3 shrink-0 border-b border-neutral-100 bg-white dark:bg-zinc-900 dark:border-zinc-800">
                    <div class="flex flex-col lg:flex-row gap-2">
                        <flux:input
                            id="pdv-product-search"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Buscar produto por nome..."
                            icon="magnifying-glass"
                            class="flex-1"
                        />
                    
                    </div>
                </div>

                {{-- Grid de produtos --}}
                <div class="flex-1 overflow-y-auto p-4 {{ !empty($cart) ? 'pb-24 lg:pb-4' : '' }} bg-zinc-50 dark:bg-[#0f1926]/50">
                    @if ($this->products->isEmpty())
                        <div class="text-center py-12 text-neutral-400 dark:text-neutral-500">
                            <flux:icon.shopping-bag class="size-10 mx-auto mb-2 opacity-40" />
                            <p class="text-sm">Nenhum produto disponível</p>
                        </div>
                    @else
                        {{-- Mobile: tabela --}}
                        <div class="md:hidden overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                            <table class="w-full table-fixed text-sm">
                                <thead class="bg-neutral-50 text-left text-[11px] uppercase tracking-wider text-neutral-400 dark:bg-zinc-800/60 dark:text-neutral-500">
                                    <tr>
                                        <th class="w-20 px-3 py-2 font-semibold"></th>
                                        <th class="px-1 py-2 font-semibold">Produto</th>
                                        <th class="w-28 px-3 py-2 font-semibold text-right">Qtd</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-100 dark:divide-zinc-800">
                                    @foreach ($this->products as $product)
                                        @php
                                            $productData = $this->buildProductDataForSidebar($product);
                                            $hasOptions = $productData !== null;
                                            $pdvCartQty = 0;
                                            foreach ($cart as $__key => $__item) {
                                                $__pid = (int) ($__item['product_id'] ?? (int) explode('_', (string) $__key)[0]);
                                                if ($__pid === $product->id) {
                                                    $pdvCartQty += $__item['qty'];
                                                }
                                            }
                                            $stockQty = $this->productStocks[$product->id] ?? null;
                                            $stockOut = $stockQty !== null && $pdvCartQty >= $stockQty;
                                        @endphp
                                        <tr>
                                            <td class="py-3 pl-3 pr-0">
                                                <button
                                                    type="button"
                                                    @if (!$stockOut)
                                                        @if ($hasOptions)
                                                            @click="addOrOpenOptionSelector(@js($productData), $wire)"
                                                        @else
                                                            wire:click="addProduct({{ $product->id }})"
                                                        @endif
                                                    @endif
                                                    {{ $stockOut ? 'disabled' : '' }}
                                                    class="relative block shrink-0 disabled:cursor-not-allowed"
                                                >
                                                    @if ($product->image_path)
                                                        <img
                                                            src="{{ $product->image_url }}"
                                                            alt="{{ $product->name }}"
                                                            class="size-16 rounded-lg object-cover bg-neutral-100 dark:bg-zinc-800"
                                                        />
                                                    @else
                                                        <div class="size-16 rounded-lg bg-neutral-100 flex items-center justify-center dark:bg-zinc-800">
                                                            <flux:icon.shopping-bag class="size-7 text-neutral-300 dark:text-zinc-500" />
                                                        </div>
                                                    @endif
                                                    @if ($pdvCartQty > 0)
                                                        <span class="absolute -top-1 -right-1 min-w-[1.25rem] h-5 px-1 rounded-full bg-amber-500 text-white text-[11px] font-bold flex items-center justify-center shadow ring-2 ring-white dark:ring-zinc-900">
                                                            {{ $pdvCartQty }}
                                                        </span>
                                                    @endif
                                                </button>
                                            </td>
                                            <td class="px-1 py-3">
                                                <button
                                                    type="button"
                                                    @if (!$stockOut)
                                                        @if ($hasOptions)
                                                            @click="addOrOpenOptionSelector(@js($productData), $wire)"
                                                        @else
                                                            wire:click="addProduct({{ $product->id }})"
                                                        @endif
                                                    @endif
                                                    {{ $stockOut ? 'disabled' : '' }}
                                                    class="text-left w-full disabled:cursor-not-allowed"
                                                >
                                                    <span class="block font-medium leading-snug text-neutral-800 dark:text-neutral-100">
                                                        {{ $product->name }}
                                                    </span>
                                                    <span class="block font-semibold text-amber-600 dark:text-amber-400">
                                                        R$ {{ number_format($product->effective_price, 2, ',', '.') }}
                                                    </span>
                                                    @if ($stockOut)
                                                        <span class="block text-[11px] font-semibold text-red-600 dark:text-red-400">Sem estoque</span>
                                                    @elseif ($stockQty !== null && $stockQty <= 5)
                                                        <span class="block text-[11px] font-semibold text-amber-600 dark:text-amber-400">Restam {{ $stockQty }}</span>
                                                    @endif
                                                </button>
                                            </td>
                                            <td class="px-3 py-3">
                                                <div class="flex items-center justify-end gap-1.5">
                                                    @if ($pdvCartQty > 0)
                                                        <button
                                                            @if ($hasOptions)
                                                                wire:click.stop="decrementProductFromCart({{ $product->id }})"
                                                            @else
                                                                wire:click.stop="updateCartQty('{{ $product->id }}', {{ $pdvCartQty - 1 }})"
                                                            @endif
                                                            class="size-7 rounded-full border flex items-center justify-center text-neutral-500 hover:bg-red-50 hover:text-red-600 hover:border-red-300 transition-colors dark:border-zinc-600"
                                                        >
                                                            <span class="text-sm font-bold leading-none">−</span>
                                                        </button>
                                                        <span class="w-5 text-center text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $pdvCartQty }}</span>
                                                    @endif
                                                    <button
                                                        @if (!$stockOut)
                                                            @if ($hasOptions)
                                                                @click.stop="addOrOpenOptionSelector(@js($productData), $wire)"
                                                            @else
                                                                wire:click.stop="addProduct({{ $product->id }})"
                                                            @endif
                                                        @endif
                                                        {{ $stockOut ? 'disabled' : '' }}
                                                        class="size-7 rounded-full text-white flex items-center justify-center transition-colors {{ $stockOut ? 'bg-neutral-300 cursor-not-allowed dark:bg-zinc-600' : 'bg-amber-500 hover:bg-amber-600 active:scale-90' }}"
                                                    >
                                                        <span class="text-sm font-bold leading-none">+</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Desktop/tablet: cards --}}
                        <div class="hidden md:grid grid-cols-4 gap-2">
                            @foreach ($this->products as $product)
                                @php
                                    $productData = $this->buildProductDataForSidebar($product);
                                    $hasOptions = $productData !== null;
                                    $pdvCartQty = 0;
                                    foreach ($cart as $__key => $__item) {
                                        $__pid = (int) ($__item['product_id'] ?? (int) explode('_', (string) $__key)[0]);
                                        if ($__pid === $product->id) {
                                            $pdvCartQty += $__item['qty'];
                                        }
                                    }
                                    $stockQty = $this->productStocks[$product->id] ?? null;
                                    $stockOut = $stockQty !== null && $pdvCartQty >= $stockQty;
                                @endphp
                                <div class="flex flex-col overflow-hidden rounded-xl border bg-white dark:bg-zinc-900 {{ $pdvCartQty > 0 ? 'border-amber-400 ring-1 ring-amber-400 dark:border-amber-500 dark:ring-amber-500' : 'border-neutral-200 dark:border-zinc-800' }}">
                                    <button
                                        type="button"
                                        @if (!$stockOut)
                                            @if ($hasOptions)
                                                @click="addOrOpenOptionSelector(@js($productData), $wire)"
                                            @else
                                                wire:click="addProduct({{ $product->id }})"
                                            @endif
                                        @endif
                                        {{ $stockOut ? 'disabled' : '' }}
                                        class="relative block w-full disabled:cursor-not-allowed"
                                    >
                                        @if ($product->image_path)
                                            <img
                                                src="{{ $product->image_url }}"
                                                alt="{{ $product->name }}"
                                                class="aspect-square w-full object-cover bg-neutral-100 dark:bg-zinc-800"
                                            />
                                        @else
                                            <div class="aspect-square w-full bg-neutral-100 flex items-center justify-center dark:bg-zinc-800">
                                                <flux:icon.shopping-bag class="size-6 text-neutral-300 dark:text-zinc-500" />
                                            </div>
                                        @endif
                                        @if ($pdvCartQty > 0)
                                            <span class="absolute top-1 right-1 min-w-[1.25rem] h-5 px-1 rounded-full bg-amber-500 text-white text-[11px] font-bold flex items-center justify-center shadow ring-2 ring-white dark:ring-zinc-900">
                                                {{ $pdvCartQty }}
                                            </span>
                                        @endif
                                    </button>
                                    <div class="flex flex-1 flex-col p-2">
                                        <button
                                            type="button"
                                            @if (!$stockOut)
                                                @if ($hasOptions)
                                                    @click="addOrOpenOptionSelector(@js($productData), $wire)"
                                                @else
                                                    wire:click="addProduct({{ $product->id }})"
                                                @endif
                                            @endif
                                            {{ $stockOut ? 'disabled' : '' }}
                                            class="text-left w-full flex-1 disabled:cursor-not-allowed"
                                        >
                                            <span class="block text-xs font-medium leading-snug text-neutral-800 dark:text-neutral-100 line-clamp-2">
                                                {{ $product->name }}
                                            </span>
                                            <span class="block text-xs font-semibold text-amber-600 dark:text-amber-400">
                                                R$ {{ number_format($product->effective_price, 2, ',', '.') }}
                                            </span>
                                            @if ($stockOut)
                                                <span class="block text-[10px] font-semibold text-red-600 dark:text-red-400">Sem estoque</span>
                                            @elseif ($stockQty !== null && $stockQty <= 5)
                                                <span class="block text-[10px] font-semibold text-amber-600 dark:text-amber-400">Restam {{ $stockQty }}</span>
                                            @endif
                                        </button>
                                        <div class="flex items-center justify-end gap-1 mt-1.5">
                                            @if ($pdvCartQty > 0)
                                                <button
                                                    @if ($hasOptions)
                                                        wire:click.stop="decrementProductFromCart({{ $product->id }})"
                                                    @else
                                                        wire:click.stop="updateCartQty('{{ $product->id }}', {{ $pdvCartQty - 1 }})"
                                                    @endif
                                                    class="size-6 rounded-full border flex items-center justify-center text-neutral-500 hover:bg-red-50 hover:text-red-600 hover:border-red-300 transition-colors dark:border-zinc-600"
                                                >
                                                    <span class="text-xs font-bold leading-none">−</span>
                                                </button>
                                                <span class="w-4 text-center text-xs font-semibold text-neutral-800 dark:text-neutral-100">{{ $pdvCartQty }}</span>
                                            @endif
                                            <button
                                                @if (!$stockOut)
                                                    @if ($hasOptions)
                                                        @click.stop="addOrOpenOptionSelector(@js($productData), $wire)"
                                                    @else
                                                        wire:click.stop="addProduct({{ $product->id }})"
                                                    @endif
                                                @endif
                                                {{ $stockOut ? 'disabled' : '' }}
                                                class="size-6 rounded-full text-white flex items-center justify-center transition-colors {{ $stockOut ? 'bg-neutral-300 cursor-not-allowed dark:bg-zinc-600' : 'bg-amber-500 hover:bg-amber-600 active:scale-90' }}"
                                            >
                                                <span class="text-xs font-bold leading-none">+</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @error('stock')
                        <div class="mt-3 flex items-center gap-2 bg-red-50 border border-red-200 text-red-700 rounded-xl px-3 py-2 text-sm dark:bg-red-900/20 dark:border-red-700 dark:text-red-300">
                            <flux:icon.exclamation-triangle class="size-4 shrink-0" />
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                {{-- ── Painel de seleção de opções (overlay) ── --}}
                <div
                    x-show="selectingProduct !== null"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="translate-x-full opacity-0"
                    x-transition:enter-end="translate-x-0 opacity-100"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="translate-x-0 opacity-100"
                    x-transition:leave-end="translate-x-full opacity-0"
                    class="absolute inset-0 z-10 bg-white dark:bg-zinc-900 flex flex-col overflow-hidden"
                    style="display:none"
                >
                    <div class="shrink-0 px-4 py-3 flex items-center gap-3 border-b bg-amber-500 dark:border-zinc-700">
                        <button
                            @click="selectingProduct = null; pendingSelections = {}"
                            class="text-white/70 hover:text-white p-1 rounded-lg hover:bg-white/10 transition-colors"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                            </svg>
                        </button>
                        <div class="flex-1 min-w-0">
                            <p class="text-white font-bold text-sm truncate" x-text="selectingProduct?.name"></p>
                            <p class="text-white/70 text-xs">
                                R$ <span x-text="getTotalWithOptions().toFixed(2).replace('.', ',')"></span>
                            </p>
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto px-4 py-4 space-y-5">
                        <template x-if="selectingProduct">
                            <div class="space-y-5">
                                <template x-for="group in selectingProduct.groups" :key="group.id">
                                    <div>
                                        <div class="flex items-center justify-between mb-3">
                                            <div class="flex items-center gap-2.5 min-w-0">
                                                <template x-if="group.image_url">
                                                    <img :src="group.image_url" class="w-10 h-10 rounded-lg object-cover shrink-0" />
                                                </template>
                                                <div>
                                                    <p class="font-bold text-sm text-neutral-800 dark:text-neutral-100" x-text="group.name"></p>
                                                    <p class="text-xs text-neutral-400 mt-0.5">
                                                        <template x-if="group.min_qty > 0">
                                                            <span>Mín. <span class="font-semibold" x-text="group.min_qty"></span> ·</span>
                                                        </template>
                                                        Máximo <span class="font-semibold" x-text="group.total_qty"></span> unidades
                                                    </p>
                                                </div>
                                            </div>
                                            <span class="text-xs font-bold px-2 py-1 rounded-full"
                                                :class="getGroupTotal(group.id) > group.total_qty
                                                    ? 'bg-red-100 text-red-700'
                                                    : getGroupTotal(group.id) >= (group.min_qty || 0)
                                                        ? 'bg-green-100 text-green-700'
                                                        : 'bg-neutral-100 text-neutral-500'">
                                                <span x-text="getGroupTotal(group.id)"></span>/<span x-text="group.total_qty"></span>
                                            </span>
                                        </div>

                                        <div class="space-y-1">
                                            <template x-for="option in group.options" :key="option.id">
                                                <div class="flex items-center gap-3 p-2.5 rounded-xl border transition-colors"
                                                    :class="option.paused
                                                        ? 'border-amber-100 bg-amber-50 dark:border-amber-900/30 dark:bg-amber-900/10'
                                                        : 'border-neutral-100 bg-neutral-50 dark:border-zinc-700 dark:bg-zinc-800'">
                                                    <template x-if="option.image_url">
                                                        <img :src="option.image_url"
                                                             class="w-12 h-12 rounded-xl object-cover shrink-0"
                                                             :class="option.paused ? 'grayscale opacity-50' : ''" />
                                                    </template>
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center gap-1.5 flex-wrap">
                                                            <p class="text-sm font-medium"
                                                                :class="option.paused ? 'text-neutral-400' : 'text-neutral-800 dark:text-neutral-100'"
                                                                x-text="option.name"></p>
                                                            <span x-show="option.paused"
                                                                class="inline-flex items-center gap-0.5 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-600">
                                                                Em pausa
                                                            </span>
                                                        </div>
                                                        <p class="text-xs text-neutral-500 mt-0.5 leading-snug"
                                                            x-show="option.description && !option.paused"
                                                            x-text="option.description"></p>
                                                        <p class="text-xs text-amber-600 mt-0.5"
                                                            x-show="option.additional_price > 0 && !option.paused"
                                                            x-text="'+R$ ' + option.additional_price.toFixed(2).replace('.', ',')"></p>
                                                    </div>
                                                    <div class="flex items-center gap-1.5 shrink-0">
                                                        <template x-if="!option.paused && group.fixed">
                                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300 text-xs font-semibold">
                                                                <span x-text="option.prefilledQty"></span> un.
                                                            </span>
                                                        </template>
                                                        <template x-if="!option.paused && !group.fixed">
                                                            <div class="flex items-center gap-1.5">
                                                                <button
                                                                    @click="if ((pendingSelections[group.id]?.[option.id] || 0) > 0) pendingSelections[group.id][option.id]--"
                                                                    class="w-7 h-7 rounded-full bg-amber-100 text-amber-500 font-bold text-base flex items-center justify-center">−</button>
                                                                <span class="w-7 text-center text-sm font-bold text-neutral-800 dark:text-neutral-100"
                                                                    x-text="pendingSelections[group.id]?.[option.id] || 0"></span>
                                                                <button
                                                                    @click="if (getGroupTotal(group.id) < group.total_qty && (!option.max_qty || (pendingSelections[group.id]?.[option.id] || 0) < option.max_qty)) { if (!pendingSelections[group.id]) pendingSelections[group.id] = {}; pendingSelections[group.id][option.id] = (pendingSelections[group.id]?.[option.id] || 0) + 1 }"
                                                                    :disabled="getGroupTotal(group.id) >= group.total_qty || (option.max_qty && (pendingSelections[group.id]?.[option.id] || 0) >= option.max_qty)"
                                                                    :class="(getGroupTotal(group.id) >= group.total_qty || (option.max_qty && (pendingSelections[group.id]?.[option.id] || 0) >= option.max_qty)) ? 'bg-neutral-200 text-neutral-400 cursor-not-allowed' : 'bg-amber-500 text-white'"
                                                                    class="w-7 h-7 rounded-full font-bold text-base flex items-center justify-center">+</button>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    <div class="shrink-0 border-t border-neutral-100 dark:border-zinc-700 px-4 py-3">
                        <button
                            @click="confirmOptions($wire)"
                            :disabled="!canConfirm()"
                            :class="!canConfirm() ? 'opacity-50 cursor-not-allowed' : ''"
                            class="w-full bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-xl px-4 py-2.5 transition-colors text-sm"
                        >
                            Adicionar ao carrinho
                        </button>
                    </div>
                </div>
            </div>
            {{-- fim coluna central --}}

            {{-- ── Coluna direita: Carrinho / Pagamento / Sucesso / PIX ── --}}
            <div
                class="shrink-0 flex-col rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden lg:flex lg:w-[22rem] xl:w-[24rem]"
                :class="mobileCartOpen ? 'fixed inset-0 z-30 flex' : 'hidden'"
            >

                {{-- ── Sucesso ── --}}
                @if ($step === 'success')
                    <div class="flex flex-col h-full overflow-hidden">
                        <div class="px-4 py-3 border-b border-neutral-100 dark:border-zinc-800 shrink-0 flex items-center gap-2 bg-white dark:bg-zinc-900">
                            <div class="size-7 rounded-full bg-green-100 flex items-center justify-center dark:bg-green-900/40 shrink-0">
                                <flux:icon.check class="size-4 text-green-600 dark:text-green-400" />
                            </div>
                            <h2 class="font-bold text-neutral-800 dark:text-neutral-100">Pedido registrado!</h2>
                        </div>
                        <div class="flex-1 flex flex-col items-center justify-center p-5 space-y-4 overflow-y-auto">
                            <p class="font-mono text-amber-500 dark:text-amber-400 font-semibold text-2xl">{{ $lastOrderNumber }}</p>
                            @if ($lastOrderTotal)
                                <p class="text-sm text-neutral-500 dark:text-neutral-400">
                                    Total: <span class="font-semibold text-neutral-700 dark:text-neutral-200">R$ {{ number_format($lastOrderTotal, 2, ',', '.') }}</span>
                                </p>
                            @endif
                            @if ($changeAmount > 0)
                                <div class="w-full bg-amber-50 border border-amber-200 rounded-xl p-4 dark:bg-amber-900/20 dark:border-amber-700 text-center">
                                    <p class="text-xs text-amber-700 dark:text-amber-300 mb-1">Troco para o cliente</p>
                                    <p class="text-4xl font-bold text-amber-700 dark:text-amber-300">
                                        R$ {{ number_format($changeAmount, 2, ',', '.') }}
                                    </p>
                                </div>
                            @endif
                            <div class="w-full flex gap-2">
                                <flux:button wire:click="resetTerminal" variant="primary" size="base" class="flex-1">
                                    Novo pedido
                                </flux:button>
                                @if ($lastOrderId)
                                    <flux:button
                                        x-data
                                        x-on:click="window.open('{{ route('admin.orders.receipt', ['order' => $lastOrderId]) }}', '_blank')"
                                        variant="outline"
                                        size="base"
                                        icon="printer"
                                        title="Imprimir cupom"
                                    />
                                    <flux:button
                                        x-data
                                        x-on:click="window.open('{{ route('admin.orders.receipt', ['order' => $lastOrderId, 'station' => 'cozinha']) }}', '_blank')"
                                        variant="outline"
                                        size="base"
                                        title="Imprimir cupom cozinha"
                                    >Cozinha</flux:button>
                                    <flux:button
                                        x-data
                                        x-on:click="window.open('{{ route('admin.orders.receipt', ['order' => $lastOrderId, 'station' => 'bar']) }}', '_blank')"
                                        variant="outline"
                                        size="base"
                                        title="Imprimir cupom bar"
                                    >Bar</flux:button>
                                @endif
                            </div>
                            @if ($lastOrderId)
                                @if ($confirmingCancelOrder)
                                    <div class="w-full border border-red-200 rounded-xl p-3 space-y-2 text-sm bg-red-50 dark:bg-red-900/20 dark:border-red-700">
                                        <p class="text-red-700 dark:text-red-300 font-medium">Cancelar pedido {{ $lastOrderNumber }}?</p>
                                        <p class="text-xs text-red-500">Estoque será restaurado. Sem reembolso automático.</p>
                                        <div class="flex gap-2">
                                            <flux:button wire:click="$set('confirmingCancelOrder', false)" variant="ghost" size="sm">Não</flux:button>
                                            <flux:button wire:click="cancelLastOrder" variant="danger" size="sm" class="flex-1">Sim, cancelar</flux:button>
                                        </div>
                                    </div>
                                    @error('cancel') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                @else
                                    <flux:button wire:click="$set('confirmingCancelOrder', true)" variant="ghost" size="sm" class="w-full text-red-500 hover:text-red-700">
                                        Cancelar este pedido
                                    </flux:button>
                                @endif
                            @endif
                        </div>
                    </div>

                {{-- ── Carrinho ── --}}
                @else
                    <div class="flex flex-col h-full overflow-hidden">
                        <div class="px-4 py-3 border-b border-neutral-100 dark:border-zinc-800 shrink-0 bg-white dark:bg-zinc-900">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    <button
                                        @click="mobileCartOpen = false"
                                        class="lg:hidden shrink-0 p-1.5 -ml-1.5 rounded-lg text-neutral-500 hover:bg-neutral-100 dark:hover:bg-zinc-800 dark:text-neutral-400"
                                        title="Voltar ao catálogo"
                                    >
                                        <flux:icon.chevron-left class="size-5" />
                                    </button>
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-bold text-neutral-400 dark:text-neutral-500 uppercase tracking-wider">Pedido atual</p>
                                        <h2 class="font-bold text-neutral-900 dark:text-neutral-100 truncate">
                                            Carrinho
                                            @if ($this->cartCount > 0)
                                                <span class="ml-1 text-xs font-normal text-neutral-500">({{ $this->cartCount }} itens)</span>
                                            @endif
                                        </h2>
                                    </div>
                                </div>
                                @if ($this->cartCount > 0)
                                    <span class="inline-flex size-9 items-center justify-center rounded-lg bg-amber-500 text-sm font-bold text-white shadow-sm shadow-amber-500/20 dark:bg-amber-400 dark:text-white shrink-0">
                                        {{ $this->cartCount }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        {{-- Região do meio rola por dentro (overflow-y-auto) e o footer fica FORA
                             dela — garantia estrutural de que o footer nunca some/desloca por
                             causa de redimensionamento, sem depender de conta de altura. --}}
                        <div class="flex-1 min-h-0 overflow-y-auto flex flex-col">

                        <div class="px-4 pt-2.5 pb-1 shrink-0 bg-white dark:bg-zinc-900">
                            <p class="text-[10px] font-bold text-neutral-400 dark:text-neutral-500 uppercase tracking-wider">Itens do pedido</p>
                        </div>
                        <div x-ref="itemsSection" class="flex-1 overflow-y-auto divide-y divide-neutral-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                            @forelse ($cart as $cartKey => $item)
                                @php
                                    $cartItemOptionsExtra = 0.0;
                                    foreach ($item['options'] ?? [] as $group) {
                                        foreach ($group['selections'] ?? [] as $sel) {
                                            $cartItemOptionsExtra += ($sel['qty'] ?? 0) * ($sel['additional_price'] ?? 0);
                                        }
                                    }
                                    $cartItemUnitPrice = (float) $item['price'] + $cartItemOptionsExtra;
                                @endphp
                                <div class="px-3 py-2.5 hover:bg-neutral-50 dark:hover:bg-zinc-800/60 transition-colors flex gap-2.5">
                                    @if (!empty($item['image_url']))
                                        <img
                                            src="{{ $item['image_url'] }}"
                                            alt="{{ $item['name'] }}"
                                            class="size-11 shrink-0 rounded-lg object-cover bg-neutral-100 dark:bg-zinc-800"
                                        />
                                    @endif
                                    <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-neutral-800 dark:text-neutral-100 leading-snug mb-1">
                                        {{ $item['name'] }}
                                    </p>
                                    @if (!empty($item['options']))
                                        @foreach ($item['options'] as $group)
                                            <p class="text-xs font-medium text-neutral-500 mt-0.5">{{ $group['group_name'] }}:</p>
                                            @foreach ($group['selections'] as $sel)
                                                <p class="text-xs text-neutral-400 leading-tight">
                                                    {{ $sel['qty'] }}× {{ $sel['name'] }}@if (($sel['additional_price'] ?? 0) > 0) <span class="text-amber-500">(+R$ {{ number_format($sel['additional_price'], 2, ',', '.') }})</span>@endif
                                                </p>
                                            @endforeach
                                        @endforeach
                                    @else
                                        <p class="text-xs text-neutral-500 dark:text-neutral-400 mb-1.5">
                                            R$ {{ number_format($cartItemUnitPrice, 2, ',', '.') }} cada
                                        </p>
                                    @endif
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="flex items-center gap-1 shrink-0">
                                            <button
                                                wire:click="updateCartQty('{{ $cartKey }}', {{ $item['qty'] - 1 }})"
                                                class="size-11 rounded-full border flex items-center justify-center text-neutral-500 hover:bg-red-50 hover:text-red-600 hover:border-red-300 active:scale-90 transition-colors dark:border-zinc-600"
                                            >
                                                <span class="text-lg font-bold leading-none">−</span>
                                            </button>
                                            <span class="w-6 text-center text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                                                {{ $item['qty'] }}
                                            </span>
                                            <button
                                                wire:click="updateCartQty('{{ $cartKey }}', {{ $item['qty'] + 1 }})"
                                                class="size-11 rounded-full border flex items-center justify-center text-neutral-500 hover:bg-green-50 hover:text-green-600 hover:border-green-300 active:scale-90 transition-colors dark:border-zinc-600"
                                            >
                                                <span class="text-lg font-bold leading-none">+</span>
                                            </button>
                                        </div>
                                        <p class="text-right text-sm font-semibold text-neutral-800 dark:text-neutral-100 shrink-0">
                                            R$ {{ number_format($cartItemUnitPrice * $item['qty'], 2, ',', '.') }}
                                        </p>
                                    </div>
                                    </div>
                                </div>
                            @empty
                                <div class="flex-1 flex items-center justify-center py-16 text-neutral-400 dark:text-neutral-500 bg-zinc-50 dark:bg-[#0f1926]/40">
                                    <div class="text-center">
                                        <flux:icon.shopping-cart class="size-10 mx-auto mb-2 opacity-40" />
                                        <p class="text-sm">Carrinho vazio</p>
                                        <p class="text-xs mt-1 text-neutral-300 dark:text-neutral-600">Clique em + para adicionar</p>
                                    </div>
                                </div>
                            @endforelse
                        </div>
                        {{-- Alça de arrasto (QA): puxa pra redimensionar a lista de itens acima --}}
                        <div
                            class="h-2.5 shrink-0 cursor-row-resize flex items-center justify-center bg-neutral-200/70 hover:bg-amber-400 dark:bg-zinc-700 dark:hover:bg-amber-500 transition-colors touch-none"
                            @pointerdown="startResize($refs.itemsSection, $event)"
                            title="Arraste para redimensionar"
                        >
                            <div class="w-8 h-1 rounded-full bg-neutral-400/70 dark:bg-neutral-500"></div>
                        </div>
                        </div>
                        {{-- fim da região rolável do meio --}}

                        @if (!empty($cart))
                            <div class="border-t border-neutral-100 px-4 py-4 space-y-3 bg-zinc-50 dark:border-zinc-800 dark:bg-[#0f1926]/70 shrink-0">
                                <div class="space-y-1 text-sm">
                                    <div class="flex justify-between items-center text-neutral-500 dark:text-neutral-400">
                                        <span>Subtotal</span>
                                        <span>R$ {{ number_format($this->cartTotal, 2, ',', '.') }}</span>
                                    </div>
                                    @if ($this->serviceFeeAmount > 0)
                                        <div class="flex justify-between items-center text-neutral-500 dark:text-neutral-400">
                                            <span>Taxa de serviço</span>
                                            <span>+ R$ {{ number_format($this->serviceFeeAmount, 2, ',', '.') }}</span>
                                        </div>
                                    @endif
                                    @if ($this->couvertFeeAmount > 0)
                                        <div class="flex justify-between items-center text-neutral-500 dark:text-neutral-400">
                                            <span>Couvert artístico</span>
                                            <span>+ R$ {{ number_format($this->couvertFeeAmount, 2, ',', '.') }}</span>
                                        </div>
                                    @endif
                                    @if ($manualDiscountAmount > 0)
                                        <div class="flex justify-between items-center text-green-600 dark:text-green-400">
                                            <span>Desc. manual</span>
                                            <span>− R$ {{ number_format($manualDiscountAmount, 2, ',', '.') }}</span>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex justify-between items-end rounded-xl bg-white border border-neutral-200 px-3 py-3 dark:bg-zinc-900 dark:border-zinc-800">
                                    <span class="text-sm font-semibold text-neutral-500 dark:text-neutral-400">Total</span>
                                    <span class="text-2xl font-black text-neutral-900 dark:text-neutral-100">
                                        R$ {{ number_format($this->cartTotalAfterDiscount, 2, ',', '.') }}
                                    </span>
                                </div>
                                <flux:button wire:click="proceedToPayment" variant="primary" size="base" class="w-full">
                                    Ir para pagamento
                                </flux:button>
                                <flux:button wire:click="clearCart" variant="ghost" size="sm" class="w-full text-red-500 hover:text-red-700">
                                    Limpar carrinho
                                </flux:button>
                            </div>
                        @endif
                    </div>
                @endif
                {{-- fim painel direito --}}

            </div>

            {{-- ── Barra flutuante do carrinho (mobile) ── --}}
            @if ($step !== 'payment' && !empty($cart))
                <div
                    x-show="!mobileCartOpen"
                    class="lg:hidden fixed inset-x-3 bottom-3 z-20 flex items-center justify-between gap-3 rounded-xl bg-amber-500 px-4 py-3 text-white shadow-lg shadow-amber-500/30 dark:bg-amber-400"
                >
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-white/80">{{ $this->cartCount }} {{ $this->cartCount === 1 ? 'item' : 'itens' }}</p>
                        <p class="text-lg font-black leading-none">R$ {{ number_format($this->cartTotalAfterDiscount, 2, ',', '.') }}</p>
                    </div>
                    <button
                        @click="mobileCartOpen = true"
                        class="shrink-0 inline-flex items-center gap-2 rounded-lg bg-white/15 px-4 py-2.5 text-sm font-bold hover:bg-white/25 transition-colors"
                    >
                        <flux:icon.shopping-cart class="size-4" />
                        Ver carrinho
                    </button>
                </div>
            @endif

            @if ($step === 'payment')
                <div class="absolute inset-0 z-30 flex items-center justify-center bg-amber-950/45 p-3 lg:p-6">
                    <div class="flex max-h-full w-full max-w-6xl flex-col overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="shrink-0 border-b border-neutral-100 px-4 py-3 dark:border-zinc-800">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    <flux:button wire:click="backToCatalog" variant="ghost" icon="arrow-left" size="sm" />
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-bold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">Checkout</p>
                                        <h2 class="text-lg font-black text-neutral-900 dark:text-neutral-100">
                                            Finalizar pedido
                                        </h2>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">Total</p>
                                    <p class="text-xl font-black text-neutral-900 dark:text-neutral-100">
                                        R$ {{ number_format($this->cartTotalAfterDiscount, 2, ',', '.') }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="grid min-h-0 flex-1 grid-cols-1 overflow-y-auto xl:grid-cols-[minmax(0,1fr)_22rem]">
                            <div class="space-y-5 p-4 lg:p-5">
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="space-y-1.5">
                                        <flux:label class="text-xs font-semibold">Tipo de pedido</flux:label>
                                        <flux:radio.group wire:model.live="deliveryType" variant="segmented" class="w-full">
                                            <flux:radio value="balcao" label="Balcão" />
                                            <flux:radio value="entrega" label="Entrega" />
                                        </flux:radio.group>
                                    </div>

                                    @if ($manualDiscountAllowed)
                                        <div class="space-y-1.5">
                                            <flux:label class="text-xs font-semibold">Desconto manual (opcional)</flux:label>
                                            @if ($manualDiscountAmount > 0)
                                                <div class="flex items-center justify-between px-3 py-2 bg-green-50 border border-green-200 rounded-xl dark:bg-green-900/20 dark:border-green-700">
                                                    <span class="text-sm font-semibold text-green-700 dark:text-green-300">
                                                        - R$ {{ number_format($manualDiscountAmount, 2, ',', '.') }}
                                                    </span>
                                                    <button wire:click="removeManualDiscount" class="text-xs text-red-500 hover:text-red-700 ml-2">Remover</button>
                                                </div>
                                            @else
                                                <div class="flex gap-2">
                                                    <flux:select wire:model.live="manualDiscountType" class="w-16 shrink-0">
                                                        <flux:select.option value="fixed">R$</flux:select.option>
                                                        <flux:select.option value="percent">%</flux:select.option>
                                                    </flux:select>
                                                    <flux:input
                                                        wire:model.live.debounce.500ms="manualDiscountInput"
                                                        placeholder="{{ $manualDiscountType === 'percent' ? '10' : '5,00' }}"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        class="flex-1"
                                                    />
                                                </div>
                                                @error('manual_discount')
                                                    <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                                @enderror
                                            @endif
                                        </div>
                                    @endif
                                </div>

                                @if ($this->schedulingEnabled)
                                    <div class="space-y-3 rounded-xl border p-3 dark:border-zinc-700">
                                        <label class="flex cursor-pointer items-center gap-2">
                                            <flux:checkbox wire:model.live="isScheduled" />
                                            <span class="text-sm font-semibold">Agendar pedido para depois</span>
                                        </label>

                                        @if ($isScheduled)
                                            <div class="flex flex-wrap gap-1.5">
                                                <flux:button size="xs" variant="{{ $scheduleDate === now()->format('Y-m-d') ? 'primary' : 'ghost' }}" wire:click="$set('scheduleDate', '{{ now()->format('Y-m-d') }}')">Hoje</flux:button>
                                                <flux:button size="xs" variant="{{ $scheduleDate === now()->addDay()->format('Y-m-d') ? 'primary' : 'ghost' }}" wire:click="$set('scheduleDate', '{{ now()->addDay()->format('Y-m-d') }}')">Amanhã</flux:button>
                                            </div>

                                            <div class="space-y-1.5">
                                                <flux:label class="text-xs font-semibold">Data</flux:label>
                                                <flux:input type="date" wire:model.live="scheduleDate" min="{{ now()->format('Y-m-d') }}" />
                                            </div>

                                            @if ($scheduleDate)
                                                <div class="space-y-1.5">
                                                    <flux:label class="text-xs font-semibold">Horário</flux:label>
                                                    @if (count($this->availableScheduleTimeSlots) > 0)
                                                        <div class="flex flex-wrap gap-1.5">
                                                            @foreach ($this->availableScheduleTimeSlots as $slot)
                                                                <flux:button
                                                                    size="xs"
                                                                    variant="{{ $scheduleTime === $slot ? 'primary' : 'ghost' }}"
                                                                    wire:click="$set('scheduleTime', '{{ $slot }}')"
                                                                >
                                                                    {{ $slot }}
                                                                </flux:button>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        <p class="text-xs text-neutral-500 dark:text-neutral-400">Sem horários disponíveis nesta data.</p>
                                                    @endif
                                                </div>
                                            @endif

                                            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                                Selecione um cliente cadastrado abaixo para vincular ao pedido agendado.
                                            </p>
                                            @error('scheduledAt')
                                                <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                            @enderror
                                        @endif
                                    </div>
                                @endif

                                @if ($this->rawServiceFeeAmount > 0 || $this->rawCouvertFeeAmount > 0)
                                    <div class="grid gap-2 md:grid-cols-2">
                                        @if ($this->rawServiceFeeAmount > 0)
                                            <label class="flex items-center justify-between gap-3 px-3 py-2 border rounded-xl dark:border-zinc-700 cursor-pointer">
                                                <span class="flex items-center gap-2">
                                                    <flux:checkbox wire:model.live="serviceFeeWaived" />
                                                    <span class="text-sm">Remover taxa de serviço</span>
                                                </span>
                                                <span class="text-xs font-semibold {{ $serviceFeeWaived ? 'text-neutral-400 line-through' : 'text-neutral-600 dark:text-neutral-300' }}">
                                                    R$ {{ number_format($this->rawServiceFeeAmount, 2, ',', '.') }}
                                                </span>
                                            </label>
                                        @endif
                                        @if ($this->rawCouvertFeeAmount > 0)
                                            <label class="flex items-center justify-between gap-3 px-3 py-2 border rounded-xl dark:border-zinc-700 cursor-pointer">
                                                <span class="flex items-center gap-2">
                                                    <flux:checkbox wire:model.live="couvertFeeWaived" />
                                                    <span class="text-sm">Remover couvert artístico</span>
                                                </span>
                                                <span class="text-xs font-semibold {{ $couvertFeeWaived ? 'text-neutral-400 line-through' : 'text-neutral-600 dark:text-neutral-300' }}">
                                                    R$ {{ number_format($this->rawCouvertFeeAmount, 2, ',', '.') }}
                                                </span>
                                            </label>
                                        @endif
                                    </div>
                                @endif

                                @if ($deliveryType === 'entrega')
                                    <div class="space-y-1.5">
                                        @include('livewire.admin.pdv._customer-search')
                                    </div>
                                @endif

                                @if ($deliveryType === 'entrega')
                                    <div class="space-y-3 border rounded-xl p-3 dark:border-zinc-700"
                                         x-data="{
                                             cepLoading: false,
                                             formatCep(v) {
                                                 v = v.replace(/\D/g, '').slice(0, 8);
                                                 return v.length > 5 ? v.slice(0, 5) + '-' + v.slice(5) : v;
                                             },
                                             async fetchCep(val) {
                                                 const digits = val.replace(/\D/g, '');
                                                 if (digits.length !== 8) return;
                                                 this.cepLoading = true;
                                                 try {
                                                     const res = await fetch('https://viacep.com.br/ws/' + digits + '/json/');
                                                     const d = await res.json();
                                                     if (!d.erro) {
                                                         if (d.logradouro) $wire.set('deliveryAddress', d.logradouro);
                                                         if (d.bairro)     $wire.set('deliveryNeighborhood', d.bairro);
                                                         if (d.localidade) $wire.set('deliveryCity', d.localidade);
                                                     }
                                                 } catch(e) {}
                                                 this.cepLoading = false;
                                             }
                                         }">
                                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                            <div class="relative">
                                                <flux:input wire:model.live.debounce.500ms="deliveryCep" placeholder="CEP" maxlength="9"
                                                    x-on:input="
                                                        $event.target.value = formatCep($event.target.value);
                                                        if ($event.target.value.replace(/\D/g,'').length === 8) fetchCep($event.target.value);
                                                    " />
                                                <span x-show="cepLoading"
                                                      class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-neutral-400">
                                                    Buscando...
                                                </span>
                                            </div>
                                            <flux:input wire:model.live.debounce.500ms="deliveryAddress" placeholder="Endereço" class="col-span-2 sm:col-span-3" />
                                            <flux:input wire:model.live.debounce.500ms="deliveryNumber" placeholder="Número" />
                                            <flux:input wire:model="deliveryComplement" placeholder="Complemento" />
                                            <flux:input wire:model.live.debounce.500ms="deliveryNeighborhood" placeholder="Bairro" />
                                            <flux:input wire:model.live.debounce.500ms="deliveryCity" placeholder="Cidade" />
                                        </div>
                                        @if ($deliveryFeeAmount > 0)
                                            <p class="text-sm font-semibold text-green-600 dark:text-green-400">Taxa de entrega: R$ {{ number_format($deliveryFeeAmount, 2, ',', '.') }}</p>
                                        @endif
                                        @if ($deliveryFeeError)
                                            <p class="text-xs text-red-600 dark:text-red-400">{{ $deliveryFeeError }}</p>
                                        @endif
                                    </div>
                                @endif

                                <div class="grid gap-4 md:grid-cols-2">
                                    @if ($deliveryType === 'entrega')
                                        <div class="space-y-1.5">
                                            <flux:label class="text-xs font-semibold">Pagamento na entrega</flux:label>
                                            <flux:radio.group wire:model.live="deliveryPaymentStatus" variant="segmented" class="w-full">
                                                <flux:radio value="paid" label="Já está pago" />
                                                <flux:radio value="on_delivery" label="Receber na entrega" />
                                            </flux:radio.group>
                                        </div>
                                    @else
                                        <div class="space-y-1.5">
                                            @include('livewire.admin.pdv._customer-search')
                                        </div>
                                    @endif

                                    <div class="space-y-1.5">
                                        <flux:label class="text-xs font-semibold">Método de pagamento</flux:label>
                                        <flux:radio.group wire:model.live="paymentMethod" variant="segmented" class="w-full">
                                            <flux:radio value="cash" label="Dinheiro" />
                                            <flux:radio value="pix" label="PIX" />
                                            <flux:radio value="credit_card" label="Cartão" />
                                        </flux:radio.group>

                                        @if ($paymentMethod === 'cash')
                                            <div class="pt-3 space-y-1.5">
                                                <flux:label class="text-xs font-semibold">Valor recebido</flux:label>
                                                <flux:input
                                                    wire:model="cashReceivedInput"
                                                    placeholder="Em branco = valor exato"
                                                    type="number"
                                                    step="0.01"
                                                    min="{{ $this->cartTotalAfterDiscount }}"
                                                />
                                                @if (filled($cashReceivedInput) && (float) str_replace(',', '.', $cashReceivedInput) >= $this->cartTotalAfterDiscount)
                                                    @php $changePreview = max(0, (float) str_replace(',', '.', $cashReceivedInput) - $this->cartTotalAfterDiscount); @endphp
                                                    <div class="bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 dark:bg-amber-900/20 dark:border-amber-700">
                                                        <p class="text-sm text-amber-700 dark:text-amber-300 font-semibold">
                                                            Troco: R$ {{ number_format($changePreview, 2, ',', '.') }}
                                                        </p>
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <div class="space-y-1.5">
                                    <flux:label class="text-xs font-semibold">Observação (opcional)</flux:label>
                                    <flux:textarea
                                        wire:model="notes"
                                        placeholder="Ex: sem cebola, embrulhar separado..."
                                        rows="3"
                                        class="resize-none"
                                    />
                                </div>

                                @error('order')
                                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="border-t border-neutral-100 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-[#0f1926]/70 xl:border-l xl:border-t-0">
                                <div class="flex h-full flex-col gap-4">
                                    <div>
                                        <p class="text-[11px] font-bold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">Resumo do pedido</p>
                                        <div class="mt-3 max-h-64 overflow-y-auto rounded-xl border border-neutral-200 bg-white divide-y divide-neutral-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
                                            @foreach ($cart as $cartKey => $item)
                                                @php
                                                    $itemOptionsExtra = 0.0;
                                                    foreach ($item['options'] ?? [] as $group) {
                                                        foreach ($group['selections'] ?? [] as $sel) {
                                                            $itemOptionsExtra += ($sel['qty'] ?? 0) * ($sel['additional_price'] ?? 0);
                                                        }
                                                    }
                                                    $itemUnitPrice = (float) $item['price'] + $itemOptionsExtra;
                                                @endphp
                                                <div class="px-3 py-2">
                                                    <div class="flex items-start justify-between gap-3 text-sm">
                                                        <span class="font-medium text-neutral-800 dark:text-neutral-100">{{ $item['qty'] }}x {{ $item['name'] }}</span>
                                                        <span class="shrink-0 font-semibold text-neutral-900 dark:text-neutral-100">R$ {{ number_format($itemUnitPrice * $item['qty'], 2, ',', '.') }}</span>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="mt-auto space-y-3">
                                        @if ($deliveryFeeAmount > 0 || $manualDiscountAmount > 0 || $this->serviceFeeAmount > 0 || $this->couvertFeeAmount > 0)
                                            <div class="space-y-1 text-sm">
                                                <div class="flex justify-between text-neutral-500 dark:text-neutral-400">
                                                    <span>Subtotal</span>
                                                    <span>R$ {{ number_format($this->cartTotal, 2, ',', '.') }}</span>
                                                </div>
                                                @if ($deliveryFeeAmount > 0)
                                                    <div class="flex justify-between text-neutral-500 dark:text-neutral-400">
                                                        <span>Taxa de entrega</span>
                                                        <span>+ R$ {{ number_format($deliveryFeeAmount, 2, ',', '.') }}</span>
                                                    </div>
                                                @endif
                                                @if ($this->serviceFeeAmount > 0)
                                                    <div class="flex justify-between text-neutral-500 dark:text-neutral-400">
                                                        <span>Taxa de serviço</span>
                                                        <span>+ R$ {{ number_format($this->serviceFeeAmount, 2, ',', '.') }}</span>
                                                    </div>
                                                @endif
                                                @if ($this->couvertFeeAmount > 0)
                                                    <div class="flex justify-between text-neutral-500 dark:text-neutral-400">
                                                        <span>Couvert artístico</span>
                                                        <span>+ R$ {{ number_format($this->couvertFeeAmount, 2, ',', '.') }}</span>
                                                    </div>
                                                @endif
                                                @if ($manualDiscountAmount > 0)
                                                    <div class="flex justify-between text-green-600 dark:text-green-400">
                                                        <span>Desconto manual</span>
                                                        <span>- R$ {{ number_format($manualDiscountAmount, 2, ',', '.') }}</span>
                                                    </div>
                                                @endif
                                            </div>
                                        @endif

                                        <div class="rounded-xl border border-neutral-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                                            <div class="flex items-end justify-between gap-3">
                                                <span class="text-sm font-semibold text-neutral-500 dark:text-neutral-400">Total</span>
                                                <span class="text-2xl font-black text-neutral-900 dark:text-neutral-100">
                                                    R$ {{ number_format($this->cartTotalAfterDiscount, 2, ',', '.') }}
                                                </span>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-2 gap-2">
                                            <flux:button wire:click="backToCatalog" variant="ghost" size="base">
                                                Voltar
                                            </flux:button>
                                            <flux:button
                                                wire:click="processOrder"
                                                variant="primary"
                                                size="base"
                                                wire:loading.attr="disabled"
                                            >
                                                <span wire:loading.remove>Confirmar</span>
                                                <span wire:loading>Processando...</span>
                                            </flux:button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

        </div>
        {{-- fim 3 colunas --}}
    @endif

</div>
