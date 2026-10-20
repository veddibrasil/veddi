@php
    $recipient = $this->effectiveFiscalRecipient();
    $recipientModel = \App\Services\Fiscal\FiscalRecipient::model($recipient);
@endphp
<div class="rounded-xl border border-neutral-200 bg-white p-3 space-y-2 dark:bg-zinc-800 dark:border-zinc-700">
    <p class="text-sm font-medium text-neutral-800 dark:text-neutral-100">Nota fiscal: {{ $recipientModel === '55' ? 'NF-e' : 'NFC-e' }}{{ filled($recipient['document'] ?? '') ? ' · '.($recipientModel === '55' ? ($recipient['name'] ?? $recipient['document']) : $recipient['document']) : '' }}</p>
    <p class="text-xs text-neutral-500">CNPJ: NF-e para a empresa. CPF ou sem documento: NFC-e.</p>
    <button type="button" wire:click="openFiscalRecipient" class="text-sm text-blue-600 underline">Alterar dados fiscais</button>
    @if($showFiscalRecipient)
        <div class="space-y-3 border-t pt-3 dark:border-zinc-700">
            <flux:input wire:model.live.debounce.300ms="fiscalRecipientDraft.document" label="CPF ou CNPJ na nota" maxlength="18" />
            @error('fiscalRecipientDraft.document') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            @if(\App\Services\Fiscal\FiscalRecipient::model($fiscalRecipientDraft) === '55')
                <p class="text-xs text-neutral-500">Informe os dados fiscais da empresa. O endereço pode ser diferente do endereço de entrega.</p>
                @foreach(['name' => 'Razão social', 'email' => 'E-mail fiscal', 'street' => 'Logradouro', 'number' => 'Número', 'complement' => 'Complemento', 'neighborhood' => 'Bairro', 'city' => 'Município', 'state' => 'UF', 'zip' => 'CEP'] as $field => $label)
                    <flux:input wire:model="fiscalRecipientDraft.{{ $field }}" :label="$label" />
                    @error('fiscalRecipientDraft.'.$field) <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                @endforeach
                <flux:select wire:model.live="fiscalRecipientDraft.ie_indicator" label="Inscrição estadual">
                    <option value="">Selecione</option>
                    <option value="9">Não contribuinte</option>
                    <option value="1">Contribuinte de ICMS</option>
                    <option value="2">Isento</option>
                </flux:select>
                @error('fiscalRecipientDraft.ie_indicator') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                @if(($fiscalRecipientDraft['ie_indicator'] ?? '') === '1')
                    <flux:input wire:model="fiscalRecipientDraft.ie" label="Número da inscrição estadual" />
                    @error('fiscalRecipientDraft.ie') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                @endif
            @endif
            <flux:checkbox wire:model="rememberFiscalRecipient" label="Usar estes dados fiscais nas próximas compras" />
            <div class="flex gap-2">
                <flux:button type="button" wire:click="$set('showFiscalRecipient', false)" size="sm">Voltar</flux:button>
                <flux:button type="button" wire:click="saveFiscalRecipient" wire:loading.attr="disabled" variant="primary" size="sm">Salvar dados fiscais</flux:button>
            </div>
        </div>
    @endif
</div>
