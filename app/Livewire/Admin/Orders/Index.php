<?php

namespace App\Livewire\Admin\Orders;

use App\Enums\OrderChannel;
use App\Events\OrderStatusUpdated;
use App\Exceptions\IfoodApiException;
use App\Models\Company;
use App\Models\IfoodDispute;
use App\Models\IfoodIntegration;
use App\Models\IfoodOrderEvent;
use App\Models\Order;
use App\Models\Scopes\CompanyScope;
use App\Services\Ifood\IfoodOrderActionService;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class Index extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public string $search = '';

    public string $companyFilter = '';

    /** Filtro de canal: '' (todos), 'chat' (order_type delivery/pickup), 'pdv' (order_type pdv) ou 'delivery' (todo pedido com entrega, via ou não do PDV). */
    public string $channelFilter = '';

    // Permissão e escopo calculados no mount: Locked, senão o navegador poderia mandar
    // isSuperAdmin=true (pedidos de todas as empresas) ou userBranchId=null (todas as filiais).
    #[Locked]
    public bool $isSuperAdmin = false;

    #[Locked]
    public bool $canView = false;

    #[Locked]
    public bool $canUpdate = false;

    #[Locked]
    public bool $canManageClosing = false;

    #[Locked]
    public int $companyId = 0;

    /** Estação do usuário logado ('cozinha'|'bar'|'entrega') quando o papel é restrito a uma estação, senão null. */
    #[Locked]
    public ?string $userStation = null;

    /** Filial do usuário logado quando o papel é restrito a uma (cozinha/bar/entrega/garçom/caixa), senão null. */
    #[Locked]
    public ?int $userBranchId = null;

    #[Url]
    public string $viewMode = 'list';

    /** Pedido com o modal "Confirmar pagamento" (recebido na entrega) aberto, ou null. */
    public ?int $confirmingPaymentOrderId = null;

    /** Pedido iFood com o modal de recusa/cancelamento aberto, ou null. */
    public ?int $ifoodCancelOrderId = null;

    public string $ifoodCancelReason = '';

    /** Pedido do modal já aceito: a ação vira solicitação de cancelamento (muda o texto do modal). */
    public bool $ifoodCancelAccepted = false;

    public array $ifoodCancellationReasons = [];

    /** Pedido com o modal de cancelamento (motivo obrigatório) aberto, ou null. */
    public ?int $cancelOrderId = null;

    public string $cancelReason = '';

    const KANBAN_STATUSES = ['scheduled', 'pending', 'awaiting_payment', 'paid', 'preparing', 'ready', 'out_for_delivery', 'delivered', 'cancelled'];

    const KANBAN_PER_PAGE = 15;

    const URGENCY_WARNING_MINUTES = 10;

    const URGENCY_CRITICAL_MINUTES = 20;

    public array $kanbanPages = [
        'scheduled' => 1,
        'pending' => 1,
        'awaiting_payment' => 1,
        'paid' => 1,
        'preparing' => 1,
        'ready' => 1,
        'out_for_delivery' => 1,
        'delivered' => 1,
        'cancelled' => 1,
    ];

    public function mount(): void
    {
        $user = auth()->user();
        $this->isSuperAdmin = $user->isSuperAdmin();

        if ($this->isSuperAdmin) {
            $this->canView = $this->canUpdate = true;
            $this->canManageClosing = true;
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');
            $this->companyId = $company->id;
            $this->canView = $user->hasPermission('orders.view', $company);
            $this->canUpdate = $user->hasPermission('orders.update', $company);
            $this->canManageClosing = $user->canManageClosing($company);

            $roleSlug = $user->roleForCompany($company);
            $this->userStation = in_array($roleSlug, ['cozinha', 'bar', 'entrega']) ? $roleSlug : null;

            if ($user->isBranchScoped($company)) {
                $this->userBranchId = $user->branchIdForCompany($company);
            }
        }

        // Cozinha, bar e entrega usam fila de cards mobile própria (sem kanban) — força a lista.
        if (in_array($this->userStation, ['cozinha', 'bar', 'entrega'])) {
            $this->viewMode = 'list';
        }
    }

    /** Escuta eventos de pedido da empresa (novo pedido/status alterado) pra manter a listagem/fila em tempo real. */
    public function getListeners(): array
    {
        if (! $this->companyId) {
            return [];
        }

        $listeners = [
            "echo-private:orders.{$this->companyId},NewOrderPlaced" => '$refresh',
            "echo-private:orders.{$this->companyId},OrderStatusUpdated" => '$refresh',
            "echo-private:orders.{$this->companyId},OrderItemsUpdated" => '$refresh',
            "echo-private:orders.{$this->companyId},IfoodOrderUpdated" => '$refresh',
            "echo-private:orders.{$this->companyId},IfoodOrderNotImported" => '$refresh',
        ];

        // "Minha fila" é a tela que cozinha/bar realmente usa no dia a dia (não tem
        // pdv.operate/pdv.waiter_operate pra acessar o Terminal/Mesas do PDV) — sem
        // este listener aqui, "Finalizar Pedido" do garçom nunca chega em lugar
        // nenhum que essas roles conseguem abrir.
        if (in_array($this->userStation, ['cozinha', 'bar'], true)) {
            $listeners["echo-private:orders.{$this->companyId},TabOrderSentToProduction"] = 'onTabOrderSentToProductionBroadcast';
        }

        return $listeners;
    }

    /**
     * Garçom mandou a comanda pra produção — filtra só a estação deste usuário
     * (cozinha só reage a via de cozinha, bar só à de bar) e a filial dele, quando
     * a role tiver uma associada. Mesmo evento/payload que já alimenta o PDV (ver
     * HasAutoPrint::onTabOrderSentToProductionBroadcast).
     */
    public function onTabOrderSentToProductionBroadcast(array $event): void
    {
        if ($this->userBranchId !== null && (int) ($event['branch_id'] ?? 0) !== $this->userBranchId) {
            return;
        }

        $stations = collect($event['stations'] ?? [])->filter(fn ($station) => $station === $this->userStation)->values();

        if ($stations->isEmpty()) {
            return;
        }

        Log::channel('orders')->info('[auto-print] fila cozinha/bar recebeu TabOrderSentToProduction, repassando pro JS imprimir', [
            'order_id' => $event['order_id'] ?? null,
            'user_station' => $this->userStation,
            'user_branch_id' => $this->userBranchId,
            'stations' => $stations->all(),
        ]);

        $this->dispatch('tab-order-finalized', orderId: $event['order_id'], stations: $stations);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
        $this->resetKanbanPages();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingCompanyFilter(): void
    {
        $this->resetPage();
        $this->resetKanbanPages();
    }

    public function updatingChannelFilter(): void
    {
        $this->resetPage();
        $this->resetKanbanPages();
    }

    private function applyChannelFilter(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->when($this->channelFilter === 'pdv', fn ($q) => $q->where('order_type', 'pdv'))
            ->when($this->channelFilter === 'chat', fn ($q) => $q->whereIn('order_type', ['delivery', 'pickup']))
            ->when($this->channelFilter === 'delivery', fn ($q) => $q->deliveryOnly())
            ->when($this->channelFilter === 'ifood', fn ($q) => $q->where('channel', 'ifood'));
    }

    public function setViewMode(string $mode): void
    {
        if (! in_array($mode, ['list', 'kanban'])) {
            return;
        }

        $this->viewMode = $mode;
        $this->resetPage();

        if ($mode === 'kanban') {
            $this->resetKanbanPages();
        }
    }

    public function loadMoreKanban(string $status): void
    {
        if (! array_key_exists($status, $this->kanbanPages)) {
            return;
        }

        $this->kanbanPages[$status]++;
    }

    /** Formata minutos decorridos pra exibição na fila de cozinha/bar (ex: "45 min", "1h20"). */
    public function formatElapsed(int $minutes): string
    {
        if ($minutes < 1) {
            return 'agora';
        }

        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest > 0 ? sprintf('%dh%02d', $hours, $rest) : "{$hours}h";
    }

    private function resetKanbanPages(): void
    {
        $this->kanbanPages = array_fill_keys(array_keys($this->kanbanPages), 1);
    }

    public function updateOrderStatus(int $orderId, string $newStatus): void
    {
        abort_unless($this->canUpdate, 403);

        if (! in_array($newStatus, self::KANBAN_STATUSES)) {
            return;
        }

        $allowedForStation = match ($this->userStation) {
            'cozinha', 'bar' => ['preparing', 'ready'],
            'entrega' => ['out_for_delivery', 'delivered'],
            default => null,
        };

        if ($allowedForStation !== null && ! in_array($newStatus, $allowedForStation)) {
            return;
        }

        $order = $this->findActionableOrder($orderId);

        if ($newStatus === 'out_for_delivery' && ! $order->isDeliveryOrder()) {
            return;
        }

        $previousStatus = $order->status;

        // Pedido iFood: toda etapa passa pela API do iFood antes de mudar aqui.
        // Cancelamento exige motivo fechado (modal); conclusão é sempre do iFood.
        if ($order->channel === OrderChannel::Ifood->value) {
            $this->updateIfoodOrderStatus($order, $newStatus);

            return;
        }

        // Cancelamento exige motivo obrigatório — abre modal em vez de aplicar direto.
        if ($newStatus === 'cancelled') {
            if ($previousStatus !== 'cancelled') {
                $this->openCancelModal($order->id);
            }

            return;
        }

        // "Pago" num pedido PDV ainda sem Payment (a receber) arrastado no kanban, sem passar
        // pelo modal "Confirmar pagamento", cai aqui — sem isso o pedido vira "paid" sem Payment,
        // e o fechamento de caixa conta a venda no TOTAL VENDAS mas não em nenhuma forma de
        // pagamento (ver CashClosingReportService). Não depende do status anterior: agendado
        // costuma passar por "Entregue"/"Preparando" antes de alguém marcar "Pago".
        if ($newStatus === 'paid' && $order->needsPdvPaymentConfirmation()) {
            app(PaymentOrchestrator::class)->confirmDeliveryPayment($order);
        }

        $order->update(['status' => $newStatus]);

        $order->refresh();

        OrderStatusUpdated::dispatch($order);

        app(OrderService::class)->recordStatusHistory($order, auth()->id(), $previousStatus, $newStatus);

        Log::channel('orders')->info('Status do pedido alterado pelo admin via kanban', [
            'order_id' => $order->id,
            'admin_id' => auth()->id(),
            'status_anterior' => $previousStatus,
            'status_novo' => $newStatus,
        ]);
    }

    public function openCancelModal(int $orderId): void
    {
        abort_unless($this->canUpdate, 403);

        $this->cancelOrderId = $orderId;
        $this->cancelReason = '';
        $this->resetErrorBag('cancelReason');
    }

    public function closeCancelModal(): void
    {
        $this->cancelOrderId = null;
        $this->cancelReason = '';
    }

    /**
     * Confirma o cancelamento do pedido. O motivo é obrigatório e o usuário logado
     * fica registrado como operador do cancelamento — ver `OrderService::cancelOrderAsAdmin()`.
     */
    public function confirmCancel(): void
    {
        abort_unless($this->canUpdate, 403);

        $this->validate([
            'cancelReason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        if (! $this->cancelOrderId) {
            return;
        }

        $order = $this->findActionableOrder($this->cancelOrderId);

        $reason = $this->cancelReason;
        $this->closeCancelModal();

        try {
            app(OrderService::class)->cancelOrderAsAdmin($order, auth()->id(), $reason);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $order->refresh();

        OrderStatusUpdated::dispatch($order);

        Log::channel('orders')->info('Pedido cancelado pelo admin via kanban', [
            'order_id' => $order->id,
            'admin_id' => auth()->id(),
        ]);
    }

    /**
     * Confirma pagamento coletado na entrega (PDV "receber na entrega"). Restrito a pedidos
     * do PDV pra não abranger pedidos online aguardando webhook Vindi/Asaas (mesmo status
     * 'awaiting_payment' é usado nesse fluxo, mas ali quem confirma é o gateway, não o admin).
     */
    public function openConfirmPaymentModal(int $orderId): void
    {
        abort_unless($this->canUpdate, 403);

        $this->confirmingPaymentOrderId = $orderId;
    }

    public function closeConfirmPaymentModal(): void
    {
        $this->confirmingPaymentOrderId = null;
    }

    public function confirmPayment(int $orderId): void
    {
        abort_unless($this->canUpdate, 403);

        $this->confirmingPaymentOrderId = null;

        $order = $this->findActionableOrder($orderId);

        if (! $order->needsPdvPaymentConfirmation()) {
            return;
        }

        app(PaymentOrchestrator::class)->confirmDeliveryPayment($order);

        $order->refresh();

        OrderStatusUpdated::dispatch($order);

        Log::channel('orders')->info('Pagamento na entrega confirmado pelo admin via kanban', [
            'order_id' => $order->id,
            'admin_id' => auth()->id(),
        ]);
    }

    /** Botão "Aceitar" do card/alerta de pedido iFood aguardando aceite. */
    public function acceptIfoodOrder(int $orderId): void
    {
        $this->updateOrderStatus($orderId, 'preparing');
    }

    private function updateIfoodOrderStatus(Order $order, string $newStatus): void
    {
        if ($newStatus === 'cancelled') {
            $this->openIfoodCancelModal($order->id);

            return;
        }

        $service = app(IfoodOrderActionService::class);
        $action = match ($newStatus) {
            'preparing' => $order->ifoodDetails()->awaitingConfirmation()
                ? fn () => $service->accept($order, auth()->id())
                : fn () => $service->startPreparation($order, auth()->id()),
            'ready' => fn () => $service->markReady($order, auth()->id()),
            'out_for_delivery' => fn () => $service->dispatch($order, auth()->id()),
            default => null,
        };

        if ($action === null) {
            session()->flash('error', 'No iFood essa etapa não é feita pela loja: a conclusão e o pagamento são registrados pelo próprio iFood.');

            return;
        }

        try {
            $action();
        } catch (Throwable $e) {
            session()->flash('error', IfoodApiException::messageFor($e));
        }
    }

    public function openIfoodCancelModal(int $orderId): void
    {
        abort_unless($this->canUpdate, 403);

        $order = $this->findActionableOrder($orderId);
        try {
            $this->ifoodCancellationReasons = app(IfoodOrderActionService::class)->getCancellationReasons($order);
        } catch (Throwable $e) {
            session()->flash('error', IfoodApiException::messageFor($e));

            return;
        }
        $this->ifoodCancelOrderId = $orderId;
        $this->ifoodCancelAccepted = ! $order->ifoodDetails()?->awaitingConfirmation();
        $this->ifoodCancelReason = '';
    }

    public function closeIfoodCancelModal(): void
    {
        $this->ifoodCancelOrderId = null;
        $this->ifoodCancelAccepted = false;
        $this->ifoodCancelReason = '';
    }

    /**
     * Recusa (pedido ainda não aceito) ou solicita cancelamento (pedido já
     * aceito, aguarda confirmação assíncrona do iFood — status local não muda
     * aqui, ver IfoodOrderActionService::requestCancellation) do pedido iFood.
     */
    public function confirmIfoodCancel(): void
    {
        abort_unless($this->canUpdate, 403);

        if (! $this->ifoodCancelOrderId || $this->ifoodCancelReason === '') {
            return;
        }

        $order = $this->findActionableOrder($this->ifoodCancelOrderId);

        $orderId = $order->id;
        $reason = $this->ifoodCancelReason;
        $wasAccepted = ! $order->ifoodDetails()?->awaitingConfirmation();

        $this->closeIfoodCancelModal();

        try {
            $service = app(IfoodOrderActionService::class);

            if ($wasAccepted) {
                $service->requestCancellation($order, $reason, auth()->id());
                session()->flash('status', 'Cancelamento solicitado ao iFood — aguardando confirmação.');
            } else {
                $service->reject($order, $reason, auth()->id());
                session()->flash('status', 'Recusa enviada ao iFood — aguardando confirmação.');
            }
        } catch (Throwable $e) {
            session()->flash('error', IfoodApiException::messageFor($e));

            return;
        }

        Log::channel('ifood')->info('iFood: recusa/cancelamento acionado pelo admin via kanban', [
            'order_id' => $orderId,
            'admin_id' => auth()->id(),
            'reason' => $reason,
            'ja_aceito' => $wasAccepted,
        ]);
    }

    /** Fechamento do dia: contagem e faturamento (exclui cancelado/reembolsado) por canal — delivery, PDV e geral. */
    private function todayClosing(): array
    {
        $base = $this->isSuperAdmin
            ? Order::withoutGlobalScope(CompanyScope::class)
            : Order::query();

        $base = $base
            ->whereDate('created_at', today())
            ->when($this->userBranchId, fn ($q) => $q->where('branch_id', $this->userBranchId))
            ->when($this->isSuperAdmin && $this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter));

        $summarize = function (\Illuminate\Database\Eloquent\Builder $query): array {
            $orders = $query->get(['total', 'status']);

            return [
                'count' => $orders->count(),
                'total' => $orders->whereNotIn('status', ['cancelled', 'refunded'])->sum('total'),
            ];
        };

        return [
            'delivery' => $summarize((clone $base)->where('order_type', 'delivery')),
            'pdv' => $summarize((clone $base)->where('order_type', 'pdv')),
            'geral' => $summarize(clone $base),
        ];
    }

    public function render()
    {
        $closing = $this->canManageClosing ? $this->todayClosing() : [];

        $companies = $this->isSuperAdmin
            ? Cache::remember('companies:active', now()->addHours(24), fn () => Company::withoutGlobalScope(CompanyScope::class)
                ->where('active', true)
                ->orderBy('name')
                ->get()
            )
            : collect();

        // Cozinha, bar e entrega usam a fila de cards mobile (view "list"), nunca o kanban —
        // mesmo que viewMode tenha ficado com um valor antigo persistido na URL.
        if ($this->viewMode === 'kanban' && ! in_array($this->userStation, ['cozinha', 'bar', 'entrega'])) {
            $baseQuery = $this->isSuperAdmin
                ? Order::withoutGlobalScope(CompanyScope::class)->with(['customer', 'branch', 'company', 'deliveryAddressRecord'])
                : Order::with(['customer', 'branch', 'deliveryAddressRecord']);

            $baseQuery = $baseQuery
                ->when($this->userStation === 'entrega', fn ($q) => $q->deliveryOnly())
                ->when(in_array($this->userStation, ['cozinha', 'bar']), fn ($q) => $q->forStation($this->userStation))
                ->when($this->userBranchId, fn ($q) => $q->where('branch_id', $this->userBranchId))
                ->when($this->search, fn ($q) => $q
                    ->where('order_number', 'like', "%{$this->search}%")
                    ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$this->search}%"))
                )
                ->when($this->isSuperAdmin && $this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter));

            $baseQuery = $this->applyChannelFilter($baseQuery);

            $perPage = self::KANBAN_PER_PAGE;

            $totals = (clone $baseQuery)
                ->whereIn('status', self::KANBAN_STATUSES)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $kanbanColumns = collect($this->kanbanStatuses($totals))
                ->mapWithKeys(function ($status) use ($baseQuery, $perPage, $totals) {
                    $limit = $this->kanbanPages[$status] * $perPage;
                    // payments_exists alimenta o botão "Confirmar pagamento" do card sem uma query por pedido.
                    $fetched = (clone $baseQuery)->withExists('payments')->where('status', $status)->latest()->limit($limit + 1)->get();
                    $hasMore = $fetched->count() > $limit;

                    return [
                        $status => [
                            'orders' => $fetched->take($limit),
                            'hasMore' => $hasMore,
                            'total' => $totals->get($status, 0),
                        ],
                    ];
                });

            $ifoodAwaiting = $this->ifoodAwaitingOrders();
            $ifoodNotImported = $this->ifoodNotImported();

            return view('livewire.admin.orders.index', compact('kanbanColumns', 'companies', 'closing', 'ifoodAwaiting', 'ifoodNotImported'))
                ->layout('layouts.app', ['title' => 'Pedidos']);
        }

        $with = ['customer', 'branch', 'deliveryAddressRecord'];
        if (in_array($this->userStation, ['cozinha', 'bar'])) {
            $with[] = 'items.product.category';
        }

        $query = $this->isSuperAdmin
            ? Order::withoutGlobalScope(CompanyScope::class)->with([...$with, 'company'])
            : Order::with($with);

        $orders = $query
            ->when($this->userStation === 'entrega', fn ($q) => $q->deliveryOnly())
            ->when(in_array($this->userStation, ['cozinha', 'bar']), fn ($q) => $q->forStation($this->userStation))
            ->when($this->userBranchId, fn ($q) => $q->where('branch_id', $this->userBranchId))
            ->when($this->statusFilter === 'new', fn ($q) => $q->whereIn('status', ['pending', 'awaiting_payment', 'paid']))
            ->when($this->statusFilter && $this->statusFilter !== 'new', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search, fn ($q) => $q
                ->where('order_number', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$this->search}%"))
            )
            ->when($this->isSuperAdmin && $this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter));

        $orders = $this->applyChannelFilter($orders)
            ->latest()
            ->paginate(20);

        $ifoodAwaiting = $this->ifoodAwaitingOrders();
        $ifoodNotImported = $this->ifoodNotImported();

        return view('livewire.admin.orders.index', compact('orders', 'companies', 'closing', 'ifoodAwaiting', 'ifoodNotImported'))
            ->layout('layouts.app', ['title' => 'Pedidos']);
    }

    /**
     * Colunas do kanban. Com o filtro iFood, "aguardando aceite" (pending) vem primeiro e
     * somem as colunas que pedido iFood não usa quando estão vazias — antes, três colunas
     * vazias empurravam os pedidos novos pra fora da tela.
     *
     * @param  \Illuminate\Support\Collection<string, int>  $totals
     * @return array<int, string>
     */
    private function kanbanStatuses(\Illuminate\Support\Collection $totals): array
    {
        if ($this->channelFilter !== 'ifood') {
            return self::KANBAN_STATUSES;
        }

        return array_values(array_filter(
            ['pending', 'paid', 'scheduled', 'preparing', 'ready', 'out_for_delivery', 'delivered', 'cancelled'],
            fn (string $status) => ! in_array($status, ['paid', 'scheduled'], true) || $totals->get($status, 0) > 0,
        ));
    }

    /** Some o aviso de um pedido do iFood que não entrou no painel (já resolvido no Gestor de Pedidos). */
    /**
     * Pedido em que o usuário pode agir: super admin vê todas as empresas; papel restrito a uma
     * filial só age nos pedidos dela (o id chega do navegador e pode ser de outra filial).
     */
    private function findActionableOrder(int $orderId): Order
    {
        return ($this->isSuperAdmin ? Order::withoutGlobalScope(CompanyScope::class) : Order::query())
            ->when($this->userBranchId, fn ($q) => $q->where('branch_id', $this->userBranchId))
            ->findOrFail($orderId);
    }

    public function dismissIfoodNotImported(int $eventId): void
    {
        abort_unless($this->canUpdate && $this->companyId, 403);

        IfoodOrderEvent::whereKey($eventId)
            ->where('status', 'failed')
            ->whereIn('ifood_integration_id', IfoodIntegration::withoutGlobalScopes()->where('company_id', $this->companyId)
                ->when($this->userBranchId, fn ($q) => $q->where('branch_id', $this->userBranchId))->select('id'))
            ->update(['status' => 'dismissed']);
    }

    /**
     * Pedidos do iFood das últimas horas que não viraram pedido aqui (item sem vínculo, falha
     * repetida) e negociações de pedidos fora do painel ainda no prazo. Só existem no iFood:
     * o restaurante resolve pelo Gestor de Pedidos, por isso o aviso fica no topo.
     *
     * @return \Illuminate\Support\Collection<int, array{key: string, event_id: ?int, title: string, detail: string}>
     */
    private function ifoodNotImported(): \Illuminate\Support\Collection
    {
        if ($this->userStation || ! $this->canView || ! $this->companyId) {
            return collect();
        }

        $integrationIds = IfoodIntegration::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->when($this->userBranchId, fn ($q) => $q->where('branch_id', $this->userBranchId))
            ->pluck('id');

        if ($integrationIds->isEmpty()) {
            return collect();
        }

        $failed = IfoodOrderEvent::whereIn('ifood_integration_id', $integrationIds)
            ->whereIn('event_type', ['PLC', 'PLACED'])
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subHours(3))
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (IfoodOrderEvent $event) => [
                'key' => 'event-'.$event->id,
                'event_id' => $event->id,
                'title' => 'Pedido do iFood às '.$event->created_at->timezone(config('app.timezone'))->format('H:i').' não entrou no painel',
                'detail' => $event->error ?: 'Aceite ou cancele pelo Gestor de Pedidos do iFood.',
            ]);

        $disputes = IfoodDispute::withoutGlobalScopes()
            ->whereIn('ifood_integration_id', $integrationIds)
            ->whereNull('order_id')
            ->where('status', IfoodDispute::STATUS_PENDING)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (IfoodDispute $dispute) => [
                'key' => 'dispute-'.$dispute->id,
                'event_id' => null,
                'title' => 'Negociação iFood em pedido fora do painel',
                'detail' => $dispute->actionLabel().'. Responda pelo Gestor de Pedidos do iFood'
                    .($dispute->expires_at ? ' até '.$dispute->expires_at->timezone(config('app.timezone'))->format('H:i') : '').'.',
            ]);

        return $disputes->concat($failed)->values();
    }

    /**
     * Pedidos iFood esperando aceite, pro alerta no topo da tela (com contagem do prazo).
     * Cozinha/bar/entrega não aceitam pedido, então não veem o alerta.
     *
     * @return \Illuminate\Support\Collection<int, Order>
     */
    private function ifoodAwaitingOrders(): \Illuminate\Support\Collection
    {
        if ($this->userStation || ! $this->canView) {
            return collect();
        }

        $query = $this->isSuperAdmin
            ? Order::withoutGlobalScope(CompanyScope::class)->when($this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter))
            : Order::query();

        return $query
            ->where('channel', OrderChannel::Ifood->value)
            ->whereIn('status', ['pending', 'paid', 'scheduled'])
            ->where('external_metadata->ifood_status', 'PLACED')
            ->when($this->userBranchId, fn ($q) => $q->where('branch_id', $this->userBranchId))
            ->oldest()
            ->limit(20)
            ->get()
            ->reject(fn (Order $order) => $order->ifoodDetails()->confirmationExpired())
            ->take(10)
            ->values();
    }
}
