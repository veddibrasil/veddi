{{--
    Card de um pedido na fila da cozinha/bar. Tipo (entrega, retirada, mesa, balcão) em faixa e
    etiqueta de cor; entrega e retirada mostram cliente, bairro e pagamento, que é o que quem solta
    o pedido precisa conferir. O topo abre o pedido.
--}}
@php
    $fulfillment = \App\Support\Orders\OrderFulfillment::for($order);
    $kind = $fulfillment->kind();
    $isHandoff = $fulfillment->isHandoff();
    $marketplace = $order->marketplaceDetails();
    $dueAt = $fulfillment->dueAt();
    $stationItems = $order->items->filter(fn ($item) => $item->matchesStation($userStation));
    $awaitingAcceptance = $order->awaitingAcceptance();
    $beforePreparation = in_array($order->status, ['pending', 'awaiting_payment', 'paid', 'scheduled'], true);
    $finished = in_array($order->status, ['ready', 'out_for_delivery', 'delivered', 'cancelled', 'refunded'], true);

    // Agendado conta a partir do horário combinado; antes dele não há atraso.
    $referenceTime = $beforePreparation ? ($dueAt ?? $order->created_at) : $order->updated_at;
    $elapsedMinutes = $referenceTime->isFuture() ? null : (int) floor($referenceTime->diffInMinutes(now(), true));

    $urgency = match (true) {
        $finished => 'done',
        $elapsedMinutes === null => 'scheduled',
        $elapsedMinutes >= $this::URGENCY_CRITICAL_MINUTES => 'critical',
        $elapsedMinutes >= $this::URGENCY_WARNING_MINUTES => 'warning',
        default => 'ok',
    };

    $urgencyClasses = match ($urgency) {
        'done' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-400',
        'critical' => 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-400 animate-pulse',
        'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-400',
        default => 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300',
    };

    [$kindLabel, $kindChip, $kindBorder] = match ($kind) {
        'delivery' => ['🛵 ENTREGA', 'bg-violet-600 text-white', 'border-l-violet-500'],
        'pickup' => ['🥡 RETIRADA', 'bg-sky-600 text-white', 'border-l-sky-500'],
        'table' => ['🍽️ '.$fulfillment->label(), 'bg-neutral-200 text-neutral-700 dark:bg-zinc-700 dark:text-neutral-200', 'border-l-neutral-300 dark:border-l-zinc-600'],
        default => ['🧾 BALCÃO', 'bg-neutral-200 text-neutral-700 dark:bg-zinc-700 dark:text-neutral-200', 'border-l-neutral-300 dark:border-l-zinc-600'],
    };

    $stepLabel = match (true) {
        $awaitingAcceptance => null, // o bloco de aceite do card já diz
        $order->status === 'scheduled' => 'Agendado',
        $beforePreparation => 'Novo',
        default => $order->status_label,
    };

    $payment = $isHandoff ? $fulfillment->payment() : null;
    $customerName = $order->customer && $order->customer->phone !== 'pdv-guest' ? $order->customer->name : null;

    $canStart = $canUpdate && $beforePreparation && ! $order->requiresChatAcceptance()
        && (! $marketplace || ($order->ifoodDetails()?->canStartPreparation() ?? false));
    $kitchenDispatches = $canUpdate && $userStation === 'cozinha' && $kind === 'delivery';
    $canDispatch = $kitchenDispatches && ($marketplace ? $marketplace->canDispatch() : in_array($order->status, ['preparing', 'ready'], true));
