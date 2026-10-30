{{-- Pedidos: confirmação só pela API e recebimento de solicitações de cancelamento/reembolso do cliente. --}}
<x-admin.form-card padding="p-6 space-y-5">
    <div>
        <h3 class="font-semibold text-neutral-800 dark:text-neutral-100">Pedidos da 99Food</h3>
        <p class="text-sm text-neutral-600 dark:text-neutral-300">
            Os pedidos chegam no painel e precisam ser aceitos em até 5 minutos — pelo kanban, pela tela do pedido ou pelo app da 99Food, conforme a configuração da loja.
        </p>
    </div>

    <div class="space-y-3">
        <flux:checkbox wire:model="receiveCancelApply" label="Receber pedidos de cancelamento do cliente"
            description="A loja tem 5 minutos para responder; sem resposta, a 99Food recusa o cancelamento." />
        <flux:checkbox wire:model="receiveRefundApply" label="Receber pedidos de reembolso do cliente"
            description="A loja tem 24 horas para responder; sem resposta, a 99Food aceita o reembolso." />
        <flux:button wire:click="saveApplySettings" wire:target="saveApplySettings" variant="primary">Salvar</flux:button>
    </div>

    <flux:button wire:click="reapplySettings" wire:target="reapplySettings" variant="ghost" size="sm">Reaplicar configuração da loja na 99Food</flux:button>
</x-admin.form-card>
