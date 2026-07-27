<div class="flex flex-1 flex-col items-center justify-center gap-8 bg-zinc-50 p-8 text-neutral-900 dark:bg-[#0f1926] dark:text-neutral-100">
    <div class="text-center">
        <h1 class="text-2xl font-bold text-neutral-900 dark:text-neutral-100">PDV</h1>
        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Escolha o tipo de atendimento.</p>
    </div>

    <div class="grid w-full max-w-2xl grid-cols-1 gap-4 sm:grid-cols-2">
        <a
            href="{{ route('admin.pdv.checkout') }}"
            wire:navigate
            class="group flex flex-col items-center gap-3 rounded-2xl border-2 border-neutral-200 bg-white p-8 text-center shadow-sm transition-all hover:border-amber-400 hover:shadow-lg dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-amber-500"
        >
            <div class="flex size-16 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/40">
                <flux:icon.computer-desktop class="size-8 text-amber-500 dark:text-amber-400" />
            </div>
            <div>
                <p class="text-lg font-bold text-neutral-900 dark:text-neutral-100">Venda Direta</p>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Balcão, entrega e pagamento imediato.</p>
            </div>
        </a>

        <a
            href="{{ route('admin.pdv.tabs') }}"
            wire:navigate
            class="group flex flex-col items-center gap-3 rounded-2xl border-2 border-neutral-200 bg-white p-8 text-center shadow-sm transition-all hover:border-amber-400 hover:shadow-lg dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-amber-500"
        >
            <div class="flex size-16 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/40">
                <flux:icon.table-cells class="size-8 text-amber-500 dark:text-amber-400" />
            </div>
            <div>
                <p class="text-lg font-bold text-neutral-900 dark:text-neutral-100">Mesas / Comandas</p>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Abrir mesa, lançar itens e fechar comanda.</p>
            </div>
        </a>
    </div>
</div>
