{{-- Aviso de erro das telas de configuração (par do x-admin.flash-status). Fica até a próxima ação. --}}
@if (session('error'))
    <div role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-700 dark:bg-red-900/30 dark:text-red-400">
        {{ session('error') }}
    </div>
@endif
