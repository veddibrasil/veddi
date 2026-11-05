{{--
    Recusa/cancelamento de pedido Keeta (kanban e tela do pedido). Motivos fechados da Keeta
    (RequestCancelled.code); os de entrega só aparecem na entrega própria. A Keeta confirma
    com o evento CANCELLED: até lá o pedido fica "cancelamento pedido".
--}}
@php $reason = \App\Enums\KeetaCancelReason::tryFrom($keetaCancelReason); @endphp
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
     x-data
     x-trap.noscroll="true"
     x-on:keydown.escape.window="$wire.closeKeetaCancelModal()">
    <div role="dialog" aria-modal="true" aria-labelledby="keeta-cancel-title" aria-describedby="keeta-cancel-description"
         class="bg-white dark:bg-zinc-800 rounded-xl shadow-xl p-6 w-full max-w-md mx-4 space-y-5">
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0" aria-hidden="true">
                <flux:icon.x-mark class="size-5 text-red-600 dark:text-red-400" />
            </div>
            <div>
                <h3 id="keeta-cancel-title" class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">
                    {{ $keetaCancelAccepted ? 'Cancelar pedido na Keeta' : 'Recusar pedido Keeta' }}
                </h3>
                <p id="keeta-cancel-description" class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                    {{ $keetaCancelAccepted
                        ? 'A Keeta avisa e reembolsa o cliente. Cancelar pedidos aceitos com frequência pode afetar a loja na Keeta.'
                        : 'A Keeta exige um dos motivos abaixo para recusar o pedido.' }}
                </p>
            </div>
        </div>

        <flux:select wire:model.live="keetaCancelReason" label="Motivo" placeholder="Selecione um motivo">
            @foreach ($this->keetaCancelReasons() as $option)
                <flux:select.option value="{{ $option->value }}">{{ $option->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($reason?->takesItems() && $keetaCancelOrderItems !== [])
            <fieldset class="space-y-1">
                <legend class="text-sm font-medium text-neutral-700 dark:text-neutral-200">
                    {{ $reason === \App\Enums\KeetaCancelReason::OutdatedMenu ? 'Itens que não existem mais (a Keeta desativa)' : 'Itens em falta' }}
                </legend>
                @foreach ($keetaCancelOrderItems as $item)
                    <label class="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                        <input type="checkbox" wire:model="keetaCancelItems" value="{{ $item['id'] }}"> {{ $item['name'] }}
                    </label>
                @endforeach
            </fieldset>
        @endif

        @if ($keetaCancelReason !== '')
            <flux:textarea wire:model="keetaCancelText" :label="$reason?->requiresText() ? 'Descreva o motivo' : 'Detalhe (opcional)'" rows="2" maxlength="200" />
        @endif

        <div class="flex justify-end gap-3 pt-1">
            <flux:button wire:click="closeKeetaCancelModal" variant="ghost">Voltar</flux:button>
            <flux:button wire:click="confirmKeetaCancel" wire:target="confirmKeetaCancel" variant="danger" :disabled="! $keetaCancelReason">
                {{ $keetaCancelAccepted ? 'Pedir cancelamento' : 'Recusar pedido' }}
            </flux:button>
        </div>
    </div>
</div>
