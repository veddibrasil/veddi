<div class="space-y-6"
    x-data
    x-init="$watch(() => $wire.deletingId, val => val ? $flux.modal('confirm-delete-company').show() : $flux.modal('confirm-delete-company').close())"
>
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Empresas</h1>
        <a href="{{ route('superadmin.companies.create') }}"
            class="inline-flex items-center px-4 py-2 bg-amber-500 text-white text-sm font-medium rounded-lg hover:bg-amber-600 transition">
            + Nova empresa
        </a>
    </div>

    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    @if(session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm dark:bg-red-900/30 dark:border-red-700 dark:text-red-400">
            {{ session('error') }}
        </div>
    @endif

    <div class="grid gap-3 sm:grid-cols-3">
        <flux:input wire:model.live.debounce.300ms="search" label="Buscar empresa" placeholder="Nome da empresa..." />
        <flux:select wire:model.live="planFilter" label="Plano">
            <option value="">Todos os planos</option>
            @foreach(\App\Enums\Plan::cases() as $plan)
                <option value="{{ $plan->value }}">{{ $plan->label() }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="missingModule" label="Oportunidade de módulo">
            <option value="">Todos os módulos</option>
            @foreach(\App\Services\Company\AddonModulePricing::MODULES as $key => $module)
                <option value="{{ $key }}">Sem {{ $module['label'] }}</option>
            @endforeach
        </flux:select>
    </div>

    <div class="bg-white border rounded-xl shadow-sm overflow-x-auto dark:bg-zinc-800 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3 text-left">Empresa</th>
                    <th class="px-4 py-3 text-left">Plano e mensalidade</th>
                    <th class="px-4 py-3 text-left">Módulos ativos</th>
                    <th class="px-4 py-3 text-center">Filiais</th>
                    <th class="px-4 py-3 text-center">Pedidos</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($companies as $company)
                    <tr class="hover:bg-neutral-50 transition dark:hover:bg-zinc-700/50">
                        <td class="px-4 py-3 font-medium text-neutral-800 dark:text-neutral-100">
                            <a href="{{ route('superadmin.companies.show', $company) }}" class="flex items-center gap-2 hover:text-amber-600 dark:hover:text-amber-400">
                                @if($company->logo_path)
                                    <img src="{{ $company->logo_url }}" alt="" class="w-7 h-7 rounded-full object-cover">
                                @else
                                    <div aria-hidden="true" class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-bold"
                                         style="background: {{ $company->primary_color }}">
                                        {{ mb_substr($company->name, 0, 1) }}
                                    </div>
                                @endif
                                {{ $company->name }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-neutral-700 dark:text-neutral-300">
                            <p class="font-medium">{{ $company->plan?->label() ?? 'Sem plano' }}</p>
                            <p class="whitespace-nowrap text-xs text-neutral-500 dark:text-neutral-400">R$ {{ number_format(($company->plan?->monthlyPrice() ?? 0) + \App\Services\Company\AddonModulePricing::extraFor($company)['amount'], 2, ',', '.') }}/mês de referência</p>
                            @if($company->pending_plan)
                                <p class="text-xs text-amber-700 dark:text-amber-400">Troca pendente: {{ $company->pending_plan->label() }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                @forelse(collect(\App\Services\Company\AddonModulePricing::MODULES)->filter(fn ($module) => $company->{$module['column']}) as $module)
                                    <span class="whitespace-nowrap rounded-full bg-emerald-50 px-2 py-1 text-xs text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $module['label'] }}</span>
                                @empty
                                    <span class="text-xs text-neutral-500 dark:text-neutral-400">Nenhum módulo</span>
                                @endforelse
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center text-neutral-700 dark:text-neutral-300">{{ $company->branches_count }} / {{ $company->plan?->maxBranches() ?? '—' }}</td>
                        <td class="px-4 py-3 text-center text-neutral-700 dark:text-neutral-300">
                            <p>{{ $company->orders_count }} no total</p>
                            <p class="whitespace-nowrap text-xs text-neutral-500 dark:text-neutral-400">{{ $company->confirmed_orders_this_month }} confirmados/mês{{ $company->plan?->maxOrdersPerMonth() !== null ? ' · faixa de '.$company->plan->maxOrdersPerMonth() : '' }}</p>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button wire:click="toggleActive({{ $company->id }})"
                                class="px-2 py-0.5 rounded-full text-xs font-medium {{ $company->active ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400' : 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-400' }}">
                                {{ $company->active ? 'Ativa' : 'Inativa' }}
                            </button>
                            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ $company->status }}</p>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('superadmin.companies.show', $company) }}"
                                class="text-neutral-500 hover:text-neutral-700 text-xs font-medium mr-3 dark:text-neutral-400 dark:hover:text-neutral-200">Detalhes e oportunidades</a>
                            <a href="{{ route('superadmin.companies.edit', $company) }}"
                                class="text-amber-600 hover:text-amber-800 text-xs font-medium mr-3 dark:text-amber-400 dark:hover:text-amber-300">Editar</a>
                            <button wire:click="confirmDelete({{ $company->id }})"
                                class="text-red-400 hover:text-red-600 text-xs dark:hover:text-red-300">Excluir</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-neutral-400 dark:text-neutral-500">Nenhuma empresa encontrada.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $companies->links() }}

    <flux:modal name="confirm-delete-company" class="max-w-sm">
        <div class="space-y-5">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                </div>
                <div>
                    <flux:heading size="lg">Excluir empresa?</flux:heading>
                    <flux:subheading class="mt-1">Todos os dados relacionados serão apagados permanentemente. Esta ação não pode ser desfeita.</flux:subheading>
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-1">
                <flux:modal.close>
                    <flux:button wire:click="cancelDelete" variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:modal.close>
                    <flux:button wire:click="delete" variant="danger">Excluir</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
