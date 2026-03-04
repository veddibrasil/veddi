<div class="max-w-lg space-y-6">
    <div class="flex items-center gap-3">
        <a href="{{ route('admin.branches.index') }}" class="text-neutral-400 hover:text-neutral-600">←</a>
        <h1 class="text-2xl font-bold text-neutral-800">
            {{ $isEditing ? 'Editar Filial' : 'Nova Filial' }}
        </h1>
    </div>

    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4">
        <flux:input wire:model="name" label="Nome da filial" placeholder="Ex: Filial Centro" />
        @error('name') <p class="text-red-500 text-xs -mt-2">{{ $message }}</p> @enderror

        <flux:input wire:model="address" label="Endereço" placeholder="Rua e número" />
        @error('address') <p class="text-red-500 text-xs -mt-2">{{ $message }}</p> @enderror

        <flux:input wire:model="city" label="Cidade" placeholder="Maringá" />
        @error('city') <p class="text-red-500 text-xs -mt-2">{{ $message }}</p> @enderror

        <flux:input wire:model="phone" label="Telefone (opcional)" placeholder="(44) 99999-9999" />
        @error('phone') <p class="text-red-500 text-xs -mt-2">{{ $message }}</p> @enderror

        <div class="grid grid-cols-2 gap-4">
            <div>
                <flux:input wire:model="opens_at" type="time" label="Abre às" />
                @error('opens_at') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="closes_at" type="time" label="Fecha às" />
                @error('closes_at') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
            </div>
        </div>

        <flux:checkbox wire:model="active" label="Filial ativa" />

        <div class="flex gap-3 pt-2">
            <flux:button wire:click="save" class="!bg-amber-500 !text-white hover:!bg-amber-600">
                {{ $isEditing ? 'Salvar alterações' : 'Criar filial' }}
            </flux:button>
            <a href="{{ route('admin.branches.index') }}"
                class="inline-flex items-center px-4 py-2 text-sm text-neutral-600 hover:text-neutral-800">
                Cancelar
            </a>
        </div>
    </div>
</div>
