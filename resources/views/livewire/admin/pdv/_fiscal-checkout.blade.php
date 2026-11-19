{{-- Nota fiscal no pagamento (Terminal e Comandas): "Emitir e imprimir" + CPF/CNPJ na nota.
     O documento não depende de cliente cadastrado; CNPJ abre os dados da empresa. O modelo
     (NFC-e/NF-e) vem da FiscalEmissionPolicy, a mesma do pedido e do job. --}}
@php
    $fiscalState = $issueFiscalNote ? $this->fiscalDocumentState : null;
    $fiscalDecision = in_array($fiscalState, ['cnpj', 'cnpj_incomplete'], true) ? $this->fiscalDecision : null;
@endphp
<div class="space-y-2 rounded-xl border px-3 py-2 dark:border-zinc-700">
    <label class="flex cursor-pointer items-center gap-2">
        <flux:checkbox wire:model.live="issueFiscalNote" aria-label="Emitir e imprimir nota fiscal" />
        <span class="text-sm">Emitir e imprimir nota fiscal</span>
    </label>

    @if ($fiscalAutoIssueOn && ! $issueFiscalNote)
        <p class="text-xs text-amber-700 dark:text-amber-300">Emissão automática ligada nesta filial: desmarcado, esta venda fica sem nota (Controle de notas).</p>
    @endif

    @if ($issueFiscalNote)
        <div class="space-y-1.5">
            <div class="flex items-end gap-2">
                <div class="min-w-0 flex-1">
                    <flux:input
                        wire:model.live.debounce.400ms="fiscalDocument"
                        x-mask:dynamic="/[a-zA-Z]/.test($input) || $input.replace(/[^0-9a-zA-Z]/g, '').length > 11 ? '**.***.***/****-99' : '999.999.999-999'"
                        label="CPF/CNPJ na nota"
                        badge="Opcional"
                        placeholder="Consumidor sem CPF"
                        inputmode="text"
                        autocapitalize="characters"
                        autocomplete="off"
                        size="sm"
                    />
                </div>
                @if (filled($fiscalDocument))
                    <flux:button wire:click="clearFiscalDocument" size="sm" variant="ghost" icon="x-mark" aria-label="Remover CPF/CNPJ da nota" />
                @endif
            </div>

            <div wire:loading.flex wire:target="fiscalDocument" class="items-center gap-1 text-xs text-neutral-500">
                <flux:icon.loading class="size-3" /> Conferindo documento…
            </div>

            <div wire:loading.remove wire:target="fiscalDocument">
                @switch($fiscalState)
                    @case('empty')
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">NFC-e sem identificar o consumidor.</p>
                        @break
                    @case('invalid')
                        <p class="text-xs text-red-600 dark:text-red-400">{{ \App\Services\Fiscal\FiscalRecipient::documentType($fiscalDocument) === 'cnpj' ? 'CNPJ' : 'CPF' }} inválido. Confira os caracteres.</p>
                        @break
                    @case('cpf')
                        <p class="text-xs text-green-700 dark:text-green-400">NFC-e com CPF do consumidor.</p>
                        @break
                    @case('cnpj')
                    @case('cnpj_incomplete')
                        <div class="rounded-lg bg-neutral-50 px-2.5 py-2 text-xs dark:bg-zinc-800/60">
                            <p class="font-semibold text-neutral-800 dark:text-neutral-100">{{ $fiscalDecision?->model ? \App\DTOs\FiscalEmissionDecision::label($fiscalDecision->model) : 'CNPJ' }} · {{ ($fiscalCompany['name'] ?? '') ?: 'Empresa sem razão social' }}</p>
                            @if (filled($fiscalCompany['street'] ?? null))
                                <p class="text-neutral-500 dark:text-neutral-400">
                                    {{ $fiscalCompany['street'] }}, {{ $fiscalCompany['number'] ?: 'S/N' }} · {{ $fiscalCompany['city'] }}/{{ $fiscalCompany['state'] }}
                                </p>
                            @endif
                            @if ($fiscalCompanyNotice)
                                <p class="mt-1 text-amber-700 dark:text-amber-400">{{ $fiscalCompanyNotice }}</p>
                            @endif
                            <div class="mt-1 flex items-center justify-between gap-2">
                                @if ($fiscalState === 'cnpj_incomplete')
                                    <span class="text-amber-700 dark:text-amber-400">Faltam dados da empresa.</span>
                                @else
                                    <span class="text-neutral-500 dark:text-neutral-400">IE: {{ ['9' => 'não contribuinte', '1' => 'contribuinte', '2' => 'isenta'][$fiscalCompany['ie_indicator'] ?? ''] ?? '—' }}{{ ($fiscalCompany['ie_confirmed'] ?? false) ? '' : ' (a conferir)' }}</span>
                                @endif
                                <button type="button" wire:click="openFiscalCompanyForm" class="font-medium text-blue-600 hover:underline dark:text-blue-400">
                                    {{ $fiscalState === 'cnpj_incomplete' ? 'Completar dados' : 'Conferir dados' }}
                                </button>
                            </div>
                            @if ($fiscalDecision?->hasChoice())
                                <fieldset class="mt-2 flex flex-wrap gap-3">
                                    <legend class="sr-only">Modelo da nota</legend>
                                    <label class="flex items-center gap-1.5">
                                        <input type="radio" name="pdv-fiscal-model" wire:click="$set('fiscalDocumentChoice', 'nfce')" @checked($fiscalDecision->model === '65')> NFC-e com CNPJ
                                    </label>
                                    <label class="flex items-center gap-1.5">
                                        <input type="radio" name="pdv-fiscal-model" wire:click="$set('fiscalDocumentChoice', 'nfe')" @checked($fiscalDecision->model === '55')> NF-e (se o comprador pedir)
                                    </label>
                                </fieldset>
                            @elseif ($fiscalDecision && $fiscalState === 'cnpj' && ($fiscalDecision->blocked() || $fiscalDecision->needs !== []))
                                <p class="mt-1 {{ $fiscalDecision->blocked() ? 'text-amber-700 dark:text-amber-400' : 'text-neutral-500 dark:text-neutral-400' }}">{{ $fiscalDecision->reasons[0] ?? '' }}</p>
                                <div class="mt-1 flex flex-wrap gap-3">
                                    @if (in_array('ie_confirmation', $fiscalDecision->needs, true))
                                        <button type="button" wire:click="openFiscalCompanyForm" class="font-medium text-blue-600 hover:underline dark:text-blue-400">Conferir IE para NFC-e</button>
                                    @endif
                                    @if ($fiscalDocumentChoice !== '')
                                        <button type="button" wire:click="$set('fiscalDocumentChoice', '')" class="font-medium text-blue-600 hover:underline dark:text-blue-400">Voltar para a escolha automática</button>
                                    @endif
                                </div>
                            @endif
                        </div>
                        @break
                @endswitch
            </div>

            @if ($customerId && in_array($fiscalState, ['cpf', 'cnpj'], true))
                <flux:checkbox wire:model="rememberFiscalData" label="Salvar no cadastro de {{ $customerName }}" />
            @endif
        </div>
    @endif
