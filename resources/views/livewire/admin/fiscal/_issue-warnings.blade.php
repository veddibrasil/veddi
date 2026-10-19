{{--
    Avisos dos modais de (re)emissão — Notas fiscais e Controle de notas.
    Recebe: $errorMessage (última falha ou null), $sefazStatus (status_sefaz da última tentativa ou null)
    e $orderDate (created_at do pedido ou null).
--}}
@if($errorMessage)
    <div>
        <h4 class="mb-1 text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Motivo da última falha</h4>
        <p class="whitespace-pre-wrap rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-700 dark:bg-red-900/30 dark:text-red-400">{{ $errorMessage }}</p>
    </div>
@endif

@if((string) $sefazStatus === '539')
    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
        A SEFAZ já tem uma nota com esta série e número. Reemitir sem mudar a série repete a rejeição —
        confira a série em <a href="{{ route('admin.fiscal.config') }}" class="underline" wire:navigate>Configurações fiscais</a> e salve antes de reemitir.
    </div>
@endif

@if($orderDate && ! $orderDate->isToday())
    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
        Pedido de {{ $orderDate->format('d/m/Y') }}. A nota sai com a data de hoje (data da emissão), não com a data da venda.
    </div>
@endif
