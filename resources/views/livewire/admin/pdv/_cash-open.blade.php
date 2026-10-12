{{-- Tela de abertura de caixa (step = open_cash): troco inicial e nome do terminal. Incluída por terminal.blade.php (mesmo escopo de variáveis e $this do componente). --}}
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
                <p class="text-xs text-neutral-500 dark:text-neutral-400">Útil quando há múltiplos caixas na mesma filial.</p>
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
                @error('openingAmountInput')
                    <p class="text-xs text-red-600 dark:text-red-400" role="alert">{{ $message }}</p>
                @enderror
                <p class="text-xs text-neutral-500 dark:text-neutral-400">Deixe em branco se o caixa começa sem troco.</p>
            </div>
        </div>

        <flux:button wire:click="openCashSession" wire:loading.attr="disabled" wire:target="openCashSession" variant="primary" size="base" class="w-full">
            Abrir caixa e começar
        </flux:button>
    </div>
</div>
