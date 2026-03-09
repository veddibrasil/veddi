@props([
    'model'      => 'image',
    'currentUrl' => null,
    'label'      => 'Imagem',
])

<div
    x-data="{
        dragging: false,
        preview: null,
        clearFile() {
            this.preview = null;
            @this.set('{{ $model }}', null);
        },
        handleDrop(e) {
            this.dragging = false;
            const file = e.dataTransfer.files[0];
            if (file) this.$refs.input.files = e.dataTransfer.files;
            this.$refs.input.dispatchEvent(new Event('change'));
        },
        handleChange(e) {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = (ev) => { this.preview = ev.target.result; };
            reader.readAsDataURL(file);
        }
    }"
>
    <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">{{ $label }}</label>

    <div
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="handleDrop($event)"
        @click="$refs.input.click()"
        :class="dragging
            ? 'border-amber-400 bg-amber-50 dark:bg-amber-900/20'
            : 'border-neutral-300 dark:border-zinc-600 hover:border-amber-400 dark:hover:border-amber-500'"
        class="relative cursor-pointer rounded-xl border-2 border-dashed transition-colors p-6 flex flex-col items-center justify-center gap-2 select-none"
    >
        <input
            x-ref="input"
            type="file"
            accept="image/*"
            wire:model="{{ $model }}"
            @change="handleChange($event)"
            class="hidden"
        />

        <div wire:loading wire:target="{{ $model }}" class="absolute inset-0 flex items-center justify-center rounded-xl bg-white/70 dark:bg-zinc-800/70 z-10">
            <svg class="animate-spin h-6 w-6 text-amber-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
            </svg>
        </div>

        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-neutral-400 dark:text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <p class="text-sm text-neutral-500 dark:text-neutral-400 text-center">
            Arraste uma imagem aqui ou <span class="text-amber-600 dark:text-amber-400 font-medium">clique para selecionar</span>
        </p>
        <p class="text-xs text-neutral-400 dark:text-zinc-500">PNG, JPG, GIF — máx. 2MB</p>
    </div>

    @error($model)
        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
    @enderror

    {{-- Preview após seleção --}}
    <template x-if="preview">
        <div class="mt-3 flex items-center gap-3">
            <img :src="preview" class="h-20 w-20 rounded-lg object-cover border border-neutral-200 dark:border-zinc-700" />
            <button
                type="button"
                @click.prevent="clearFile()"
                class="text-xs text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 flex items-center gap-1"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
                Remover
            </button>
        </div>
    </template>

    {{-- Imagem atual (sem nova seleção) --}}
    @if ($currentUrl)
        <template x-if="!preview">
            <div class="mt-3 flex items-center gap-3">
                <img src="{{ $currentUrl }}" class="h-20 w-20 rounded-lg object-cover border border-neutral-200 dark:border-zinc-700" />
                <span class="text-xs text-neutral-400 dark:text-zinc-500">Imagem atual</span>
            </div>
        </template>
    @endif
</div>
