<div class="w-full space-y-6"
    x-init="$watch(() => $wire.deletingId, val => val ? $flux.modal('confirm-delete-pause').show() : $flux.modal('confirm-delete-pause').close())">
    <x-admin.page-header
        :back-route="route('admin.branches.index')"
        :title="'Pausas e Feriados — ' . $branch->name"
    />

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    <p class="text-sm text-neutral-500 dark:text-neutral-400">
        Cadastre períodos em que esta filial não deve receber pedidos, mesmo dentro do horário normal de
        funcionamento — feriados, reformas, viagens ou qualquer pausa programada. Enquanto uma pausa estiver
        ativa, a filial aparece como fechada no chat e no agendamento.
    </p>

    @if ($canSave)
        <x-admin.form-card title="Nova pausa">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <flux:input wire:model="starts_date" type="date" label="Data de início" />
                        @error('starts_date') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:input wire:model="starts_time" type="time" label="Horário (opcional)" />
                        @error('starts_time') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <flux:input wire:model="ends_date" type="date" label="Data de término" />
                        @error('ends_date') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:input wire:model="ends_time" type="time" label="Horário (opcional)" />
                        @error('ends_time') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
            <p class="text-xs text-neutral-400 dark:text-neutral-500 -mt-2">
                Sem horário, a pausa considera o dia todo (00:00 até 23:59).
            </p>

            <div>
                <flux:input wire:model="reason" label="Motivo (opcional)" placeholder="Ex: Natal, reforma, férias..." />
                @error('reason') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <flux:checkbox wire:model="recurring_annual" label="Repetir todo ano nesta mesma data" />
                <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-1">
                    Em pausas recorrentes, o ano informado é ignorado — só o dia/mês/horário se repetem.
                </p>
            </div>

            <flux:button wire:click="create" variant="primary">Adicionar pausa</flux:button>
        </x-admin.form-card>
    @endif

    <x-admin.form-card title="Pausas cadastradas">
        @if ($this->pauses->isEmpty())
            <p class="text-sm text-neutral-400 dark:text-neutral-500">Nenhuma pausa cadastrada.</p>
        @else
            <div class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @foreach ($this->pauses as $pause)
                    <div class="py-3 flex items-center justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-semibold text-neutral-700 dark:text-neutral-200">
                                    {{ $pause->starts_at->format('d/m/Y H:i') }} até {{ $pause->ends_at->format('d/m/Y H:i') }}
                                </span>
                                @if ($pause->recurring_annual)
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-400">
                                        Recorrente
                                    </span>
                                @endif
                            </div>
                            @if ($pause->reason)
                                <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-0.5">{{ $pause->reason }}</p>
                            @endif
                        </div>
                        @if ($canSave)
                            <flux:button wire:click="confirmDelete({{ $pause->id }})" variant="ghost" size="sm">
                                Excluir
                            </flux:button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-admin.form-card>

    {{-- Modal de confirmação de exclusão --}}
    <flux:modal name="confirm-delete-pause" class="max-w-sm">
        <div class="space-y-5">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                </div>
                <div>
                    <flux:heading size="lg">Excluir pausa?</flux:heading>
                    <flux:subheading class="mt-1">Esta ação não pode ser desfeita. A filial volta a atender normalmente nesse período.</flux:subheading>
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
