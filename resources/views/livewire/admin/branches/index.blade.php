
<div class="space-y-4"
    x-data="{}"
    x-init="$watch(() => $wire.deletingId, val => val ? $flux.modal('confirm-delete-branch').show() : $flux.modal('confirm-delete-branch').close())">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">{{ $branchScoped ? 'Minha filial' : 'Filiais' }}</h1>
            @if(!$isSuperAdmin && !$branchScoped && $branchLimit > 0)
                <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">
                    {{ $branchCount }}/{{ $branchLimit }} filiai{{ $branchLimit > 1 ? 's' : '' }} do plano
                </p>
            @endif
        </div>

        @if($canCreate)
            @if($canCreateMoreBranches)
                <a href="{{ route('admin.branches.create') }}"
                    class="inline-flex items-center gap-1 bg-amber-500 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-amber-600 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500">
                    + Nova filial
                </a>
            @else
                {{-- Aviso sempre visível (não só no hover): funciona no celular e no teclado. --}}
                <div class="max-w-xs sm:text-right">
                    <span aria-disabled="true" class="inline-flex items-center gap-1.5 bg-neutral-100 text-neutral-500 text-sm font-medium px-4 py-2 rounded-lg cursor-not-allowed dark:bg-zinc-700 dark:text-zinc-400">
                        <flux:icon name="lock-closed" class="size-4" />
                        + Nova filial
                    </span>
                    <p class="mt-1.5 text-xs text-neutral-600 dark:text-neutral-400">
                        Limite de {{ $branchLimit }} filial(ais) do plano atingido.
                        @if($branchLimit < 5)
                            Faça upgrade para o <span class="font-bold text-amber-700 dark:text-amber-400">PRO</span> ou <span class="font-bold text-blue-700 dark:text-blue-400">PDV</span> para ter mais.
                            <a href="{{ route('admin.billing') }}" class="font-bold text-amber-700 hover:text-amber-800 underline underline-offset-2 dark:text-amber-400 dark:hover:text-amber-300">Ver planos →</a>
                        @endif
                    </p>
                </div>
            @endif
        @endif
    </div>

    <x-admin.flash-status />

    <div class="bg-white border rounded-xl shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
        {{-- Cabeçalho de colunas --}}
        <div class="hidden sm:flex items-center justify-between px-4 py-2 border-b bg-neutral-50 rounded-t-xl dark:bg-zinc-700/50 dark:border-zinc-700">
            <span class="text-xs font-medium text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Filial / Endereço</span>
            <span class="text-xs font-medium text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Situação e configurações</span>
        </div>

        <div class="divide-y dark:divide-zinc-700">
            @forelse ($branches as $branch)
                @php
                    $companyBranchCount = $isSuperAdmin
                        ? ($branchCountPerCompany[$branch->company_id] ?? 1)
                        : $branchCount;
                    $isLastBranch = $companyBranchCount <= 1;
                    $showPdv = (bool) $branch->company?->pdv_module_enabled;
                @endphp
                <div class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="font-semibold text-neutral-800 dark:text-neutral-100">{{ $branch->name }}</p>
                        @if($isSuperAdmin && $branch->company)
                            <p class="text-xs font-medium text-amber-700 dark:text-amber-400">{{ $branch->company->name }}</p>
                        @endif
                        <p class="text-sm text-neutral-600 dark:text-neutral-400">{{ $branch->address }}, {{ $branch->city }}</p>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $branch->opens_at }} – {{ $branch->closes_at }}
                            @if ($branch->phone) · {{ $branch->phone }} @endif
                        </p>
                    </div>

                    {{-- Colunas de largura fixa no desktop e 2 colunas iguais no mobile: os botões ficam do mesmo tamanho e alinhados em todas as linhas, seja "Ativa" ou "Inativa". --}}
                    <div class="grid grid-cols-2 gap-2 shrink-0 sm:flex sm:items-center">
                        <div class="col-span-2 sm:col-span-1 sm:flex sm:w-[4.5rem] sm:justify-center">
                            <span class="inline-block text-xs px-2 py-0.5 rounded-full {{ $branch->active ? 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-400' : 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-400' }}">
                                {{ $branch->active ? 'Ativa' : 'Inativa' }}
                            </span>
                        </div>

                        <flux:button size="sm" icon="pencil-square" class="w-full justify-center sm:w-36" :href="route('admin.branches.edit', $branch)">
                            {{ $canUpdate ? 'Editar' : 'Ver dados' }}
                        </flux:button>

                        <flux:dropdown position="bottom" align="end">
                            <flux:button size="sm" icon="cog-6-tooth" icon:trailing="chevron-down" class="w-full justify-center sm:w-36">Configurar</flux:button>

                            <flux:menu>
                                <flux:menu.item icon="truck" :href="route('admin.branches.delivery', $branch)">Entrega</flux:menu.item>
                                <flux:menu.item icon="calendar-days" :href="route('admin.branches.pauses', $branch)">Pausas e feriados</flux:menu.item>
                                @if($showPdv)
                                    <flux:menu.separator />
                                    <flux:menu.group heading="PDV">
                                        <flux:menu.item icon="printer" :href="route('admin.branches.printer', $branch)">Impressoras</flux:menu.item>
                                        <flux:menu.item icon="currency-dollar" :href="route('admin.branches.service-charges', $branch)">Taxa de serviço e couvert</flux:menu.item>
                                        <flux:menu.item icon="table-cells" :href="route('admin.branches.tables', $branch)">Mesas e comandas</flux:menu.item>
                                    </flux:menu.group>
                                @endif
                                @if($canDelete)
                                    <flux:menu.separator />
                                    @if($isLastBranch)
                                        <flux:menu.item icon="trash" disabled>Excluir (única filial)</flux:menu.item>
                                    @else
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $branch->id }})">Excluir filial</flux:menu.item>
                                    @endif
                                @endif
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </div>
            @empty
                <div class="px-4 py-12 text-center">
                    <div class="text-3xl mb-2" aria-hidden="true">🏪</div>
                    <p class="text-sm font-medium text-neutral-700 dark:text-neutral-200">Nenhuma filial cadastrada.</p>
                    <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">Cadastre a primeira filial para começar a receber pedidos.</p>
                    @if($canCreate && $canCreateMoreBranches)
                        <a href="{{ route('admin.branches.create') }}"
                            class="mt-4 inline-flex items-center gap-1 bg-amber-500 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-amber-600 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500">
                            + Nova filial
                        </a>
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    {{-- Modal de confirmação de exclusão --}}
    <flux:modal name="confirm-delete-branch" class="max-w-sm">
        <div class="space-y-5">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                </div>
                <div>
                    <flux:heading size="lg">Excluir {{ $deletingName !== '' ? '“'.$deletingName.'”' : 'filial' }}?</flux:heading>
                    <flux:subheading class="mt-1">Esta ação não pode ser desfeita. A filial será removida permanentemente.</flux:subheading>
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
