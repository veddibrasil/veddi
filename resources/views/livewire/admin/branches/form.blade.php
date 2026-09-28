@php
    $days = [0 => 'Domingo', 1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado'];
    $hasSlots = collect($scheduling_slots)->contains(fn ($daySlots) => ! empty($daySlots));
@endphp

<div class="w-full space-y-6">
    <x-admin.unsaved-guard :dirty-on="['addSchedulingSlot', 'removeSchedulingSlot']" />

    {{-- Editando, o breadcrumb das abas já leva de volta à lista; só a criação (sem abas) precisa da seta. --}}
    <x-admin.page-header
        :back-route="$isEditing ? null : route('admin.branches.index')"
        back-label="Voltar para filiais"
        :title="$isEditing ? 'Editar Filial' : 'Nova Filial'"
    />

    @if ($isEditing && $branch)
        <x-admin.branch-nav :branch="$branch" current="edit" />
    @endif

    <x-admin.flash-status />

    <fieldset @disabled(! $canSave) class="min-w-0 space-y-6">
        <legend class="sr-only">Dados da filial</legend>

        <x-admin.form-card title="Informações básicas">
            @if ($needsCompanySelect)
                <div>
                    <flux:select wire:model="company_id" label="Empresa" placeholder="Selecione uma empresa...">
                        @foreach ($companies as $company)
                            <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('company_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <flux:input wire:model="name" label="Nome da filial" placeholder="Ex: Filial Centro" />
                    @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input
                        wire:model="phone"
                        mask-dynamic="$input.replace(/\D/g,'').length <= 10 ? '(99) 9999-9999' : '(99) 99999-9999'"
                        label="Telefone (opcional)"
                        placeholder="(44) 99999-9999"
                    />
                    @error('phone') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <flux:checkbox wire:model="active" label="Filial ativa" />
            </div>
        </x-admin.form-card>

        <x-admin.form-card title="Endereço">
            {{-- CEP primeiro: ao completar os 8 dígitos, preenche rua, bairro, cidade e UF (ViaCEP). Tudo continua editável. --}}
            <div
                class="space-y-4"
                x-data="{
                    cepLoading: false,
                    cepMessage: '',
                    async lookupCep(value) {
                        const digits = (value || '').replace(/\D/g, '');
                        if (digits.length !== 8) { this.cepMessage = ''; return; }
                        this.cepLoading = true;
                        this.cepMessage = '';
                        try {
                            const res = await fetch('https://viacep.com.br/ws/' + digits + '/json/');
                            const data = await res.json();
                            if (data.erro) { this.cepMessage = 'CEP não encontrado. Preencha o endereço manualmente.'; return; }
                            if (data.logradouro) $wire.address = data.logradouro;
                            if (data.bairro) $wire.neighborhood = data.bairro;
                            if (data.localidade) $wire.city = data.localidade;
                            if (data.uf) $wire.state = data.uf;
                            this.cepMessage = 'Endereço preenchido pelo CEP. Confira e informe o número.';
                        } catch (e) {
                            this.cepMessage = 'Não foi possível consultar o CEP agora. Preencha o endereço manualmente.';
                        } finally {
                            this.cepLoading = false;
                        }
                    },
                }"
            >
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div>
                        <flux:input
                            wire:model="cep"
                            mask="99999-999"
                            inputmode="numeric"
                            autocomplete="postal-code"
                            label="CEP"
                            placeholder="00000-000"
                            x-on:input.debounce.400ms="lookupCep($event.target.value)"
                        />
                        @error('cep') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <flux:input wire:model="address" label="Rua" placeholder="Ex: Av. Brasil" />
                        @error('address') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:input wire:model="number" label="Número" placeholder="123" />
                        @error('number') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <p x-show="cepLoading" x-cloak class="text-xs text-neutral-600 dark:text-neutral-400" role="status">Buscando endereço…</p>
                <p x-show="cepMessage" x-cloak x-text="cepMessage" class="text-xs text-neutral-600 dark:text-neutral-400" role="status"></p>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div class="sm:col-span-2">
                        <flux:input wire:model="neighborhood" label="Bairro" placeholder="Centro" />
                        @error('neighborhood') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:input wire:model="city" label="Cidade" placeholder="Maringá" />
                        @error('city') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:select wire:model="state" label="UF" placeholder="Selecione">
                            @foreach (\App\Livewire\Admin\Branches\Form::STATES as $uf => $stateName)
                                <flux:select.option value="{{ $uf }}">{{ $uf }} — {{ $stateName }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @error('state') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <flux:input wire:model="complement" label="Complemento (opcional)" placeholder="Apto 12, Bloco B..." />
                    @error('complement') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-admin.form-card>

        <x-admin.form-card title="Horário de funcionamento">
            @error('available_days') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror

            <div class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @foreach ($days as $dayIndex => $dayLabel)
                    <x-admin.business-hours-row
                        :day-index="$dayIndex"
                        :day-label="$dayLabel"
                        :is-active="in_array($dayIndex, $available_days)"
                    />
                @endforeach
            </div>
        </x-admin.form-card>
    </fieldset>

    {{-- Agendamento: recolhido quando a empresa não usa agendamento (evita uma segunda lista de 7 dias sem uso). --}}
    <div
        x-data="{ open: @js($schedulingEnabled || $hasSlots) }"
        class="bg-white border rounded-xl shadow-sm dark:bg-zinc-800 dark:border-zinc-700"
    >
        <button
            type="button"
            x-on:click="open = ! open"
            x-bind:aria-expanded="open.toString()"
            aria-controls="scheduling-slots-panel"
            class="flex w-full items-center justify-between gap-3 rounded-xl p-6 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-500"
        >
            <span>
                <span class="block font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Horários de agendamento</span>
                <span class="mt-1 block text-xs text-neutral-600 dark:text-neutral-400">
                    @if ($schedulingEnabled)
                        Agendamento ativo na empresa. Defina os intervalos em que o cliente pode agendar.
                    @elseif ($hasSlots)
                        Há horários cadastrados, mas o agendamento está desativado nas configurações da empresa.
                    @else
                        Opcional — só é usado se o agendamento estiver ativado nas configurações da empresa.
                    @endif
                </span>
            </span>
            <flux:icon name="chevron-down" class="size-5 shrink-0 text-neutral-500 transition-transform" x-bind:class="open ? 'rotate-180' : ''" />
        </button>

        <div id="scheduling-slots-panel" x-show="open" x-cloak class="space-y-4 px-6 pb-6">
            <p class="text-xs text-neutral-600 dark:text-neutral-400">
                Defina os intervalos em que os clientes podem agendar pedidos. Se nenhum horário for definido para um dia, o agendamento usará o horário de funcionamento normal daquele dia.
            </p>

            @if ($canCompanySettings)
                <p class="text-xs text-neutral-600 dark:text-neutral-400">
                    {{ $schedulingEnabled ? 'Para desativar o agendamento ou mudar a antecedência mínima, veja' : 'Para usar o agendamento, ative-o em' }}
                    <a href="{{ route('admin.settings') }}#agendamento" class="font-medium text-amber-700 underline underline-offset-2 hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-300">Configurações › Empresa › Agendamento de pedidos</a>.
                </p>
            @endif

            <fieldset @disabled(! $canSave) class="min-w-0">
                <legend class="sr-only">Horários de agendamento por dia</legend>
                <div class="divide-y divide-neutral-100 dark:divide-zinc-700">
                    @foreach ($days as $dayIndex => $dayLabel)
                        <x-admin.scheduling-slots-row
                            :day-index="$dayIndex"
                            :day-label="$dayLabel"
                            :is-active="in_array($dayIndex, $available_days)"
                            :slots="$scheduling_slots[$dayIndex] ?? []"
                        />
                    @endforeach
                </div>
            </fieldset>
        </div>
    </div>

    <x-admin.form-actions
        :save-label="$isEditing ? 'Salvar alterações' : 'Criar filial'"
        :cancel-route="route('admin.branches.index')"
        :cancel-label="$isEditing ? 'Voltar à lista' : 'Cancelar'"
        :can-save="$canSave"
        sticky
    />
</div>
