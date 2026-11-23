{{-- Dados do comprador (CPF ou CNPJ). Salvar grava no pedido; não emite. --}}
@php
    $buyerIsCompany = \App\Services\Fiscal\FiscalRecipient::documentType($fiscalRecipientDraft['document'] ?? '') === 'cnpj';
    $draftReadiness = $this->fiscalDraftReadiness;
    $draftReady = $draftReadiness && ! $draftReadiness['decision']->blocked() && $draftReadiness['issues'] === [];
    $addressIncomplete = collect(['street', 'neighborhood', 'city', 'state'])->contains(fn ($field) => blank($fiscalRecipientDraft[$field] ?? null));
@endphp
<div class="space-y-3 rounded-lg border border-neutral-200 p-3 dark:border-zinc-700" x-init="$nextTick(() => $el.querySelector('input')?.focus())">
    <div>
        <p class="text-sm font-medium text-neutral-800 dark:text-neutral-100">{{ $buyerIsCompany ? 'Dados da empresa para a nota' : 'Dados do comprador para a nota' }}</p>
        <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $buyerIsCompany ? 'Preenchidos pela consulta pública do CNPJ quando disponível. Confira com o comprador; o endereço é o fiscal (sede), não o de entrega.' : 'Para NF-e, informe nome completo e endereço fiscal do comprador. CPF também pode ter inscrição estadual.' }}</p>
    </div>
    @if($fiscalRecipientNotice)
        <p role="status" class="text-xs text-amber-700 dark:text-amber-400">{{ $fiscalRecipientNotice }}</p>
    @endif
    @error('fiscalRecipientDraft.document') <p role="alert" class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-6">
        <div class="sm:col-span-6">
            <flux:input wire:model.live.debounce.250ms="fiscalRecipientDraft.name" :label="$buyerIsCompany ? 'Razão social' : 'Nome completo'" autocomplete="name" />
            @error('fiscalRecipientDraft.name') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-3">
            <flux:input wire:model.live.debounce.350ms="fiscalRecipientDraft.zip" label="CEP" x-mask="99999-999" inputmode="numeric" autocomplete="postal-code" description="Preenche o endereço." />
            <p wire:loading wire:target="fiscalRecipientDraft.zip" role="status" class="mt-1 text-xs text-neutral-500">Buscando endereço…</p>
            @error('fiscalRecipientDraft.zip') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-3">
            <flux:input wire:model.live.debounce.250ms="fiscalRecipientDraft.number" label="Número" />
            <button type="button" wire:click="$set('fiscalRecipientDraft.number', 'S/N')" class="mt-1 text-xs text-blue-600 hover:underline dark:text-blue-400">Sem número</button>
            @error('fiscalRecipientDraft.number') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        @unless($addressIncomplete)
            <p role="status" class="text-xs text-neutral-600 sm:col-span-6 dark:text-neutral-300">{{ $fiscalRecipientDraft['street'] }}, {{ $fiscalRecipientDraft['number'] ?: 'informe o número' }} · {{ $fiscalRecipientDraft['neighborhood'] }} · {{ $fiscalRecipientDraft['city'] }}/{{ $fiscalRecipientDraft['state'] }}</p>
        @endunless
        <details class="sm:col-span-6" @if($addressIncomplete || $errors->hasAny(['fiscalRecipientDraft.street', 'fiscalRecipientDraft.neighborhood', 'fiscalRecipientDraft.city', 'fiscalRecipientDraft.state', 'fiscalRecipientDraft.email'])) open @endif>
            <summary class="cursor-pointer text-xs font-medium text-blue-600 dark:text-blue-400">{{ $addressIncomplete ? 'Completar endereço' : 'Conferir endereço e dados opcionais' }}</summary>
            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-6">
                @foreach(['street' => ['Logradouro', 'sm:col-span-6'], 'neighborhood' => ['Bairro', 'sm:col-span-3'], 'city' => ['Município', 'sm:col-span-3'], 'state' => ['UF', 'sm:col-span-2'], 'complement' => ['Complemento', 'sm:col-span-4'], 'email' => ['E-mail para envio da nota', 'sm:col-span-6']] as $field => [$fieldLabel, $span])
                    <div class="{{ $span }}">
                        <flux:input wire:model.blur="fiscalRecipientDraft.{{ $field }}" :label="$fieldLabel" :maxlength="$field === 'state' ? 2 : null" />
                        @error('fiscalRecipientDraft.'.$field) <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
        </details>
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
    <p class="text-xs text-neutral-500 dark:text-neutral-400">Confira a situação da IE com o comprador. Ao salvar, a situação escolhida fica registrada como conferida.</p>
    @if($order->customer_id)
        <flux:checkbox wire:model="rememberFiscalRecipient" label="Usar estes dados nas próximas compras deste cliente" />
    @endif
    <div class="flex flex-wrap gap-2">
        @if($draftReadiness && $draftReadiness['decision']->model)
            <flux:button type="button" wire:click="saveFiscalRecipientAndIssue('{{ $draftReadiness['decision']->model }}')" wire:loading.attr="disabled" :disabled="! $draftReady" variant="primary" size="sm">
                <span wire:loading.remove wire:target="saveFiscalRecipientAndIssue">Salvar e emitir {{ \App\DTOs\FiscalEmissionDecision::label($draftReadiness['decision']->model) }}</span>
                <span wire:loading wire:target="saveFiscalRecipientAndIssue">Emitindo…</span>
            </flux:button>
        @endif
        <flux:button type="button" wire:click="saveFiscalRecipientAndContinue" wire:loading.attr="disabled" :variant="$draftReady ? 'ghost' : 'primary'" size="sm">Salvar dados e continuar</flux:button>
        <flux:button type="button" wire:click="$set('showFiscalRecipient', false)" size="sm" variant="ghost">Voltar</flux:button>
    </div>
</div>
