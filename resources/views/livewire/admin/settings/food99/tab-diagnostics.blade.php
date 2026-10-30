{{-- Diagnóstico: ids da loja, token, último callback e requestId (o suporte da 99 pede), eventos recentes. --}}
<x-admin.form-card padding="p-6 space-y-5">
    <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
        <div x-data="{ copied: false }">
            <dt class="text-xs uppercase tracking-wide text-neutral-500">ID da loja (shop_id)</dt>
            <dd class="flex items-center gap-1 font-mono">
                <span class="break-all">{{ $data['shop_id'] ?? '—' }}</span>
                @if($data['shop_id'] ?? null)
                    <flux:button size="xs" variant="ghost" icon="document-duplicate" aria-label="Copiar ID da loja"
                        x-on:click="navigator.clipboard.writeText(@js($data['shop_id'])); copied = true; setTimeout(() => copied = false, 2000)" />
                    <span x-show="copied" x-cloak class="font-sans text-xs text-green-600 dark:text-green-400">Copiado</span>
                @endif
            </dd>
        </div>
        <div><dt class="text-xs uppercase tracking-wide text-neutral-500">app_shop_id</dt><dd class="font-mono">{{ $data['app_shop_id'] ?? '—' }}</dd></div>
        <div><dt class="text-xs uppercase tracking-wide text-neutral-500">Acesso válido até</dt><dd>{{ $data['token_expires_at'] ?? '—' }}</dd></div>
        <div><dt class="text-xs uppercase tracking-wide text-neutral-500">Último evento recebido</dt><dd>{{ $data['last_webhook_at'] ?? 'Nenhum ainda' }}</dd></div>
        <div class="sm:col-span-2"><dt class="text-xs uppercase tracking-wide text-neutral-500">Último requestId</dt><dd class="font-mono">{{ $data['last_request_id'] ?? '—' }}</dd></div>
    </dl>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                    <th class="py-2 pr-3">Quando</th><th class="py-2 pr-3">Evento</th><th class="py-2 pr-3">Pedido</th><th class="py-2 pr-3">Situação</th><th class="py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($this->recentEvents as $event)
                    <tr wire:key="food99-event-{{ $event->id }}">
                        <td class="py-2 pr-3 whitespace-nowrap">{{ $event->created_at->timezone(config('app.timezone'))->format('d/m H:i:s') }}</td>
                        <td class="py-2 pr-3 font-mono text-xs">{{ $event->event_type }}</td>
                        <td class="py-2 pr-3 font-mono text-xs">{{ $event->external_order_id ?? '—' }}</td>
                        <td class="py-2 pr-3">
                            {{ $event->status }}{{ $event->attempts > 1 ? " ({$event->attempts}x)" : '' }}
                            @if($event->error) <p class="text-xs text-red-600 dark:text-red-400">{{ $event->error }}</p> @endif
                        </td>
                        <td class="py-2 text-right">
                            @if($event->status === 'failed')
                                <flux:button size="sm" variant="ghost" wire:click="reprocessEvent({{ $event->id }})">Reprocessar</flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-3 text-neutral-500">Nenhum evento recebido.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin.form-card>
