<div class="space-y-1.5">
    <div class="flex items-center justify-between gap-3">
        <flux:label class="text-xs font-semibold">Método de pagamento</flux:label>

        @if (! (isset($deliveryType, $deliveryPaymentStatus) && $deliveryType === 'entrega' && $deliveryPaymentStatus === 'on_delivery'))
            <label class="flex items-center gap-2 text-xs font-medium text-neutral-500 dark:text-neutral-400 cursor-pointer">
                Dividir pagamento
                <flux:switch wire:model.live="isSplitPayment" />
            </label>
        @endif
    </div>

    @if (! $isSplitPayment)
        <flux:radio.group wire:model.live="paymentMethod" variant="segmented" class="w-full">
            <flux:radio value="cash" label="Dinheiro" />
            <flux:radio value="pix" label="PIX" />
            <flux:radio value="credit_card" label="Cartão" />
        </flux:radio.group>

        @if ($paymentMethod === 'cash')
            <div class="pt-3 space-y-1.5">
                <flux:label class="text-xs font-semibold">Valor recebido</flux:label>
                <flux:input
                    wire:model.live.debounce.500ms="cashReceivedInput"
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
    @else
        <div class="space-y-2">
            @foreach ($splitPayments as $index => $part)
                <div wire:key="split-part-{{ $index }}" class="flex flex-wrap items-center gap-2 rounded-xl border border-neutral-200 p-2 dark:border-zinc-700">
                    <flux:select wire:model.live="splitPayments.{{ $index }}.method" size="sm" class="w-32 shrink-0">
                        <flux:select.option value="cash">Dinheiro</flux:select.option>
                        <flux:select.option value="pix">PIX</flux:select.option>
                        <flux:select.option value="credit_card">Cartão</flux:select.option>
                    </flux:select>

                    <flux:input
                        wire:model.live.debounce.500ms="splitPayments.{{ $index }}.amount"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="Valor"
                        class="min-w-[7rem] flex-1"
                    />

                    @if ($part['method'] === 'cash')
                        <flux:input
                            wire:model.live.debounce.500ms="splitPayments.{{ $index }}.cash_received"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="Recebido"
                            class="w-28 shrink-0"
                        />
                    @endif

                    <label class="flex shrink-0 items-center gap-1.5 text-xs font-medium text-neutral-500 dark:text-neutral-400 cursor-pointer">
                        <flux:checkbox wire:model.live="splitPayments.{{ $index }}.paid" />
                        Pago
                    </label>

                    @if (count($splitPayments) > 2)
                        <button
                            type="button"
                            wire:click="removeSplitPart({{ $index }})"
                            title="Remover parte"
                            class="shrink-0 text-neutral-400 hover:text-red-600 dark:hover:text-red-400"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    @endif

                    @if ($part['method'] === 'cash' && filled($part['cash_received'] ?? null))
                        @php
                            $partAmount = (float) str_replace(',', '.', $part['amount'] ?: 0);
                            $partReceived = (float) str_replace(',', '.', $part['cash_received']);
                        @endphp
                        @if ($partReceived >= $partAmount)
                            <div class="w-full bg-amber-50 border border-amber-200 rounded-xl px-3 py-1.5 dark:bg-amber-900/20 dark:border-amber-700">
                                <p class="text-xs text-amber-700 dark:text-amber-300 font-semibold">
                                    Troco: R$ {{ number_format($partReceived - $partAmount, 2, ',', '.') }}
                                </p>
                            </div>
                        @endif
                    @endif
                </div>
            @endforeach

            <button type="button" wire:click="addSplitPart" class="text-xs font-semibold text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200">
                + Adicionar parte
            </button>

            <div class="rounded-xl border border-neutral-200 bg-white p-2 text-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex justify-between text-neutral-500 dark:text-neutral-400">
                    <span>Total das partes</span>
                    <span class="font-semibold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($this->splitPaymentsTotal, 2, ',', '.') }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-neutral-500 dark:text-neutral-400">Falta</span>
                    <span class="font-semibold {{ abs($this->splitPaymentsRemaining) < 0.01 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                        R$ {{ number_format($this->splitPaymentsRemaining, 2, ',', '.') }}
                    </span>
                </div>
                <div class="mt-1 flex justify-between border-t border-neutral-100 pt-1 dark:border-zinc-800">
                    <span class="text-neutral-500 dark:text-neutral-400">Recebido (marcado como pago)</span>
                    <span class="font-semibold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($this->splitPaymentsPaidAmount, 2, ',', '.') }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-neutral-500 dark:text-neutral-400">Falta receber</span>
                    <span class="font-semibold {{ $this->splitPaymentsRemainingToCollect <= 0.01 ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400' }}">
                        R$ {{ number_format(max(0, $this->splitPaymentsRemainingToCollect), 2, ',', '.') }}
                    </span>
                </div>
            </div>
        </div>
    @endif
</div>
