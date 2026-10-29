<div class="w-full space-y-6">
    <div class="flex items-center gap-3">
        <a href="{{ route('superadmin.marketing.templates.index') }}" wire:navigate class="text-neutral-400 hover:text-neutral-600 dark:text-neutral-500 dark:hover:text-neutral-300" aria-label="Voltar para templates">←</a>
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">{{ $templateId ? 'Editar template' : 'Novo template' }}</h1>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">
        <div class="space-y-6">
            <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
                <div>
                    <flux:input wire:model="name" label="Nome" placeholder="Ex.: Novidade do produto" />
                    @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="subjectDefault" label="Assunto padrão (opcional)" />
                    @error('subjectDefault') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model.live.debounce.600ms="preheaderDefault" label="Pré-cabeçalho padrão (opcional)" />
                    @error('preheaderDefault') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="bg-white border rounded-xl shadow-sm p-6 dark:bg-zinc-800 dark:border-zinc-700">
                @include('livewire.super-admin.marketing.partials.block-editor')
            </div>

            <div class="flex justify-end gap-2">
                <flux:button :href="route('superadmin.marketing.templates.index')" wire:navigate variant="ghost">Cancelar</flux:button>
                <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled">Salvar template</flux:button>
            </div>
        </div>

        <div class="bg-white border rounded-xl shadow-sm p-6 space-y-3 dark:bg-zinc-800 dark:border-zinc-700 xl:sticky xl:top-4">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Preview</h2>
            @include('livewire.super-admin.marketing.partials.preview', ['previewCompanies' => $this->previewCompanies])
        </div>
    </div>
</div>
