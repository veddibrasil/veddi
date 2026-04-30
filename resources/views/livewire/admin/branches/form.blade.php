<div class="w-full space-y-6">
    <div class="flex items-center gap-3">
        <a href="{{ route('admin.branches.index') }}" class="text-neutral-400 hover:text-neutral-600 dark:text-neutral-500 dark:hover:text-neutral-300">←</a>
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">
            {{ $isEditing ? 'Editar Filial' : 'Nova Filial' }}
        </h1>
    </div>

    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Informações da filial</h2>

        @if($needsCompanySelect)
        <div>
      
            <flux:select wire:model="company_id" label="Empresa" placeholder="Selecione uma empresa...">
                @foreach($companies as $company)
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
                <flux:input wire:model="phone" mask-dynamic="$input.replace(/\D/g,'').length <= 10 ? '(99) 9999-9999' : '(99) 99999-9999'" label="Telefone (opcional)" placeholder="(44) 99999-9999" />
                @error('phone') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="sm:col-span-2">
                <flux:input wire:model="address" label="Endereço" placeholder="Rua e número" />
                @error('address') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="city" label="Cidade" placeholder="Maringá" />
                @error('city') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 items-end">
            <div>
                <flux:input wire:model="opens_at" type="time" label="Abre às" />
                @error('opens_at') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="closes_at" type="time" label="Fecha às" />
                @error('closes_at') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2 pb-1">
                <flux:checkbox wire:model="active" label="Filial ativa" />
            </div>
        </div>
    </div>

    <div class="flex gap-3 pb-8">
        <flux:button wire:click="save" class="!bg-amber-500 !text-white hover:!bg-amber-600"
            wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="save">{{ $isEditing ? 'Salvar alterações' : 'Criar filial' }}</span>
            <span wire:loading wire:target="save">Salvando...</span>
        </flux:button>
        <a href="{{ route('admin.branches.index') }}"
            class="inline-flex items-center px-4 py-2 text-sm text-neutral-600 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200">
            Cancelar
        </a>
    </div>
</div>
