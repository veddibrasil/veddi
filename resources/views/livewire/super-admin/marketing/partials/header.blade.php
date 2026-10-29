{{-- Cabeçalho das telas de e-mail marketing: título, descrição e ação opcional. --}}
<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">{{ $title }}</h1>
            @isset($description)
                <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">{{ $description }}</p>
            @endisset
        </div>
        @isset($actionRoute)
            <flux:button variant="primary" icon="plus" :href="route($actionRoute)" wire:navigate>{{ $actionLabel }}</flux:button>
        @endisset
    </div>
    @include('livewire.super-admin.marketing.partials.nav')
</div>
