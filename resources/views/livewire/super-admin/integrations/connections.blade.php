@php
    $stateLabels = ['connected' => 'Conectada', 'paused' => 'Suspensa', 'error' => 'Reconexão necessária', 'pending' => 'Em andamento'];
    $stateColors = ['connected' => 'green', 'paused' => 'yellow', 'error' => 'red', 'pending' => 'amber'];
    $tz = config('app.timezone');
@endphp

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Integrações</h1>
        <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Lojas do iFood e da 99Food conectadas em todas as empresas.</p>
    </div>

    @if($notice)
        <div role="alert" class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm dark:bg-red-900/30 dark:border-red-700 dark:text-red-400">
            {{ $notice }}
        </div>
    @endif

    <div class="flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Buscar empresa, filial ou loja..." class="max-w-sm" />
        <flux:select wire:model.live="channel" class="max-w-40" aria-label="Canal">
            <flux:select.option value="">Todos os canais</flux:select.option>
            <flux:select.option value="ifood">iFood</flux:select.option>
            <flux:select.option value="99food">99Food</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="state" class="max-w-52" aria-label="Situação">
            <flux:select.option value="">Todas as situações</flux:select.option>
            @foreach($stateLabels as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="bg-white border rounded-xl shadow-sm overflow-x-auto dark:bg-zinc-800 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3 text-left">Empresa</th>
                    <th class="px-4 py-3 text-left">Filial</th>
                    <th class="px-4 py-3 text-left">Canal</th>
                    <th class="px-4 py-3 text-left">Loja</th>
                    <th class="px-4 py-3 text-center">Situação</th>
                    <th class="px-4 py-3 text-left">Último evento</th>
                    <th class="px-4 py-3 text-left">Acesso até</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($connections as $connection)
                    <tr wire:key="{{ $connection['key'] }}" class="hover:bg-neutral-50 transition dark:hover:bg-zinc-700/50">
                        <td class="px-4 py-3 font-medium text-neutral-800 dark:text-neutral-100">
                            <a href="{{ route('superadmin.companies.show', $connection['company_id']) }}" class="hover:text-amber-600 dark:hover:text-amber-400">{{ $connection['company'] }}</a>
                        </td>
                        <td class="px-4 py-3 text-neutral-700 dark:text-neutral-300">{{ $connection['branch'] }}</td>
                        <td class="px-4 py-3 text-neutral-700 dark:text-neutral-300">{{ $connection['channel_label'] }}</td>
                        <td class="px-4 py-3 text-neutral-700 dark:text-neutral-300">
                            <p>{{ $connection['store'] }}</p>
                            @if($connection['store_id'] !== '')
                                <p class="font-mono text-xs text-neutral-400 dark:text-neutral-500">{{ $connection['store_id'] }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            <flux:badge size="sm" :color="$stateColors[$connection['state']]">{{ $stateLabels[$connection['state']] }}</flux:badge>
                        </td>
                        <td class="px-4 py-3 text-neutral-700 dark:text-neutral-300">{{ $connection['last_event_at']?->timezone($tz)->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="px-4 py-3 text-neutral-700 dark:text-neutral-300">{{ $connection['token_expires_at']?->timezone($tz)->format('d/m/Y H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-neutral-400 dark:text-neutral-500">Nenhuma loja conectada.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $connections->links() }}

    @if($food99Enabled)
        <x-admin.form-card padding="p-6 space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-neutral-800 dark:text-neutral-100">Lojas da 99Food sem filial</h2>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Autorizaram o app VEDDI, mas ainda não foram ligadas a nenhuma filial.</p>
                </div>
                <flux:button size="sm" icon="arrow-path" wire:click="loadUnlinkedShops" wire:target="loadUnlinkedShops">Consultar</flux:button>
            </div>

            @if($unlinkedShops)
                <ul class="divide-y divide-neutral-100 text-sm dark:divide-zinc-700">
                    @foreach($unlinkedShops as $shop)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="unlinked-{{ $shop['shop_id'] }}">
                            <span class="text-neutral-800 dark:text-neutral-100">{{ $shop['shop_name'] ?? 'Loja' }}</span>
                            <span class="font-mono text-xs text-neutral-500 dark:text-neutral-400">{{ $shop['shop_id'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @elseif($unlinkedLoaded)
                <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhuma loja aguardando vínculo.</p>
            @endif
        </x-admin.form-card>
    @endif
</div>
