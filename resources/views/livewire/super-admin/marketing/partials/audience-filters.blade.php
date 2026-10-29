{{-- Filtros de público ($prefix = propriedade Livewire, padrão "filters"). --}}
@php($prefix = $prefix ?? 'filters')
@php($plans = \App\Enums\Plan::cases())

<div class="space-y-4">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <fieldset class="space-y-2">
            <legend class="text-sm font-medium text-neutral-700 dark:text-neutral-300">Plano</legend>
            @foreach($plans as $plan)
                <flux:checkbox wire:model.live="{{ $prefix }}.plans" value="{{ $plan->value }}" label="{{ $plan->label() }}" />
            @endforeach
            <p class="text-xs text-neutral-400 dark:text-neutral-500">Nenhum marcado = todos.</p>
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="text-sm font-medium text-neutral-700 dark:text-neutral-300">Status da empresa</legend>
            @foreach(\App\DTOs\AudienceFilterData::COMPANY_STATUSES as $value => $label)
                <flux:checkbox wire:model.live="{{ $prefix }}.company_statuses" value="{{ $value }}" label="{{ $label }}" />
            @endforeach
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="text-sm font-medium text-neutral-700 dark:text-neutral-300">Papel do usuário</legend>
            @foreach(\App\DTOs\AudienceFilterData::ROLES as $value => $label)
                <flux:checkbox wire:model.live="{{ $prefix }}.roles" value="{{ $value }}" label="{{ $label }}" />
            @endforeach
            <p class="text-xs text-neutral-400 dark:text-neutral-500">Clientes finais dos restaurantes nunca entram.</p>
        </fieldset>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <flux:select wire:model.live="{{ $prefix }}.company_active" label="Empresa ativa/inativa">
            <flux:select.option value="">Qualquer</flux:select.option>
            <flux:select.option value="1">Ativa</flux:select.option>
            <flux:select.option value="0">Inativa</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="{{ $prefix }}.pdv_enabled" label="Módulo PDV">
            <flux:select.option value="">Qualquer</flux:select.option>
            <flux:select.option value="1">Ligado</flux:select.option>
            <flux:select.option value="0">Desligado</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="{{ $prefix }}.fiscal_enabled" label="Nota fiscal">
            <flux:select.option value="">Qualquer</flux:select.option>
            <flux:select.option value="1">Ligada</flux:select.option>
            <flux:select.option value="0">Desligada</flux:select.option>
        </flux:select>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <flux:input type="date" wire:model.live.debounce.500ms="{{ $prefix }}.created_from" label="Cadastro a partir de" />
            @error($prefix.'.created_from') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <flux:input type="date" wire:model.live.debounce.500ms="{{ $prefix }}.created_to" label="Cadastro até" />
            @error($prefix.'.created_to') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <flux:input type="number" min="1" wire:model.live.debounce.500ms="{{ $prefix }}.inactive_days" label="Sem pedidos há (dias)" placeholder="Ex.: 30" />
            <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-1">Só empresas cadastradas há pelo menos esse tempo.</p>
            @error($prefix.'.inactive_days') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
    </div>
</div>
