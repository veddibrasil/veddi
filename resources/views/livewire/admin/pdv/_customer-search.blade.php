<flux:label class="text-xs font-semibold">
    Cliente {{ $deliveryType === 'entrega' ? '(obrigatório para entrega)' : '(opcional)' }}
</flux:label>
@if ($this->needsCustomerForDelivery)
    <p class="text-xs text-red-600 dark:text-red-400">Selecione ou cadastre um cliente para entrega.</p>
@endif
<div class="flex gap-2">
    <flux:input
        wire:model.live.debounce.300ms="customerQuery"
        placeholder="Nome, telefone ou CPF..."
        class="flex-1"
    />
    <flux:button wire:click="lookupCustomer" variant="outline" icon="magnifying-glass" size="sm" />
</div>
@if ($customerFound)
    <p class="text-xs text-green-600 dark:text-green-400">✓ {{ $customerName }}</p>
@elseif (!empty($customerResults))
    <div class="border rounded-xl overflow-hidden dark:border-zinc-600 max-h-32 overflow-y-auto">
        @foreach ($customerResults as $result)
            <button
                wire:click="selectCustomer({{ $result['id'] }})"
                class="w-full flex items-center justify-between px-3 py-2 text-left text-xs hover:bg-amber-50 dark:hover:bg-amber-900/20 transition-colors border-b last:border-b-0 dark:border-zinc-600"
            >
                <span class="font-medium text-neutral-800 dark:text-neutral-100">{{ $result['name'] }}</span>
                <span class="text-neutral-400 dark:text-neutral-500 shrink-0 ml-2">{{ $result['phone'] }}</span>
            </button>
        @endforeach
    </div>
@elseif (filled($customerQuery) && !$customerFound)
    @if ($showCreateCustomer)
        <div class="space-y-2 border rounded-xl p-3 dark:border-zinc-600">
            <p class="text-[10px] font-semibold text-neutral-600 dark:text-neutral-300">Novo cliente</p>
            <flux:input wire:model="newCustomerName" placeholder="Nome completo" />
            <flux:input wire:model="newCustomerPhone" wire:keydown.enter="createCustomer" placeholder="Telefone (somente números)" />
            @if ($createCustomerError)
                <p class="text-xs text-red-600 dark:text-red-400">{{ $createCustomerError }}</p>
            @endif
            <div class="flex gap-2">
                <flux:button wire:click="cancelCreateCustomer" variant="ghost" size="sm">Cancelar</flux:button>
                <flux:button wire:click="createCustomer" variant="primary" size="sm" class="flex-1">Cadastrar</flux:button>
            </div>
        </div>
    @else
        <div class="flex items-center justify-between gap-3">
            <p class="text-xs text-neutral-500 dark:text-neutral-400">Cliente não encontrado; pedido como balcão.</p>
            <flux:button wire:click="showCreateCustomerForm" variant="ghost" size="sm" icon="user-plus" class="shrink-0">
                Criar
            </flux:button>
        </div>
    @endif
@endif
