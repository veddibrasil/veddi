<div class="space-y-4" x-data="stationPrintListener()">
@if ($userStation === 'entrega')
    {{-- ── ENTREGA: fila mobile em cards, sem kanban ──────────────────────── --}}
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-neutral-800 dark:text-neutral-100">Minhas entregas</h1>
        <span class="text-xs font-medium px-2 py-1 rounded-full bg-neutral-100 text-neutral-500 dark:bg-zinc-700 dark:text-neutral-400">{{ $orders->total() }}</span>
    </div>

    <flux:input wire:model.live.debounce.400ms="search" placeholder="Buscar por número ou cliente..." />

    <div class="flex gap-2">
        <button wire:click="$set('statusFilter', '')"
                class="flex-1 py-2.5 rounded-lg text-sm font-medium transition-colors {{ $statusFilter === '' ? 'bg-amber-500 text-white' : 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300' }}">
            Todos
        </button>
        <button wire:click="$set('statusFilter', 'out_for_delivery')"
                class="flex-1 py-2.5 rounded-lg text-sm font-medium transition-colors {{ $statusFilter === 'out_for_delivery' ? 'bg-purple-600 text-white' : 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300' }}">
            A caminho
        </button>
        <button wire:click="$set('statusFilter', 'delivered')"
                class="flex-1 py-2.5 rounded-lg text-sm font-medium transition-colors {{ $statusFilter === 'delivered' ? 'bg-emerald-600 text-white' : 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300' }}">
            Entregues
        </button>
    </div>

    <div class="space-y-3">
        @forelse ($orders as $order)
            @php
                $addrFull = $order->deliveryFullAddress();
                $rawPhone = preg_replace('/\D/', '', $order->customer?->phone ?? '');
                $whatsappPhone = strlen($rawPhone) <= 11 ? '55'.$rawPhone : $rawPhone;
            @endphp
            <div wire:key="entrega-card-{{ $order->id }}" class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
                <a href="{{ route('admin.orders.show', $order) }}" wire:navigate class="block px-4 pt-4 pb-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-mono font-semibold text-xs text-neutral-400 dark:text-neutral-500">{{ $order->order_number }}</p>
                            <p class="text-base font-semibold text-neutral-800 dark:text-neutral-100 truncate">{{ $order->customer->name ?? '—' }}</p>
                            @if ($addrFull)
                                <p class="text-sm text-neutral-500 dark:text-neutral-400 truncate">{{ $addrFull }}</p>
                            @endif
                        </div>
                        <span class="shrink-0 text-xs px-2 py-1 rounded-full font-medium
                            @if($order->status === 'delivered') bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-400
                            @elseif($order->status === 'out_for_delivery') bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-400
                            @elseif($order->status === 'cancelled') bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-400
                            @else bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300 @endif">
                            {{ $order->status_label }}
                        </span>
                    </div>
                    <div class="flex items-baseline justify-between mt-2">
                        <p class="text-lg font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($order->total, 2, ',', '.') }}</p>
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">
                            Frete:
                            @if ($order->delivery_fee > 0)
                                R$ {{ number_format($order->delivery_fee, 2, ',', '.') }}
                            @else
                                <span class="text-green-600 dark:text-green-400">Grátis</span>
                            @endif
                        </p>
                    </div>
                </a>

                @if ($addrFull || $rawPhone)
                    <div class="grid grid-cols-3 border-t dark:border-zinc-700 divide-x dark:divide-zinc-700">
                        <a href="tel:{{ $rawPhone }}"
                           class="flex items-center justify-center gap-1.5 py-3 text-xs font-medium text-neutral-600 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-zinc-700/50 transition-colors">
                            📞 Ligar
                        </a>
                        <a href="https://wa.me/{{ $whatsappPhone }}" target="_blank"
                           class="flex items-center justify-center gap-1.5 py-3 text-xs font-medium text-green-700 dark:text-green-400 hover:bg-neutral-50 dark:hover:bg-zinc-700/50 transition-colors">
                            💬 WhatsApp
                        </a>
                        <a href="https://maps.google.com/?q={{ urlencode($addrFull) }}" target="_blank"
                           class="flex items-center justify-center gap-1.5 py-3 text-xs font-medium text-blue-700 dark:text-blue-400 hover:bg-neutral-50 dark:hover:bg-zinc-700/50 transition-colors">
                            📍 Mapa
                        </a>
                    </div>
                @endif

                @if ($canUpdate && in_array($order->status, ['paid', 'preparing', 'ready']))
                    <button wire:click="updateOrderStatus({{ $order->id }}, 'out_for_delivery')"
                            wire:loading.attr="disabled"
                            class="w-full py-3.5 text-sm font-bold text-white bg-purple-600 hover:bg-purple-700 active:bg-purple-800 disabled:opacity-50 transition-colors border-t dark:border-zinc-700">
                        Saiu para entrega
                    </button>
                @elseif ($canUpdate && $order->status === 'out_for_delivery')
                    <button wire:click="updateOrderStatus({{ $order->id }}, 'delivered')"
                            wire:loading.attr="disabled"
                            class="w-full py-3.5 text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 disabled:opacity-50 transition-colors border-t dark:border-zinc-700">
                        Marcar como entregue
                    </button>
                @endif
            </div>
        @empty
            <div class="px-4 py-16 text-center">
                <div class="text-4xl mb-2">🛵</div>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhuma entrega no momento.</p>
            </div>
        @endforelse
    </div>

    <div>{{ $orders->links() }}</div>
