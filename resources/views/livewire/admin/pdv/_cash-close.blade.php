{{-- Tela de fechamento de caixa (step = close_cash). Fechamento cego: o operador vê só o turno, o valor de abertura e informa o valor contado — esperado, vendas e diferença ficam só para o administrador (relatórios de fechamento e Relatório PDV). Incluída por terminal.blade.php (mesmo escopo de variáveis e $this do componente). --}}
@php $session = $this->cashSession; @endphp
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
            <div class="bg-neutral-50 rounded-xl p-4 border dark:bg-zinc-800 dark:border-zinc-700 space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                    <span class="text-neutral-600 dark:text-neutral-400">Turno</span>
                    <span class="font-medium text-right">
                        {{ $session->created_at->format('d/m H:i') }} · {{ $this->shiftStats['duration'] }}
                    </span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-neutral-600 dark:text-neutral-400">Abertura</span>
                    <span class="font-medium">R$ {{ number_format((float) $session->opening_amount, 2, ',', '.') }}</span>
                </div>
            </div>
        @endif

        <div class="space-y-2">
            <flux:label>Valor contado no caixa</flux:label>
            <flux:input
                wire:model="closingAmountInput"
                placeholder="R$ 0,00"
                type="number"
                step="0.01"
                min="0"
            />
            @error('closingAmountInput')
                <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

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
