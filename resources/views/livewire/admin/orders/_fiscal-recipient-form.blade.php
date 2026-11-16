{{-- Conferência dos dados da empresa (CNPJ) dentro do modal de emissão. Salvar grava no pedido; não emite. --}}
<div class="space-y-3 rounded-lg border border-neutral-200 p-3 dark:border-zinc-700" x-init="$nextTick(() => $el.querySelector('input')?.focus())">
    <div>
        <p class="text-sm font-medium text-neutral-800 dark:text-neutral-100">Dados da empresa para a nota</p>
        <p class="text-xs text-neutral-500 dark:text-neutral-400">Preenchidos pela consulta pública do CNPJ quando disponível. Confira com o comprador; o endereço é o fiscal (sede), não o de entrega.</p>
    </div>
    @if($fiscalRecipientNotice)
        <p role="status" class="text-xs text-amber-700 dark:text-amber-400">{{ $fiscalRecipientNotice }}</p>
    @endif
    @error('fiscalRecipientDraft.document') <p role="alert" class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-6">
        <div class="sm:col-span-6">
            <flux:input wire:model="fiscalRecipientDraft.name" label="Razão social" />
            @error('fiscalRecipientDraft.name') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        @foreach(['zip' => ['CEP', 'sm:col-span-2'], 'city' => ['Município', 'sm:col-span-3'], 'state' => ['UF', 'sm:col-span-1'], 'street' => ['Logradouro', 'sm:col-span-4'], 'number' => ['Número', 'sm:col-span-2'], 'neighborhood' => ['Bairro', 'sm:col-span-3'], 'complement' => ['Complemento', 'sm:col-span-3'], 'email' => ['E-mail para envio da nota', 'sm:col-span-6']] as $field => [$fieldLabel, $span])
            <div class="{{ $span }}">
                <flux:input wire:model="fiscalRecipientDraft.{{ $field }}" :label="$fieldLabel" :maxlength="$field === 'state' ? 2 : null" />
                @error('fiscalRecipientDraft.'.$field) <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        @endforeach
        <div class="sm:col-span-3">
            <flux:select wire:model.live="fiscalRecipientDraft.ie_indicator" label="Situação da inscrição estadual">
                <option value="">Selecione</option>
                <option value="9">Não contribuinte</option>
                <option value="1">Contribuinte de ICMS</option>
                <option value="2">Isenta</option>
            </flux:select>
            @error('fiscalRecipientDraft.ie_indicator') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        @if(($fiscalRecipientDraft['ie_indicator'] ?? '') === '1')
            <div class="sm:col-span-3">
                <flux:input wire:model="fiscalRecipientDraft.ie" label="Número da IE" />
                @error('fiscalRecipientDraft.ie') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        @endif
    </div>
    <p class="text-xs text-neutral-500 dark:text-neutral-400">A consulta pública não informa a IE. Ao salvar, a situação escolhida fica registrada como conferida.</p>
    @if($order->customer_id)
        <flux:checkbox wire:model="rememberFiscalRecipient" label="Usar estes dados nas próximas compras deste cliente" />
    @endif
    <div class="flex flex-wrap gap-2">
        <flux:button type="button" wire:click="saveFiscalRecipientAndContinue" wire:loading.attr="disabled" variant="primary" size="sm">Salvar dados e continuar</flux:button>
        <flux:button type="button" wire:click="$set('showFiscalRecipient', false)" size="sm" variant="ghost">Voltar</flux:button>
    </div>
</div>
