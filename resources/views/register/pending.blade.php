<x-layouts::auth :title="'Aguardando pagamento'">
    <div class="flex flex-col items-center gap-6 text-center w-full max-w-md">

        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/20">
            <svg class="h-8 w-8 text-amber-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
        </div>

        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Conta criada!</h1>
            <p class="mt-2 text-zinc-500 dark:text-zinc-400">
                Sua conta está aguardando a confirmação do pagamento do Plano PRO.
            </p>
        </div>

        @if(session('info'))
            <div class="rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 p-4 text-sm text-blue-700 dark:text-blue-400 w-full">
                {{ session('info') }}
            </div>
        @endif

        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 p-6 text-left w-full space-y-3">
            <h2 class="font-semibold text-zinc-800 dark:text-zinc-200">Próximos passos</h2>
            <ul class="space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30 text-green-700 text-xs">1</span>
                    Você receberá um link de pagamento PIX via e-mail do Asaas.
                </li>
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30 text-green-700 text-xs">2</span>
                    Após o pagamento ser confirmado, sua conta será ativada automaticamente.
                </li>
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30 text-green-700 text-xs">3</span>
                    Você receberá um e-mail de boas-vindas com o link de acesso.
                </li>
            </ul>
        </div>

        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            Já realizou o pagamento?
            <flux:link href="{{ route('login') }}" wire:navigate class="text-red-600 hover:text-red-700">
                Faça login aqui
            </flux:link>
        </p>

    </div>
</x-layouts::auth>
