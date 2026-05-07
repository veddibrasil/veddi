<div class="w-full space-y-6">
    <x-admin.page-header
        :back-route="route('admin.branches.index')"
        :title="$isEditing ? 'Editar Filial' : 'Nova Filial'"
    />

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
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div class="sm:col-span-2">
                <flux:input wire:model="address" label="Rua" placeholder="Ex: Av. Brasil" />
                @error('address') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="number" label="Número" placeholder="123" />
                @error('number') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="cep" label="CEP" placeholder="00000-000" />
                @error('cep') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

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
                <flux:input wire:model="state" label="UF" placeholder="SP" />
                @error('state') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <flux:input wire:model="complement" label="Complemento (opcional)" placeholder="Apto 12, Bloco B..." />
                @error('complement') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="service_radius_km" type="number" step="0.01" min="0"
                    label="Raio de atuação (km)"
                    placeholder="Deixe em branco para sem limite" />
                <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-1">
                    Distância máxima de entrega a partir desta filial.
                </p>
                @error('service_radius_km') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </x-admin.form-card>

    <x-admin.form-card title="Horário de funcionamento">
        @error('available_days') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror

        <div class="divide-y divide-neutral-100 dark:divide-zinc-700">
            @foreach ([0 => 'Domingo', 1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado'] as $dayIndex => $dayLabel)
                <x-admin.business-hours-row
                    :day-index="$dayIndex"
                    :day-label="$dayLabel"
                    :is-active="in_array($dayIndex, $available_days)"
                />
            @endforeach
        </div>
    </x-admin.form-card>

    <x-admin.form-actions
        :save-label="$isEditing ? 'Salvar alterações' : 'Criar filial'"
        :cancel-route="route('admin.branches.index')"
    />
</div>
