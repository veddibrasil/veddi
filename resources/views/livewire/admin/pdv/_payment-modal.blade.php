{{-- Modal de checkout (step = payment) da venda direta: tipo de pedido, desconto, entrega, agendamento, pagamento e confirmação. Incluída por terminal.blade.php (mesmo escopo de variáveis e $this do componente). --}}
{{-- fixed (não absolute): mesmo motivo do TabTerminal — cobre o header
     inteiro (select de filial incluso), senão ele fica clicável/focável
     por trás do modal e mudar de filial no meio do pagamento fecha o
     modal sozinho (updatedSelectedBranchId() zera $step). --}}
<div class="fixed inset-0 z-30 flex items-center justify-center bg-amber-950/45 p-3 lg:p-6">
    <div class="flex max-h-full w-full max-w-6xl flex-col overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-900">
        <div class="shrink-0 border-b border-neutral-100 px-4 py-3 dark:border-zinc-800">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <flux:button wire:click="backToCatalog" variant="ghost" icon="arrow-left" size="sm" aria-label="Voltar para o catálogo" />
                    <div class="min-w-0">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Checkout</p>
                        <h2 class="text-lg font-black text-neutral-900 dark:text-neutral-100">
                            Finalizar pedido
                        </h2>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Total</p>
                    <p class="text-xl font-black text-neutral-900 dark:text-neutral-100">
                        R$ {{ number_format($this->cartTotalAfterDiscount, 2, ',', '.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex min-h-0 flex-1 flex-col overflow-hidden xl:flex-row">
            <div class="flex-1 min-h-0 overflow-y-auto space-y-5 p-4 lg:p-5">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="space-y-1.5">
                        <flux:label class="text-xs font-semibold">Tipo de pedido</flux:label>
                        <flux:radio.group wire:model.live="deliveryType" variant="segmented" class="w-full">
                            <flux:radio value="balcao" label="Balcão" />
                            <flux:radio value="entrega" label="Entrega" />
                            <flux:radio value="retirar" label="Retirar" />
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
                                    <flux:select wire:model.live="manualDiscountType" class="w-20 shrink-0" aria-label="Tipo de desconto">
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
                            <flux:checkbox wire:model.live="isScheduled" aria-label="Agendar pedido para depois" />
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
                                    <flux:checkbox wire:model.live="serviceFeeWaived" aria-label="Isentar taxa de serviço" />
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
                                    <flux:checkbox wire:model.live="couvertFeeWaived" aria-label="Isentar couvert" />
                                    <span class="text-sm">Remover couvert artístico</span>
                                </span>
                                <span class="text-xs font-semibold {{ $couvertFeeWaived ? 'text-neutral-400 line-through' : 'text-neutral-600 dark:text-neutral-300' }}">
                                    R$ {{ number_format($this->rawCouvertFeeAmount, 2, ',', '.') }}
                                </span>
                            </label>
                        @endif
                    </div>
                @endif

                @if (in_array($deliveryType, ['entrega', 'retirar']))
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
                                         if (d.logradouro) $wire.set('deliveryAddress', d.logradouro, false);
                                         if (d.bairro)     $wire.set('deliveryNeighborhood', d.bairro, false);
                                         if (d.uf)         $wire.set('deliveryState', d.uf, false);
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

                <div class="grid gap-4 md:grid-cols-[1fr_2fr]">
                    @if ($deliveryType === 'entrega')
                        <div class="space-y-1.5">
                            <flux:label class="text-xs font-semibold">Pagamento na entrega</flux:label>
                            <flux:radio.group wire:model.live="deliveryPaymentStatus" variant="segmented" class="w-full">
                                <flux:radio value="paid" label="Já está pago" />
                                <flux:radio value="on_delivery" label="Receber na entrega" />
                            </flux:radio.group>
                        </div>
                    @elseif ($deliveryType === 'retirar')
                        <div class="space-y-1.5">
                            <flux:label class="text-xs font-semibold">Pagamento na retirada</flux:label>
                            <flux:radio.group wire:model.live="pickupPaymentStatus" variant="segmented" class="w-full">
                                <flux:radio value="paid" label="Já está pago" />
                                <flux:radio value="on_pickup" label="Pagar na retirada" />
                            </flux:radio.group>
                        </div>
                    @else
                        <div class="space-y-1.5">
                            @include('livewire.admin.pdv._customer-search')
                        </div>
                    @endif

                    @include('livewire.admin.pdv._split-payment')
                </div>

                <div class="text-xs text-neutral-500 dark:text-neutral-400">
                    Atendente: <span class="font-medium text-neutral-700 dark:text-neutral-200">{{ auth()->user()->name }}</span>
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

            <div class="flex max-h-[50vh] flex-col overflow-hidden max-xl:shrink-0 border-t border-neutral-100 bg-zinc-50 dark:border-zinc-800 dark:bg-[#0f1926]/70 xl:max-h-none xl:w-[22rem] xl:shrink-0 xl:border-l xl:border-t-0">
                {{-- Área de cima rola por dentro; o rodapé (Total/nota fiscal/botões)
                     fica FORA dela — mesmo motivo do rodapé do carrinho: "Confirmar"
                     precisa estar sempre alcançável sem rolar, mesmo com formulário
                     ou lista de itens longos. --}}
                {{-- min-h abaixo de xl: com a coluna empilhada sob o formulário, a lista (flex-1, base 0)
                     não contribuía com altura nenhuma e o resumo sobrava com ~30px em tablet 1024x768. --}}
                <div class="flex-1 min-h-[7.5rem] overflow-y-auto p-4 space-y-4 xl:min-h-0">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Resumo do pedido</p>
                        <div class="mt-3 rounded-xl border border-neutral-200 bg-white divide-y divide-neutral-100 dark:border-zinc-800 dark:bg-zinc-900 dark:divide-zinc-800">
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
                </div>

                <div class="shrink-0 space-y-3 border-t border-neutral-100 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-[#0f1926]/70">
                    <div class="rounded-xl border border-neutral-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="flex items-end justify-between gap-3">
                            <span class="text-sm font-semibold text-neutral-500 dark:text-neutral-400">Total</span>
                            <span class="text-2xl font-black text-neutral-900 dark:text-neutral-100">
                                R$ {{ number_format($this->cartTotalAfterDiscount, 2, ',', '.') }}
                            </span>
                        </div>
                    </div>

                    @if ($canUseFiscalNotes)
                        <label class="flex items-center gap-2 px-3 py-2 border rounded-xl dark:border-zinc-700 cursor-pointer">
                            <flux:checkbox wire:model.live="printFiscalNote" aria-label="Imprimir nota fiscal ao confirmar" />
                            <span class="text-sm">Imprimir nota fiscal ao confirmar</span>
                        </label>
                    @endif

                    <div class="grid grid-cols-2 gap-2">
                        <flux:button wire:click="backToCatalog" variant="ghost" size="base">
                            Voltar
                        </flux:button>
                        <flux:button
                            id="pdv-confirm-order-btn"
                            x-on:click="$wire.processOrder(document.getElementById('pdv-cash-received-input')?.value ?? null)"
                            variant="primary"
                            size="base"
                            wire:loading.attr="disabled"
                            :disabled="$isSplitPayment && abs($this->splitPaymentsRemaining) > 0.01"
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
