{{-- Ações da linha do Controle de notas — usadas na coluna Ações (sm+) e embaixo da situação no celular. --}}
@if($canIssue && $selectable[$order->id])
    <button wire:click="openIssueOne({{ $order->id }})" wire:loading.attr="disabled"
        class="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">
        {{ $situation === 'none' ? 'Emitir' : 'Reemitir' }}
    </button>
@endif
@include('livewire.admin.fiscal._print-button', [
    'note' => $note,
    'class' => 'text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400',
])
@if($note?->danfe_url)
    <a href="{{ route('admin.fiscal.document', ['note' => $note->id, 'type' => 'danfe']) }}" target="_blank"
        class="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">DANFE</a>
@endif
@if($note?->xml_url)
    <a href="{{ route('admin.fiscal.document', ['note' => $note->id, 'type' => 'xml']) }}" target="_blank"
        class="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">XML</a>
@endif