</div>

{{-- Dados da empresa pra NF-e: vêm do cadastro do cliente ou da consulta do CNPJ; caixa confere --}}
<flux:modal wire:model.self="showFiscalCompanyForm" class="w-full max-w-xl">
    <div class="space-y-4">
        <div>
            <flux:heading size="lg">Dados da empresa para a nota</flux:heading>
            <flux:text class="mt-1">CNPJ {{ \App\Services\Fiscal\FiscalRecipient::formatDocument($fiscalDocument) }}. O endereço é o fiscal da empresa, pode ser diferente do de entrega.</flux:text>
        </div>

        @if ($fiscalCompanyNotice)
            <flux:callout variant="warning" icon="exclamation-triangle" :text="$fiscalCompanyNotice" />
        @endif

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-6">
            <div class="sm:col-span-6"><flux:input wire:model="fiscalCompany.name" label="Razão social" /></div>
            <div class="sm:col-span-2"><flux:input wire:model="fiscalCompany.zip" label="CEP" x-mask="99999-999" /></div>
            <div class="sm:col-span-3"><flux:input wire:model="fiscalCompany.city" label="Município" /></div>
            <div class="sm:col-span-1"><flux:input wire:model="fiscalCompany.state" label="UF" maxlength="2" /></div>
            <div class="sm:col-span-4"><flux:input wire:model="fiscalCompany.street" label="Logradouro" /></div>
            <div class="sm:col-span-2"><flux:input wire:model="fiscalCompany.number" label="Número" /></div>
            <div class="sm:col-span-3"><flux:input wire:model="fiscalCompany.neighborhood" label="Bairro" /></div>
            <div class="sm:col-span-3"><flux:input wire:model="fiscalCompany.complement" label="Complemento" badge="Opcional" /></div>
            <div class="sm:col-span-6"><flux:input wire:model="fiscalCompany.email" label="E-mail para envio da nota" badge="Opcional" type="email" /></div>
            <div class="sm:col-span-3">
                <flux:select wire:model.live="fiscalCompany.ie_indicator" label="Inscrição estadual">
                    <option value="9">Não contribuinte</option>
                    <option value="1">Contribuinte de ICMS</option>
                    <option value="2">Isenta</option>
                </flux:select>
            </div>
            @if (($fiscalCompany['ie_indicator'] ?? '') === '1')
                <div class="sm:col-span-3"><flux:input wire:model="fiscalCompany.ie" label="Número da IE" /></div>
            @endif
        </div>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">Voltar</flux:button>
            </flux:modal.close>
            <flux:button wire:click="confirmFiscalCompany" variant="primary">Usar estes dados</flux:button>
        </div>
    </div>
</flux:modal>
