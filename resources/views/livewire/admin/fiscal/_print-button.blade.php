{{-- Botão "Imprimir" da nota autorizada (pedido, notas fiscais, controle de notas) — imprime
     sem abrir aba (ver printFiscalNote em pdv-printer.js). NFC-e: térmica da filial via QZ Tray,
     ou impressão do navegador com o DANFE em bobina; NF-e: impressão do navegador com o PDF. --}}
@php
    $printUrl = match (true) {
        $note?->status !== 'authorized' => null,
        $note->model === '55' => $note->danfe_url ? route('admin.fiscal.document', ['note' => $note->id, 'type' => 'danfe', 'inline' => 1]) : null,
        default => $note->order_id ? route('admin.orders.fiscal-receipt', $note->order_id) : null,
    };
@endphp
@if($printUrl)
    <a href="{{ $printUrl }}" target="_blank" x-data="fiscalNotePrinter"
        @click.prevent="printFiscalNote({{ (int) $note->order_id }}, '{{ $note->model === '55' ? '55' : '65' }}', $el.href)"
        class="{{ $class }}">
        {{ $label ?? 'Imprimir' }}
    </a>
@endif
