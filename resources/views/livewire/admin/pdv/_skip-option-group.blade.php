<template x-if="!group.fixed && group.allow_skip">
    <button type="button"
        @click="clearGroup(group)"
        :aria-pressed="getGroupTotal(group.id) === 0"
        :aria-label="'Não quero: ' + group.name"
        class="w-full min-h-11 flex items-center gap-2.5 p-2.5 rounded-xl border mb-1.5 text-left touch-manipulation transition-colors duration-150 motion-reduce:transition-none hover:border-amber-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 dark:focus-visible:outline-amber-400"
        :class="getGroupTotal(group.id) === 0
            ? 'border-amber-500 bg-amber-50 text-amber-800 dark:border-amber-400 dark:bg-amber-900/20 dark:text-amber-300'
            : 'border-neutral-200 bg-neutral-50 text-neutral-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-neutral-300'">
        <span aria-hidden="true" class="w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0"
            :class="getGroupTotal(group.id) === 0 ? 'border-current' : 'border-neutral-400 dark:border-neutral-500'">
            <span x-show="getGroupTotal(group.id) === 0" class="w-2.5 h-2.5 rounded-full bg-current"></span>
        </span>
        <span class="text-sm font-semibold">Não quero</span>
    </button>
</template>
