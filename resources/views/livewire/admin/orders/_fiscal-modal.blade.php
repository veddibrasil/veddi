{{--
    Emissão unificada: comprador (CPF/CNPJ, empresa conferida) -> documento (política da filial)
    -> pendências agrupadas com link para corrigir -> emitir. Salvar dados não emite nada.
--}}
<flux:modal wire:model.self="showFiscalModal" class="w-full max-w-2xl" aria-labelledby="fiscal-modal-title">
    @php
        $readiness = $showFiscalModal ? $this->fiscalReadiness : null;
        $decision = $readiness['decision'] ?? null;
        $issues = $readiness['issues'] ?? [];
        $recipient = $readiness['recipient'] ?? [];
        $documentType = \App\Services\Fiscal\FiscalRecipient::documentType($fiscalCustomerDocument);
        $isCompany = $documentType === \App\Services\Fiscal\FiscalRecipient::TYPE_CNPJ;
        $blockingIssues = collect($issues)->reject(fn ($issue) => $issue['group'] === 'policy')->values()->all();
        $ready = $decision && ! $decision->blocked() && $issues === [] && ! $showFiscalRecipient;
        $label = \App\DTOs\FiscalEmissionDecision::label($decision?->model);
    @endphp
    @if($readiness)
        <div class="space-y-5">
            <div>
                <flux:heading size="lg" id="fiscal-modal-title">Emitir nota fiscal · Pedido {{ $order->order_number }}</flux:heading>
                @if($readiness['issuer'])
                    <flux:text class="mt-1">
                        Emitente: {{ $readiness['issuer']['branch'] ?? 'filial' }} · CNPJ {{ $readiness['issuer']['cnpj'] }}
                        @if($readiness['issuer']['environment'] === 'homologacao') · <span class="font-medium text-amber-700 dark:text-amber-400">homologação, sem valor fiscal</span>@endif
                    </flux:text>
                @endif
            </div>

            @error('fiscalEmission')
                <div x-init="$el.focus()" tabindex="-1" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 outline-none dark:border-red-800/60 dark:bg-red-900/30 dark:text-red-200">
                    {{ $issues !== [] ? 'Não foi possível emitir. Resolva as pendências abaixo e verifique novamente.' : $message }}
                </div>
            @enderror

            {{-- 1. Comprador --}}
            <section class="space-y-2" aria-labelledby="fiscal-buyer-title">
                <h4 id="fiscal-buyer-title" class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">1. Comprador</h4>
                <div>
                    <flux:input wire:model.live.debounce.400ms="fiscalCustomerDocument" label="CPF ou CNPJ na nota" placeholder="Consumidor sem CPF"
                        maxlength="18" autocomplete="off" description="Aceita CNPJ com letras (alfanumérico)." />
                    @error('fiscalCustomerDocument') <p role="alert" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    <div wire:loading.flex wire:target="fiscalCustomerDocument" class="mt-1 items-center gap-1 text-xs text-neutral-500">
                        <flux:icon.loading class="size-3" /> Conferindo documento e consultando a empresa…
                    </div>
                </div>

                @if($showFiscalRecipient)
                    @include('livewire.admin.orders._fiscal-recipient-form')
                @elseif($isCompany && filled($recipient['name'] ?? null))
                    <div class="rounded-lg bg-neutral-50 px-3 py-2 text-sm dark:bg-zinc-900/40">
                        <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $recipient['name'] }}</p>
                        <p class="text-xs text-neutral-600 dark:text-neutral-300">
                            Endereço fiscal: {{ $recipient['street'] ?? '' }}, {{ $recipient['number'] ?? 'S/N' }}{{ filled($recipient['neighborhood'] ?? null) ? ' – '.$recipient['neighborhood'] : '' }} · {{ $recipient['city'] ?? '' }}/{{ $recipient['state'] ?? '' }}
                        </p>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">É o endereço da sede; pode ser diferente do endereço de entrega.</p>
                        <p class="mt-1 text-xs {{ ($recipient['ie_confirmed'] ?? false) ? 'text-neutral-600 dark:text-neutral-300' : 'text-amber-700 dark:text-amber-400' }}">
                            IE: {{ ['9' => 'não contribuinte', '1' => 'contribuinte de ICMS', '2' => 'isenta'][$recipient['ie_indicator'] ?? ''] ?? 'não informada' }}
                            · {{ ($recipient['ie_confirmed'] ?? false) ? 'conferida' : 'a conferir' }}
                        </p>
                        <button type="button" wire:click="openFiscalRecipient" class="mt-1 text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">Conferir dados da empresa</button>
                    </div>
                @elseif($documentType === \App\Services\Fiscal\FiscalRecipient::TYPE_CPF)
                    <p class="text-xs text-neutral-500 dark:text-neutral-400">CPF {{ \App\Services\Fiscal\FiscalRecipient::formatDocument($fiscalCustomerDocument) }}.</p>
                @endif
            </section>

            {{-- 2. Documento --}}
            <section class="space-y-2" aria-labelledby="fiscal-document-title">
                <h4 id="fiscal-document-title" class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">2. Documento</h4>
                @if($decision->hasChoice())
                    <fieldset class="flex flex-wrap gap-3">
                        <legend class="sr-only">Modelo da nota</legend>
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm dark:border-zinc-700 {{ $decision->model === '65' ? 'border-purple-500 ring-1 ring-purple-500' : '' }}">
                            <input type="radio" name="fiscal-model" value="nfce" wire:click="$set('fiscalDocumentChoice', 'nfce')" @checked($decision->model === '65')>
                            NFC-e com CNPJ
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm dark:border-zinc-700 {{ $decision->model === '55' ? 'border-purple-500 ring-1 ring-purple-500' : '' }}">
                            <input type="radio" name="fiscal-model" value="nfe" wire:click="$set('fiscalDocumentChoice', 'nfe')" @checked($decision->model === '55')>
                            NF-e
                        </label>
                    </fieldset>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $decision->reasons[0] ?? '' }} Use NF-e quando o comprador pedir.</p>
                @elseif($decision->blocked())
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-200">
                        @foreach($decision->reasons as $reason)
                            <p>{{ $reason }}</p>
                        @endforeach
                        @if(in_array('ie_confirmation', $decision->needs, true))
                            <div class="mt-2 flex flex-wrap gap-3">
                                <button type="button" wire:click="openFiscalRecipient" class="text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">Confirmar situação da IE</button>
                                <button type="button" wire:click="$set('fiscalDocumentChoice', 'nfe')" class="text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">Emitir como NF-e</button>
                            </div>
                        @elseif($fiscalDocumentChoice !== '')
                            <button type="button" wire:click="$set('fiscalDocumentChoice', '')" class="mt-2 text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">Voltar para a escolha automática</button>
                        @endif
                    </div>
                @else
                    <p class="text-sm text-neutral-700 dark:text-neutral-200"><span class="font-semibold">{{ $label }}</span> · {{ $decision->reasons[0] ?? '' }}</p>
                    @if(in_array('ie_confirmation', $decision->needs, true))
                        <button type="button" wire:click="openFiscalRecipient" class="text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">Conferir IE para emitir NFC-e com CNPJ</button>
                    @endif
                @endif
                <p class="text-xs text-neutral-500 dark:text-neutral-400">Venda para consumo do comprador (consumidor final). Revenda, industrialização e devolução não são emitidas por aqui.</p>
            </section>

            {{-- 3. Pendências --}}
            <section class="space-y-2" aria-labelledby="fiscal-ready-title">
                <h4 id="fiscal-ready-title" class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">3. Conferência</h4>
                @if($blockingIssues !== [])
                    @include('livewire.admin.fiscal._readiness', [
                        'issues' => $blockingIssues,
                        'canFix' => $canFixFiscalProducts,
                        'returnTo' => '/'.ltrim(parse_url(route('admin.orders.show', $order), PHP_URL_PATH), '/'),
                    ])
                @elseif($ready)
                    <p class="text-sm text-green-700 dark:text-green-400">Pronto para emitir {{ $label }}.</p>
                @else
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Resolva o comprador e o documento acima.</p>
                @endif
            </section>

            @unless($ready)
                <p class="text-xs text-neutral-500 dark:text-neutral-400">O botão de emitir libera quando não houver pendência. Depois de corrigir um cadastro, clique em "Verificar novamente".</p>
            @endunless
            <div class="flex flex-wrap items-center justify-between gap-3 border-t pt-4 dark:border-zinc-700">
                <flux:button type="button" wire:click="$refresh" size="sm" variant="ghost" icon="arrow-path">Verificar novamente</flux:button>
                <div class="flex gap-2">
                    <flux:button type="button" wire:click="closeFiscalModal" variant="ghost">Cancelar</flux:button>
                    <flux:button type="button" wire:click="issueFiscalNote" variant="primary" :disabled="! $ready" wire:loading.attr="disabled" wire:target="issueFiscalNote" class="disabled:cursor-not-allowed disabled:opacity-50" :aria-describedby="$ready ? null : 'fiscal-ready-title'">
                        <span wire:loading.remove wire:target="issueFiscalNote">Emitir {{ $ready ? $label : 'nota' }}</span>
                        <span wire:loading wire:target="issueFiscalNote">Enfileirando…</span>
                    </flux:button>
                </div>
            </div>
        </div>
    @endif
</flux:modal>
