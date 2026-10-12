@php
    $statusLabels = [
        \App\Models\WhatsAppConnection::STATUS_PENDING => 'Pendente',
        \App\Models\WhatsAppConnection::STATUS_PROVISIONING => 'Provisionando',
        \App\Models\WhatsAppConnection::STATUS_TEMPLATES_PENDING => 'Aguardando templates',
        \App\Models\WhatsAppConnection::STATUS_ACTIVE => 'Ativa',
        \App\Models\WhatsAppConnection::STATUS_ERROR => 'Erro',
        \App\Models\WhatsAppConnection::STATUS_DISCONNECTED => 'Desconectada',
    ];
    $statusColors = [
        \App\Models\WhatsAppConnection::STATUS_PENDING => 'amber',
        \App\Models\WhatsAppConnection::STATUS_PROVISIONING => 'amber',
        \App\Models\WhatsAppConnection::STATUS_TEMPLATES_PENDING => 'amber',
        \App\Models\WhatsAppConnection::STATUS_ACTIVE => 'green',
        \App\Models\WhatsAppConnection::STATUS_ERROR => 'red',
        \App\Models\WhatsAppConnection::STATUS_DISCONNECTED => 'zinc',
    ];
    $qualityColors = ['GREEN' => 'green', 'YELLOW' => 'amber', 'RED' => 'red'];
@endphp

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">WhatsApp</h1>
        <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Conexões de WhatsApp (Cloud API) de todas as empresas.</p>
    </div>

    @if($notice)
        <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ $notice }}
        </div>
    @endif

    <div class="flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Buscar empresa ou número..." class="max-w-sm" />
        <flux:select wire:model.live="status" class="max-w-52">
            <flux:select.option value="">Todos os status</flux:select.option>
            @foreach($statusLabels as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="bg-white border rounded-xl shadow-sm overflow-x-auto dark:bg-zinc-800 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3 text-left">Empresa</th>
                    <th class="px-4 py-3 text-left">Número</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-center">Qualidade</th>
                    <th class="px-4 py-3 text-left">Tipo</th>
                    <th class="px-4 py-3 text-center">Templates</th>
                    <th class="px-4 py-3 text-left">Último erro</th>
                    <th class="px-4 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($connections as $connection)
                    <tr wire:key="connection-{{ $connection->id }}" class="hover:bg-neutral-50 transition dark:hover:bg-zinc-700/50">
                        <td class="px-4 py-3 font-medium text-neutral-800 dark:text-neutral-100">
                            @if($connection->company)
                                <a href="{{ route('superadmin.companies.show', $connection->company_id) }}" class="hover:text-amber-600 dark:hover:text-amber-400">
                                    {{ $connection->company->name }}
                                </a>
                            @else
                                <span class="text-neutral-400">Empresa #{{ $connection->company_id }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-neutral-700 dark:text-neutral-300">
                            <p>{{ $connection->display_phone_number ?: '—' }}</p>
                            @if($connection->verified_name)
                                <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $connection->verified_name }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            <flux:badge size="sm" :color="$statusColors[$connection->status] ?? 'zinc'">{{ $statusLabels[$connection->status] ?? $connection->status }}</flux:badge>
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($connection->quality_rating)
                                <flux:badge size="sm" :color="$qualityColors[$connection->quality_rating] ?? 'zinc'">{{ $connection->quality_rating }}</flux:badge>
                            @else
                                <span class="text-neutral-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-neutral-700 dark:text-neutral-300">
                            {{ $connection->isCoexistence() ? 'Coexistência' : 'Número novo' }}
                        </td>
                        <td class="px-4 py-3 text-center text-neutral-700 dark:text-neutral-300">
                            {{ $connection->approved_templates_count }}/{{ $requiredTemplates }}
                        </td>
                        <td class="px-4 py-3 text-xs text-neutral-500 max-w-xs dark:text-neutral-400">
                            @if($connection->last_error)
                                <span title="{{ $connection->last_error }}" class="line-clamp-2">{{ $connection->last_error }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if(in_array($connection->status, $syncable, true))
                                <flux:button size="sm" wire:click="syncTemplates({{ $connection->id }})" wire:loading.attr="disabled" wire:target="syncTemplates({{ $connection->id }})">
                                    Sincronizar templates
                                </flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-neutral-400 dark:text-neutral-500">Nenhuma conexão de WhatsApp encontrada.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $connections->links() }}
</div>
