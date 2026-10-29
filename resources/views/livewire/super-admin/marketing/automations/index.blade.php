<div class="space-y-6">
    @include('livewire.super-admin.marketing.partials.header', [
        'title' => 'Automações',
        'description' => 'Sequências disparadas por gatilho. Cada pessoa entra uma vez e sai quando o gatilho deixa de valer.',
        'actionRoute' => 'superadmin.marketing.automations.create',
        'actionLabel' => 'Nova automação',
    ])

    @include('livewire.super-admin.marketing.partials.notice')

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs text-neutral-500 dark:text-neutral-400">Prontas: boas-vindas a empresas novas, recuperar cadastro abandonado e reengajar empresa sem pedidos.</p>
        <flux:button icon="sparkles" wire:click="installDefaults">Adicionar automações padrão</flux:button>
    </div>

    <div class="bg-white border rounded-xl shadow-sm overflow-x-auto dark:bg-zinc-800 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3 text-left">Automação</th>
                    <th class="px-4 py-3 text-left">Gatilho</th>
                    <th class="px-4 py-3 text-right">E-mails</th>
                    <th class="px-4 py-3 text-right">Na sequência</th>
                    <th class="px-4 py-3 text-right">Concluíram</th>
                    <th class="px-4 py-3 text-right">Saíram</th>
                    <th class="px-4 py-3 text-center">Ativa</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($automations as $automation)
                    <tr wire:key="automation-{{ $automation->id }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('superadmin.marketing.automations.edit', $automation) }}" wire:navigate class="font-medium text-neutral-800 hover:text-amber-600 dark:text-neutral-100 dark:hover:text-amber-400">{{ $automation->name }}</a>
                        </td>
                        <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">
                            {{ $automation->trigger->label() }}
                            <span class="text-xs text-neutral-400">({{ $automation->threshold() }} {{ $automation->trigger->thresholdUnit() }})</span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $automation->steps_count }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $automation->active_count }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $automation->completed_count }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $automation->exited_count }}</td>
                        <td class="px-4 py-3 text-center">
                            <flux:switch :checked="$automation->active" wire:click="toggle({{ $automation->id }})" aria-label="Ativar ou pausar {{ $automation->name }}" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-neutral-400 dark:text-neutral-500">Nenhuma automação ainda. Use “Adicionar automações padrão” (os templates necessários entram junto) ou crie a sua.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