@endphp
<div wire:key="station-card-{{ $order->id }}" data-testid="station-card" data-kind="{{ $kind }}"
     class="flex flex-col bg-white border border-l-4 {{ $kindBorder }} rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
    <div class="flex items-start justify-between gap-2 px-4 pt-3 pb-2">
        <a href="{{ route('admin.orders.show', $order) }}" wire:navigate class="min-w-0 flex-1 space-y-1.5" aria-label="Abrir pedido {{ $order->displayNumber() }}">
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-xs font-bold px-2 py-0.5 rounded-md {{ $kindChip }}">{{ $kindLabel }}</span>
                @if ($marketplace)
                    <span class="text-xs font-medium px-2 py-0.5 rounded-md bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $marketplace->channelLabel() }} · {{ $marketplace->orderTypeLabel() }}</span>
                @endif
                @if ($dueAt)
                    <span class="text-xs font-bold px-2 py-0.5 rounded-md bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">⏰ {{ $dueAt->isToday() ? $dueAt->format('H:i') : $dueAt->format('d/m H:i') }}</span>
                @endif
            </div>
            <p class="flex flex-wrap items-baseline gap-x-2 text-sm">
                <span class="font-mono font-semibold text-neutral-800 dark:text-neutral-100">{{ $order->displayNumber() }}</span>
                @if ($stepLabel)
                    <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">{{ $stepLabel }}</span>
                @endif
            </p>
            @if ($isHandoff && ($customerName || $order->delivery_neighborhood))
                <p class="text-sm text-neutral-700 dark:text-neutral-200 truncate">
                    {{ $customerName }}@if ($customerName && $kind === 'delivery' && $order->delivery_neighborhood) · @endif{{ $kind === 'delivery' ? $order->delivery_neighborhood : '' }}
                </p>
            @endif
            @if ($payment)
                <p class="text-xs font-semibold {{ $payment['state'] === 'collect' ? 'text-orange-700 dark:text-orange-300' : ($payment['state'] === 'paid' ? 'text-emerald-700 dark:text-emerald-400' : 'text-neutral-500 dark:text-neutral-400') }}">
                    {{ $payment['state'] === 'collect' ? '💵' : '✓' }} {{ $fulfillment->paymentHeadline() }}
                    @if ($fulfillment->changeLine())
                        <span class="block font-medium">{{ $fulfillment->changeLine() }}</span>
                    @endif
                </p>
            @endif
        </a>
        <div class="flex items-center gap-2 shrink-0">
            @if ($elapsedMinutes !== null)
                <span class="text-xs px-2 py-1 rounded-full font-medium {{ $urgencyClasses }}">
                    {{ $this->formatElapsed($elapsedMinutes) }}
                </span>
            @endif
            <a href="{{ route('admin.orders.receipt', ['order' => $order->id, 'station' => $userStation]) }}" target="_blank"
               title="Imprimir cupom {{ $userStation }}"
               aria-label="Imprimir cupom {{ $userStation }} do pedido {{ $order->displayNumber() }}"
               @click.prevent="printStation({{ $order->id }}, '{{ $userStation }}', $el.href)"
               class="shrink-0 p-1 text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200 rounded-lg hover:bg-neutral-100 dark:hover:bg-zinc-700 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                </svg>
            </a>
        </div>
    </div>

    <div class="px-4 pb-3 divide-y dark:divide-zinc-700">
        @foreach ($stationItems as $item)
            <div class="py-2 first:pt-0">
                <p class="text-sm font-medium text-neutral-800 dark:text-neutral-100">{{ $item->quantity }}x {{ $item->product_name }}</p>
                @foreach (($item->options ?? []) as $group)
                    <p class="text-xs font-medium text-neutral-500 mt-0.5 dark:text-neutral-400">{{ $group['group_name'] ?? 'Opções' }}:</p>
                    @foreach (($group['selections'] ?? []) as $sel)
                        <p class="text-xs text-neutral-400 leading-tight dark:text-neutral-500">{{ $sel['qty'] ?? 0 }}× {{ $sel['name'] ?? '-' }}</p>
                    @endforeach
                @endforeach
                @if ($item->notes)
                    <p class="mt-1 text-xs font-semibold text-amber-700 dark:text-amber-400">Obs: {{ $item->notes }}</p>
                @endif
            </div>
        @endforeach
    </div>

    @if ($order->notes)
        <div class="mx-4 mb-3 px-3 py-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-xs text-amber-800 dark:text-amber-300 whitespace-pre-line">{{ $order->notes }}</div>
    @endif

    {{-- mt-auto: no grid os cards da linha têm a mesma altura; ação fica sempre no rodapé. --}}
    <div class="mt-auto">
    @if ($awaitingAcceptance)
        <div class="px-4 pb-4">
            @include('livewire.admin.orders.partials.order-acceptance', ['canAccept' => $canUpdate, 'canReject' => false, 'large' => true])
        </div>
    @elseif ($canStart)
        <button wire:click="updateOrderStatus({{ $order->id }}, 'preparing')"
                wire:loading.attr="disabled" wire:target="updateOrderStatus({{ $order->id }}, 'preparing')"
                class="w-full py-3.5 text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50 transition-colors border-t dark:border-zinc-700">
            Iniciar preparo
        </button>
    @elseif ($canUpdate && $order->status === 'preparing')
        <div class="flex border-t dark:border-zinc-700">
            <button wire:click="updateOrderStatus({{ $order->id }}, 'ready')"
                    wire:loading.attr="disabled" wire:target="updateOrderStatus({{ $order->id }}, 'ready')"
                    class="flex-1 py-3.5 text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 disabled:opacity-50 transition-colors">
                Marcar como pronto
            </button>
            @if ($canDispatch)
                <button wire:click="updateOrderStatus({{ $order->id }}, 'out_for_delivery')"
                        wire:loading.attr="disabled" wire:target="updateOrderStatus({{ $order->id }}, 'out_for_delivery')"
                        class="flex-1 py-3.5 text-sm font-bold text-white bg-violet-600 hover:bg-violet-700 active:bg-violet-800 disabled:opacity-50 transition-colors border-l border-white/30">
                    Saiu para entrega
                </button>
            @endif
        </div>
    @elseif ($canDispatch)
        <button wire:click="updateOrderStatus({{ $order->id }}, 'out_for_delivery')"
                wire:loading.attr="disabled" wire:target="updateOrderStatus({{ $order->id }}, 'out_for_delivery')"
                class="w-full py-3.5 text-sm font-bold text-white bg-violet-600 hover:bg-violet-700 active:bg-violet-800 disabled:opacity-50 transition-colors border-t dark:border-zinc-700">
            🛵 Saiu para entrega
        </button>
    @elseif ($order->status === 'ready')
        <div class="w-full py-3 text-center text-xs font-medium text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 border-t dark:border-zinc-700">
            {{ $marketplace?->waitingMessage() ?? ($kind === 'pickup' ? 'Pronto — aguardando o cliente retirar' : 'Pronto — aguardando retirada/entrega') }}
        </div>
    @endif
    </div>
</div>
