<div class="w-full space-y-6">
    <x-admin.page-header
        :back-route="route('admin.branches.index')"
        :title="'Taxa de Serviço e Couvert — ' . $branch->name"
    />

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    <p class="text-sm text-neutral-500 dark:text-neutral-400">
        Aplicadas automaticamente em todo pedido do PDV (balcão e mesa/comanda) desta filial. Não afeta pedidos do cardápio público.
    </p>

    <x-admin.form-card title="Taxa de Serviço">
        <div class="flex items-center">
            <flux:checkbox wire:model="service_fee_enabled" label="Cobrar taxa de serviço" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Tipo</label>
                <flux:select wire:model="service_fee_type">
                    <option value="percent">Percentual (%)</option>
                    <option value="fixed">Valor fixo (R$)</option>
                </flux:select>
                @error('service_fee_type') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="service_fee_value" type="number" step="0.01" min="0"
                    label="{{ $service_fee_type === 'percent' ? 'Percentual (%)' : 'Valor (R$)' }}"
                    placeholder="{{ $service_fee_type === 'percent' ? 'Ex: 10' : 'Ex: 5,00' }}" />
                @error('service_fee_value') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </x-admin.form-card>

    <x-admin.form-card title="Couvert Artístico">
        <div class="flex items-center">
            <flux:checkbox wire:model="couvert_enabled" label="Cobrar couvert artístico" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Tipo</label>
                <flux:select wire:model="couvert_type">
                    <option value="fixed">Valor fixo (R$)</option>
                    <option value="percent">Percentual (%)</option>
                </flux:select>
                @error('couvert_type') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="couvert_value" type="number" step="0.01" min="0"
                    label="{{ $couvert_type === 'percent' ? 'Percentual (%)' : 'Valor (R$)' }}"
                    placeholder="{{ $couvert_type === 'percent' ? 'Ex: 10' : 'Ex: 5,00' }}" />
                @error('couvert_value') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </x-admin.form-card>

    <x-admin.form-actions
        save-label="Salvar configurações"
        :cancel-route="route('admin.branches.index')"
    />
</div>
