{{-- Resumo da nota no pedido (antes de emitir). A conferência e a emissão ficam no modal. --}}
@php
    $cardRecipient = $this->effectiveFiscalRecipient();
    $cardDocument = $cardRecipient['document'] ?? '';
    $cardIsCompany = \App\Services\Fiscal\FiscalRecipient::documentType($cardDocument) === \App\Services\Fiscal\FiscalRecipient::TYPE_CNPJ;
    $cardDecision = $this->fiscalDocumentPreview;
@endphp
<div class="rounded-xl border border-neutral-200 bg-white p-3 space-y-1 dark:bg-zinc-800 dark:border-zinc-700">
    <p class="text-sm font-medium text-neutral-800 dark:text-neutral-100">
        Comprador:
        @if(filled($cardDocument))
            {{ $cardIsCompany ? 'CNPJ' : 'CPF' }} {{ \App\Services\Fiscal\FiscalRecipient::formatDocument($cardDocument) }}{{ $cardIsCompany && filled($cardRecipient['name'] ?? null) ? ' · '.$cardRecipient['name'] : '' }}
        @else
            consumidor não identificado
        @endif
    </p>
    @if($cardDecision)
        <p class="text-xs text-neutral-500 dark:text-neutral-400">
            {{ $cardDecision->model ? 'Documento previsto: '.\App\DTOs\FiscalEmissionDecision::label($cardDecision->model).'.' : 'Documento a definir.' }}
            {{ $cardDecision->reasons[0] ?? '' }}
        </p>
    @endif
</div>
<div class="mt-2 flex flex-wrap gap-2">
    <button type="button" wire:click="openFiscalModal"
        class="text-sm bg-purple-100 text-purple-700 hover:bg-purple-200 dark:bg-purple-900/30 dark:text-purple-300 dark:hover:bg-purple-900/50 px-4 py-2 rounded-lg transition-colors">
        {{ $cardDecision?->model ? 'Emitir '.\App\DTOs\FiscalEmissionDecision::label($cardDecision->model) : 'Conferir e emitir nota' }}
    </button>
    <button type="button" wire:click="openFiscalModal(true)" class="text-sm text-blue-600 underline px-2 dark:text-blue-400">
        Alterar dados fiscais
    </button>
</div>
