@php
    $operationLabels = ['DELIVERY' => 'Entrega', 'TAKEOUT' => 'Retirada', 'INDOOR' => 'Consumo no local'];
    $stateBadges = [
        'OK' => ['green', 'Aberta'],
        'WARNING' => ['yellow', 'Aberta com alerta'],
        'CLOSED' => ['zinc', 'Fechada'],
        'ERROR' => ['red', 'Indisponível'],
    ];
    $address = $merchantDetails['address'] ?? null;
    $addressLine = is_array($address)
        ? collect([
            trim(($address['street'] ?? '').' '.($address['number'] ?? '')),
            $address['district'] ?? null,
            trim(($address['city'] ?? '').(isset($address['state']) ? ' / '.$address['state'] : '')),
        ])->filter()->implode(' · ')
        : null;
    $operations = array_values(array_filter($merchantStatus, 'is_array'));
@endphp

@if($homologationMode)
    {{-- Ferramentas do roteiro de homologação (IFOOD_HOMOLOGATION_MODE): respostas completas da API. --}}
    <x-admin.form-card padding="p-6 space-y-4">
        <div>
            <h2 class="font-semibold text-neutral-800 dark:text-neutral-100">Homologação — operação da loja no iFood</h2>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">Client ID: {{ config('ifood.partner_client_id') ?: 'Não configurado' }}</p>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">Última operação: {{ $merchantCheckedAt ?? 'Ainda não consultado' }} · {{ config('ifood.timezone') }}</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <flux:button wire:click="consultMerchantStores" wire:target="consultMerchantStores">Listar todas as lojas</flux:button>
            <flux:button wire:click="consultMerchant" wire:target="consultMerchant">Consultar lojas, detalhes e disponibilidade</flux:button>
        </div>
        @if($merchantStores)
            <h3 class="font-medium text-neutral-800 dark:text-neutral-100">Lojas vinculadas ao aplicativo</h3>
            <ul class="space-y-2">
                @foreach($merchantStores as $store)
                    <li class="text-sm text-neutral-700 dark:text-neutral-200">{{ $store['name'] ?? 'Loja' }} — {{ $store['id'] }}</li>
                @endforeach
            </ul>
        @elseif($merchantStoresLoaded)
            <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhuma loja autorizada para este aplicativo.</p>
        @endif
        @if($merchantDetails)
            <h3 class="font-medium text-neutral-800 dark:text-neutral-100">Detalhes completos da loja desta filial</h3>
            @include('livewire.admin.settings.ifood-data', ['data' => $merchantDetails])
        @endif
        @if($merchantStatus)
            <h3 class="font-medium text-neutral-800 dark:text-neutral-100">Disponibilidade e validações</h3>
            @include('livewire.admin.settings.ifood-data', ['data' => $merchantStatus])
        @endif
    </x-admin.form-card>
@endif

<x-admin.form-card padding="p-6 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-semibold text-neutral-800 dark:text-neutral-100">Situação da loja no app do iFood</h2>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">O que o cliente vê agora em cada forma de pedido.</p>
        </div>
        <flux:button size="sm" icon="arrow-path" wire:click="loadStoreOverview" wire:target="loadStoreOverview">Atualizar</flux:button>
    </div>

    @if($operations)
        <ul class="divide-y divide-neutral-100 dark:divide-zinc-700">
            @foreach($operations as $operation)
                @php
                    [$badgeColor, $badgeLabel] = $stateBadges[$operation['state'] ?? ''] ?? ['zinc', $operation['state'] ?? '—'];
                    $operationName = strtoupper((string) ($operation['operation'] ?? ''));
                    $message = $operation['message'] ?? [];
                    $problems = collect($operation['validations'] ?? [])->filter(fn ($validation) => is_array($validation) && ($validation['state'] ?? 'OK') !== 'OK');
                @endphp
                <li class="space-y-1 py-3 first:pt-0 last:pb-0">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-medium text-neutral-800 dark:text-neutral-100">{{ $operationLabels[$operationName] ?? ($operationName !== '' ? ucfirst(strtolower($operationName)) : 'Loja') }}</span>
                        <flux:badge size="sm" :color="$badgeColor">{{ $badgeLabel }}</flux:badge>
                    </div>
                    @if(! empty($message['title']) || ! empty($message['subtitle']))
                        <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ collect([$message['title'] ?? null, $message['subtitle'] ?? null])->filter()->implode(' — ') }}</p>
                    @endif
                    @foreach($problems as $problem)
                        <p class="text-sm text-yellow-800 dark:text-yellow-300">
                            {{ $problem['message']['title'] ?? $problem['message']['description'] ?? $problem['code'] ?? 'Validação pendente' }}
                        </p>
                    @endforeach
                </li>
            @endforeach
        </ul>
    @elseif($merchantCheckedAt === null)
        <div class="space-y-2" wire:loading.remove wire:target="loadStoreOverview, loadCurrentTab">
            <p class="text-sm text-neutral-500 dark:text-neutral-400">Clique em Atualizar para ver a situação da loja no iFood.</p>
        </div>
    @endif

    @if($merchantDetails)
        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 border-t border-neutral-100 pt-4 text-sm dark:border-zinc-700 sm:grid-cols-2">
            <div>
                <dt class="text-xs text-neutral-600 dark:text-neutral-400">Nome no iFood</dt>
                <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ $merchantDetails['name'] ?? '—' }}</dd>
            </div>
            @if(! empty($merchantDetails['corporateName']))
                <div>
                    <dt class="text-xs text-neutral-600 dark:text-neutral-400">Razão social</dt>
                    <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ $merchantDetails['corporateName'] }}</dd>
                </div>
            @endif
            @if($addressLine)
                <div class="sm:col-span-2">
                    <dt class="text-xs text-neutral-600 dark:text-neutral-400">Endereço</dt>
                    <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ $addressLine }}</dd>
                </div>
            @endif
        </dl>

        @unless($homologationMode)
            <details class="group rounded-lg border border-neutral-200 dark:border-zinc-700">
                <summary class="cursor-pointer select-none px-4 py-3 text-sm font-medium text-neutral-700 dark:text-neutral-200">Ver resposta completa do iFood</summary>
                <div class="space-y-3 px-4 pb-4">
                    @include('livewire.admin.settings.ifood-data', ['data' => $merchantDetails])
                    @if($merchantStatus)
                        @include('livewire.admin.settings.ifood-data', ['data' => $merchantStatus])
                    @endif
                </div>
            </details>
        @endunless
    @endif

    <p class="text-xs text-neutral-500 dark:text-neutral-400">
        Para fechar a loja por algumas horas, use a aba Pausas. Os horários de funcionamento no iFood ficam na aba Horários.
    </p>
</x-admin.form-card>
