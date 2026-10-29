{{--
    Recusa/cancelamento de pedido 99Food (kanban e tela do pedido). Motivos fechados da 99
    (reason_id); os de entrega só aparecem na entrega própria e "Outro motivo" exige o texto.
--}}
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
     x-data
     x-trap.noscroll="true"
     x-on:keydown.escape.window="$wire.closeFood99CancelModal()">
    <div role="dialog" aria-modal="true" aria-labelledby="food99-cancel-title" aria-describedby="food99-cancel-description"
         class="bg-white dark:bg-zinc-800 rounded-xl shadow-xl p-6 w-full max-w-md mx-4 space-y-5">
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0" aria-hidden="true">
                <flux:icon.x-mark class="size-5 text-red-600 dark:text-red-400" />
            </div>
            <div>
                <h3 id="food99-cancel-title" class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">
                    {{ $food99CancelAccepted ? 'Cancelar pedido na 99Food' : 'Recusar pedido 99Food' }}
                </h3>
                <p id="food99-cancel-description" class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                    {{ $food99CancelAccepted
                        ? 'O cliente é avisado e reembolsado pela 99Food. Cancelar pedidos aceitos com frequência pode afetar a loja na 99Food.'
                        : 'A 99Food exige um dos motivos abaixo para recusar o pedido.' }}
                </p>
            </div>
        </div>

        <flux:select wire:model.live="food99CancelReason" label="Motivo" placeholder="Selecione um motivo">
            @foreach ($this->food99CancelReasons() as $reason)
                <flux:select.option value="{{ $reason->value }}">{{ $reason->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ((int) $food99CancelReason === \App\Enums\Food99CancelReason::Other->value)
            <flux:textarea wire:model="food99CancelText" label="Descreva o motivo" rows="2" maxlength="200" />
        @endif

        <div class="flex justify-end gap-3 pt-1">
            <flux:button wire:click="closeFood99CancelModal" variant="ghost">Voltar</flux:button>
            <flux:button wire:click="confirmFood99Cancel" wire:target="confirmFood99Cancel" variant="danger" :disabled="! $food99CancelReason">
                {{ $food99CancelAccepted ? 'Cancelar pedido' : 'Recusar pedido' }}
            </flux:button>
        </div>
    </div>
</div>
