{{-- Tela de fechamento de caixa (step = close_cash): conferência do valor contado e justificativa de diferença. Incluída por terminal.blade.php (mesmo escopo de variáveis e $this do componente). --}}
@php
    $session = $this->cashSession;
    $cashBreakdown = $this->cashSessionBreakdown($session);
    $expectedCash = $cashBreakdown['expected'];
    $closingVal = filled($closingAmountInput) ? \App\Support\MoneyInput::parse($closingAmountInput) : null;
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
                    <span class="text-neutral-600 dark:text-neutral-400">Abertura</span>
                    <span class="font-medium">R$ {{ number_format($cashBreakdown['opening'], 2, ',', '.') }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-neutral-600 dark:text-neutral-400">Vendas em dinheiro</span>
                    <span class="font-medium text-green-800 dark:text-green-400">+ R$ {{ number_format($cashBreakdown['cash_sales'], 2, ',', '.') }}</span>
                </div>
                @if ($cashBreakdown['supplies'] > 0)
                    <div class="flex justify-between">
                        <span class="text-neutral-600 dark:text-neutral-400">Suprimentos</span>
                        <span class="font-medium text-green-800 dark:text-green-400">+ R$ {{ number_format($cashBreakdown['supplies'], 2, ',', '.') }}</span>
                    </div>
                @endif
                @if ($cashBreakdown['withdrawals'] > 0)
                    <div class="flex justify-between">
                        <span class="text-neutral-600 dark:text-neutral-400">Sangrias</span>
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
                wire:model.live.debounce.300ms="closingAmountInput"
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
