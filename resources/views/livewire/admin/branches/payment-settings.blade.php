<div class="w-full space-y-6">
    <x-admin.unsaved-guard />
    <x-admin.page-header :title="'Pagamentos — ' . $branch->name" />
    <x-admin.branch-nav :branch="$branch" current="payments" />
    <x-admin.flash-status />

    <p class="text-sm text-neutral-500 dark:text-neutral-400">
        Escolha as formas de pagamento aceitas em cada modalidade desta filial. Mantenha ao menos uma opção por modalidade.
        As alterações valem para novos pedidos e para comandas no momento do fechamento.
    </p>

    <fieldset @disabled(! $canSave) class="min-w-0 space-y-6">
        <legend class="sr-only">Formas de pagamento da filial</legend>
        @foreach ($orderTypes as $channel => $types)
            @if ($channel === 'pdv' && ! $branch->company?->pdv_module_enabled)
                @continue
            @endif
            <x-admin.form-card :title="$channel === 'chat' ? 'Chat / cardápio público' : 'PDV'">
                @if ($channel === 'chat')
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Pagamentos online são feitos pelo chat. Ao receber, o cliente paga diretamente para a loja na entrega ou na retirada.</p>
                @endif
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    @foreach ($types as $type => $label)
                        <fieldset class="min-w-0 space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-zinc-700">
                            <legend class="px-1 text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $label }}</legend>
                            <flux:checkbox.group wire:model="methods.{{ $channel }}.{{ $type }}" aria-label="{{ $label }} — {{ $channel === 'chat' ? 'Chat' : 'PDV' }}">
                                @foreach ($methodLabels[$channel] as $method => $methodLabel)
                                    <flux:checkbox :value="$method" :label="$methodLabel" />
                                @endforeach
                            </flux:checkbox.group>
                            @error("methods.$channel.$type") <p class="text-xs text-red-600 dark:text-red-400" role="alert">{{ $message }}</p> @enderror
                            @error("methods.$channel.$type.*") <p class="text-xs text-red-600 dark:text-red-400" role="alert">{{ $message }}</p> @enderror
                        </fieldset>
                    @endforeach
                </div>
            </x-admin.form-card>
        @endforeach
    </fieldset>

    <x-admin.form-actions save-label="Salvar pagamentos" :cancel-route="route('admin.branches.index')" cancel-label="Voltar à lista" :can-save="$canSave" />
</div>
