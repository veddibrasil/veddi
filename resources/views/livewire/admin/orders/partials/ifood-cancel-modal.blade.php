{{--
    Recusa/cancelamento de pedido iFood (kanban e tela do pedido). Os motivos vêm da API do
    iFood (GET /cancellationReasons) — exigência da homologação; nunca fixar a lista aqui.
    Parâmetros: $accepted (bool) — pedido já aceito: vira pedido de cancelamento ao iFood.
--}}
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
     x-data
     x-trap.noscroll="true"
     x-on:keydown.escape.window="$wire.closeIfoodCancelModal()">
    <div role="dialog" aria-modal="true" aria-labelledby="ifood-cancel-title" aria-describedby="ifood-cancel-description"
         class="bg-white dark:bg-zinc-800 rounded-xl shadow-xl p-6 w-full max-w-md mx-4 space-y-5">
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0" aria-hidden="true">
                <flux:icon.x-mark class="size-5 text-red-600 dark:text-red-400" />
            </div>
            <div>
                <h3 id="ifood-cancel-title" class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">
                    {{ $accepted ? 'Solicitar cancelamento ao iFood' : 'Recusar pedido iFood' }}
                </h3>
                <p id="ifood-cancel-description" class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                    {{ $accepted
                        ? 'O iFood analisa o pedido de cancelamento; o pedido segue ativo até a resposta.'
                        : 'O iFood exige um dos motivos abaixo para recusar o pedido.' }}
                </p>
            </div>
        </div>

        <flux:select wire:model.live="ifoodCancelReason" label="Motivo" placeholder="Selecione um motivo">
            @foreach ($ifoodCancellationReasons as $reason)
                <flux:select.option value="{{ $reason['code'] }}">{{ $reason['description'] }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="flex justify-end gap-3 pt-1">
            <flux:button wire:click="closeIfoodCancelModal" variant="ghost">Voltar</flux:button>
            <flux:button wire:click="confirmIfoodCancel" wire:target="confirmIfoodCancel" variant="danger" :disabled="! $ifoodCancelReason">
                {{ $accepted ? 'Solicitar cancelamento' : 'Recusar pedido' }}
            </flux:button>
        </div>
    </div>
</div>
