@if($notice)
    <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400" role="status">
        {{ $notice }}
    </div>
@endif
@if($errorNotice ?? null)
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm dark:bg-red-900/30 dark:border-red-700 dark:text-red-400" role="alert">
        {{ $errorNotice }}
    </div>
@endif
