@props(['item', 'cartKey' => null, 'editable' => false])
@php $hasItemOptions = !empty($item['options']); @endphp

<div class="flex items-start gap-2.5 bg-gray-50 rounded-xl p-2.5 border border-gray-100">
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm text-gray-800 break-words{{ $editable ? '' : ' truncate' }}">
            {{ $item['name'] }}
        </p>
        @if ($hasItemOptions)
            @foreach ($item['options'] as $group)
                @continue(empty($group['selections']))
                <p class="text-xs font-medium text-gray-500 mt-0.5">{{ $group['group_name'] }}:</p>
                @foreach ($group['selections'] as $sel)
                    <p class="text-xs text-gray-400 leading-tight">
                        {{ $sel['qty'] }}× {{ $sel['name'] }}@if ($sel['additional_price'] > 0) <span class="text-amber-600">(+R$ {{ number_format($sel['additional_price'], 2, ',', '.') }})</span>@endif
                    </p>
                @endforeach
            @endforeach
        @else
            <p class="text-xs text-gray-400">R$ {{ number_format($item['price'], 2, ',', '.') }} cada</p>
        @endif
    </div>
    @if ($editable && $cartKey !== null)
        <div class="flex items-center gap-1 shrink-0">
            <button type="button" wire:click="updateCartQty('{{ $cartKey }}', {{ $item['qty'] - 1 }})"
                aria-label="Remover uma unidade de {{ $item['name'] }}"
                class="w-9 h-9 rounded-full bg-gray-200 hover:bg-gray-300 font-bold text-sm flex items-center justify-center">−</button>
            <span class="w-6 text-center text-sm font-bold" aria-live="polite">{{ $item['qty'] }}</span>
            <button type="button" wire:click="updateCartQty('{{ $cartKey }}', {{ $item['qty'] + 1 }})"
                aria-label="Adicionar uma unidade de {{ $item['name'] }}"
                class="w-9 h-9 rounded-full mc-bg-primary-light font-bold text-sm mc-text-primary flex items-center justify-center">+</button>
            <button type="button" wire:click="removeFromCart('{{ $cartKey }}')"
                aria-label="Remover {{ $item['name'] }} do carrinho"
                class="w-9 h-9 rounded-full bg-red-50 hover:bg-red-100 text-red-500 text-sm flex items-center justify-center ml-0.5"><span aria-hidden="true">✕</span></button>
        </div>
    @else
        <span class="text-sm font-bold text-gray-700 shrink-0">{{ $item['qty'] }}×</span>
    @endif
</div>