@elseif (in_array($userStation, ['cozinha', 'bar']))
    @include('livewire.admin.orders.partials.station-queue')
@else
    <div class="flex items-center justify-between gap-2">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Pedidos</h1>
        <a href="{{ route('admin.orders.closing.pdf', $isSuperAdmin && $companyFilter ? ['company_id' => $companyFilter] : []) }}"
           target="_blank"
           @click.prevent="printClosing('{{ $isSuperAdmin && $companyFilter ? '?company_id='.$companyFilter : '' }}', $el.href)"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg bg-amber-500 text-white hover:bg-amber-600 transition-colors shrink-0">
            📄 Fechamento do dia (PDF)
        </a>
    </div>

    {{-- ── Fechamento do dia ───────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="bg-white border rounded-xl shadow-sm px-4 py-3 dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Delivery hoje</p>
            <p class="text-xl font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($closing['delivery']['total'], 2, ',', '.') }}</p>
            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $closing['delivery']['count'] }} pedido(s)</p>
        </div>
        <div class="bg-white border rounded-xl shadow-sm px-4 py-3 dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs font-medium text-neutral-400 uppercase tracking-wide">PDV hoje</p>
            <p class="text-xl font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($closing['pdv']['total'], 2, ',', '.') }}</p>
            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $closing['pdv']['count'] }} pedido(s)</p>
        </div>
        <div class="bg-white border rounded-xl shadow-sm px-4 py-3 dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Geral hoje</p>
            <p class="text-xl font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($closing['geral']['total'], 2, ',', '.') }}</p>
            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $closing['geral']['count'] }} pedido(s)</p>
        </div>
    </div>

    <div class="flex flex-wrap gap-2">
        <div class="flex-1 min-w-[12rem]">
            <flux:input wire:model.live="search" placeholder="Buscar por número ou cliente..." />
        </div>
        @if(auth()->user()->isSuperAdmin())
        <flux:select wire:model.live="companyFilter" placeholder="Todas as empresas" class="w-full sm:w-48 shrink-0">
            <flux:select.option value="">Todas as empresas</flux:select.option>
            @foreach ($companies as $company)
                <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
            @endforeach
        </flux:select>
        @endif
        <flux:select wire:model.live="channelFilter" placeholder="Todos os canais" class="w-full sm:w-40 shrink-0">
            <flux:select.option value="">Todos os canais</flux:select.option>
            <flux:select.option value="chat">Chat</flux:select.option>
            <flux:select.option value="pdv">PDV</flux:select.option>
            <flux:select.option value="delivery">Entrega</flux:select.option>
        </flux:select>
        @if($viewMode === 'list')
        <flux:select wire:model.live="statusFilter" placeholder="Todos os status" class="w-full sm:w-44 shrink-0">
            <flux:select.option value="">Todos os status</flux:select.option>
            <flux:select.option value="pending">Pendente</flux:select.option>
            <flux:select.option value="awaiting_payment">Aguardando Pagamento</flux:select.option>
            <flux:select.option value="scheduled">Agendado</flux:select.option>
            <flux:select.option value="paid">Pago</flux:select.option>
            <flux:select.option value="preparing">Preparando</flux:select.option>
            <flux:select.option value="ready">Pronto</flux:select.option>
            <flux:select.option value="delivered">Entregue</flux:select.option>
            <flux:select.option value="cancelled">Cancelado</flux:select.option>
        </flux:select>
        @endif

        {{-- View mode toggle --}}
        <div class="flex rounded-lg border border-neutral-200 dark:border-zinc-700 overflow-hidden shrink-0">
            <button wire:click="setViewMode('list')" title="Visualização em lista"
                class="px-2.5 py-1.5 transition-colors {{ $viewMode === 'list' ? 'bg-amber-500 text-white' : 'text-neutral-500 hover:bg-neutral-100 dark:hover:bg-zinc-700' }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                </svg>
            </button>
            <button wire:click="setViewMode('kanban')" title="Visualização em board"
                class="px-2.5 py-1.5 transition-colors {{ $viewMode === 'kanban' ? 'bg-amber-500 text-white' : 'text-neutral-500 hover:bg-neutral-100 dark:hover:bg-zinc-700' }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2" />
                </svg>
            </button>
        </div>
    </div>

    {{-- ── LIST VIEW ──────────────────────────────────────────────────────── --}}
    @if($viewMode === 'list')
    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
        {{-- Cabeçalho de colunas --}}
        <div class="flex items-center justify-between px-4 py-2 border-b bg-neutral-50 dark:bg-zinc-700/50 dark:border-zinc-700">
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Pedido / Cliente</span>
            <div class="flex items-center gap-6">
                <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Total</span>
                <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Status</span>
            </div>
        </div>
        <div class="divide-y dark:divide-zinc-700">
            @forelse ($orders as $order)
                <div class="flex items-center px-4 py-4 hover:bg-neutral-50 transition-colors dark:hover:bg-zinc-700/50">
                    <a href="{{ route('admin.orders.show', $order) }}"
                        class="flex-1 flex items-center justify-between gap-4 min-w-0">
                        <div class="min-w-0">
                            <p class="font-mono font-semibold text-sm text-neutral-800 dark:text-neutral-100">{{ $order->order_number }}</p>
                            @if(auth()->user()->isSuperAdmin() && $order->company)
                                <p class="text-xs font-medium text-amber-600 dark:text-amber-400">{{ $order->company->name }}</p>
                            @endif
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">{{ $order->customer->name ?? '—' }}</p>
                            <p class="text-xs text-neutral-400 dark:text-neutral-500">
                                {{ $order->branch->name ?? '—' }} · {{ $order->created_at->format('d/m H:i') }}
                                <span class="ml-1 px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500 dark:bg-zinc-700 dark:text-neutral-400">{{ $order->origin_label }}</span>
                            </p>
                            @if ($order->scheduled_at)
                                <p class="text-xs text-amber-600 dark:text-amber-400 font-medium">🕐 Agendado: {{ $order->scheduled_at->setTimezone(config('app.timezone'))->format('d/m H:i') }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-4 shrink-0">
                            <p class="font-bold text-sm text-neutral-800 dark:text-neutral-100">R$ {{ number_format($order->total, 2, ',', '.') }}</p>
                            <span class="text-xs px-2 py-1 rounded-full min-w-20 text-center
                                @if($order->status === 'paid' || $order->status === 'delivered') bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400
                                @elseif($order->status === 'preparing' || $order->status === 'ready') bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-400
                                @elseif($order->status === 'cancelled') bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-400
                                @elseif($order->status === 'scheduled') bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-400
                                @elseif($order->status === 'awaiting_payment') bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-400
                                @else bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300 @endif">
                                {{ $order->status_label }}
                            </span>
                        </div>
                    </a>
                    <a href="{{ route('admin.orders.receipt', $userStation ? ['order' => $order->id, 'station' => $userStation] : $order) }}" target="_blank"
                       title="{{ $userStation ? 'Imprimir cupom '.$userStation : 'Imprimir cupom' }}"
                       class="ml-3 shrink-0 p-1.5 text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200 rounded-lg hover:bg-neutral-100 dark:hover:bg-zinc-700 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                    </a>
                </div>
            @empty
                <div class="px-4 py-12 text-center">
                    <div class="text-3xl mb-2">📋</div>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhum pedido encontrado.</p>
                </div>
            @endforelse
        </div>
    </div>

    <div>{{ $orders->links() }}</div>
    @endif

    {{-- ── KANBAN VIEW ─────────────────────────────────────────────────────── --}}
    @if($viewMode === 'kanban')
    @unless($canUpdate)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
            Seu usuário pode visualizar pedidos, mas não tem permissão para alterar status.
        </div>
    @endunless

    @php
        $kanbanMeta = [
            'scheduled'       => ['label' => 'Agendado',           'bg' => 'bg-amber-50 dark:bg-amber-900/20', 'text' => 'text-amber-700 dark:text-amber-400',       'dot' => 'bg-amber-500'],
            'pending'         => ['label' => 'Pendente',            'bg' => 'bg-neutral-100 dark:bg-zinc-700',   'text' => 'text-neutral-600 dark:text-neutral-300',  'dot' => 'bg-neutral-400'],
            'awaiting_payment'=> ['label' => 'Ag. Pagamento',       'bg' => 'bg-yellow-50 dark:bg-yellow-900/20','text' => 'text-yellow-700 dark:text-yellow-400',     'dot' => 'bg-yellow-400'],
            'paid'            => ['label' => 'Pago',                'bg' => 'bg-green-50 dark:bg-green-900/20',  'text' => 'text-green-700 dark:text-green-400',       'dot' => 'bg-green-500'],
            'preparing'       => ['label' => 'Preparando',          'bg' => 'bg-blue-50 dark:bg-blue-900/20',    'text' => 'text-blue-700 dark:text-blue-400',         'dot' => 'bg-blue-500'],
            'ready'           => ['label' => 'Pronto',              'bg' => 'bg-indigo-50 dark:bg-indigo-900/20','text' => 'text-indigo-700 dark:text-indigo-400',     'dot' => 'bg-indigo-500'],
            'out_for_delivery'=> ['label' => 'A caminho',           'bg' => 'bg-purple-50 dark:bg-purple-900/20','text' => 'text-purple-700 dark:text-purple-400',     'dot' => 'bg-purple-500'],
            'delivered'       => ['label' => 'Entregue',            'bg' => 'bg-emerald-50 dark:bg-emerald-900/20','text' => 'text-emerald-700 dark:text-emerald-400',  'dot' => 'bg-emerald-500'],
            'cancelled'       => ['label' => 'Cancelado',           'bg' => 'bg-red-50 dark:bg-red-900/20',      'text' => 'text-red-700 dark:text-red-400',           'dot' => 'bg-red-500'],
        ];
    @endphp
    <div
        x-data="ordersKanban('{{ $this->getId() }}', @js($canUpdate))"
        class="flex gap-4 overflow-x-auto pb-4"
        style="min-height: 60vh"
    >
        @foreach($kanbanColumns as $status => $col)
        @php
            $meta = $kanbanMeta[$status] ?? [
                'label' => $status,
                'bg' => 'bg-neutral-100 dark:bg-zinc-700',
                'text' => 'text-neutral-600 dark:text-neutral-300',
                'dot' => 'bg-neutral-400',
            ];
        @endphp
        <div wire:key="kanban-col-{{ $status }}" class="flex-none w-72">
            {{-- Column header --}}
            <div class="flex items-center gap-2 mb-3 px-1">
                <span class="w-2 h-2 rounded-full {{ $meta['dot'] }} shrink-0"></span>
                <span class="text-sm font-semibold text-neutral-700 dark:text-neutral-200">{{ $meta['label'] }}</span>
                <span class="ml-auto text-xs font-medium px-1.5 py-0.5 rounded-full {{ $meta['bg'] }} {{ $meta['text'] }}">
                    {{ $col['total'] }}
                </span>
            </div>

            {{-- Drop zone --}}
            <div
                data-kanban-col="{{ $status }}"
                class="rounded-xl p-2 min-h-24 space-y-2 {{ $meta['bg'] }} transition-colors"
            >
                @forelse($col['orders'] as $order)
                <div
                    wire:key="kanban-card-{{ $order->id }}"
                    data-order-id="{{ $order->id }}"
                    class="bg-white dark:bg-zinc-800 rounded-lg p-3 shadow-sm border border-neutral-200 dark:border-zinc-700 select-none {{ $canUpdate ? 'cursor-grab active:cursor-grabbing' : 'cursor-default opacity-75' }}"
                >
                    <div class="flex items-start justify-between gap-2 mb-1">
                        <a href="{{ route('admin.orders.show', $order) }}"
                           class="font-mono text-xs font-semibold text-neutral-800 dark:text-neutral-100 hover:underline">
                            {{ $order->order_number }}
                        </a>
                        <a href="{{ route('admin.orders.receipt', $userStation ? ['order' => $order->id, 'station' => $userStation] : $order) }}" target="_blank"
                           title="{{ $userStation ? 'Imprimir cupom '.$userStation : 'Imprimir cupom' }}"
                           class="shrink-0 p-0.5 text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200 rounded transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                            </svg>
                        </a>
                    </div>
                    <p class="text-sm text-neutral-700 dark:text-neutral-300 truncate">{{ $order->customer->name ?? '—' }}</p>
                    <span class="inline-block text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500 dark:bg-zinc-700 dark:text-neutral-400">{{ $order->origin_label }}</span>
                    <div class="flex items-center justify-between mt-2">
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $order->created_at->format('d/m H:i') }}</p>
                        <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($order->total, 2, ',', '.') }}</p>
                    </div>
                    @if(auth()->user()->isSuperAdmin() && $order->company)
                        <p class="text-xs font-medium text-amber-600 dark:text-amber-400 mt-1 truncate">{{ $order->company->name }}</p>
                    @endif
                    @if ($order->scheduled_at)
                        <p class="text-xs text-amber-600 dark:text-amber-400 font-medium mt-1">🕐 {{ $order->scheduled_at->setTimezone(config('app.timezone'))->format('d/m H:i') }}</p>
                    @endif
                    @if ($canUpdate && $order->order_type === 'pdv' && $order->status === 'awaiting_payment')
                        <button wire:click.stop="confirmPayment({{ $order->id }})"
                                wire:confirm="Confirmar que o pagamento foi recebido na entrega?"
                                class="w-full mt-2 py-1 text-xs font-medium rounded-lg bg-green-100 text-green-700 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-400 dark:hover:bg-green-900/50 transition-colors">
                            Confirmar pagamento
                        </button>
                    @endif
                </div>
                @empty
                <div class="flex items-center justify-center h-16 text-xs text-neutral-400 dark:text-neutral-500">
                    Nenhum pedido
                </div>
                @endforelse

                @if($col['hasMore'])
                <button
                    wire:click="loadMoreKanban('{{ $status }}')"
                    wire:loading.attr="disabled"
                    wire:target="loadMoreKanban('{{ $status }}')"
                    class="w-full mt-1 py-1.5 text-xs font-medium rounded-lg border border-dashed border-neutral-300 dark:border-zinc-600 text-neutral-500 dark:text-neutral-400 hover:bg-white/60 dark:hover:bg-zinc-700/60 hover:text-neutral-700 dark:hover:text-neutral-200 transition-colors disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="loadMoreKanban('{{ $status }}')">Carregar mais</span>
                    <span wire:loading wire:target="loadMoreKanban('{{ $status }}')">Carregando...</span>
                </button>
                @endif
            </div>
        </div>
        @endforeach
    </div>
    @endif
@endif
</div>
