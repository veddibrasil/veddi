{{-- Aviso de sucesso das telas de configuração (filial, empresa, integrações). Sticky: fica visível mesmo depois de salvar no fim de uma página longa. --}}
@if (session('status'))
    <div
        x-data="{ show: true }"
        x-init="setTimeout(() => show = false, 8000)"
        x-show="show"
        x-transition.opacity
        role="status"
        aria-live="polite"
        class="sticky top-4 z-30 flex items-start justify-between gap-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 shadow-sm dark:border-green-700 dark:bg-green-900/30 dark:text-green-400"
    >
        <span>{{ session('status') }}</span>
        <button
            type="button"
            x-on:click="show = false"
            aria-label="Fechar aviso"
            class="-my-1 rounded px-1.5 text-lg leading-none text-green-700/70 hover:text-green-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-green-600 dark:text-green-400/70 dark:hover:text-green-300"
        >&times;</button>
    </div>
@endif
