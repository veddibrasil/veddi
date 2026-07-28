<?php

namespace App\Livewire\Admin\Orders;

use App\Events\OrderStatusUpdated;
use App\Models\Company;
use App\Models\Order;
use App\Models\Scopes\CompanyScope;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public string $search = '';

    public string $companyFilter = '';

    public bool $isSuperAdmin = false;

    public bool $canView = false;

    public bool $canUpdate = false;

    public int $companyId = 0;

    /** Estação do usuário logado ('cozinha'|'bar'|'entrega') quando o papel é restrito a uma estação, senão null. */
    public ?string $userStation = null;

    #[Url]
    public string $viewMode = 'list';

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
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');
            $this->companyId = $company->id;
            $this->canView = $user->hasPermission('orders.view', $company);
            $this->canUpdate = $user->hasPermission('orders.update', $company);

            $roleSlug = $user->roleForCompany($company);
            $this->userStation = in_array($roleSlug, ['cozinha', 'bar', 'entrega']) ? $roleSlug : null;
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

        return [
            "echo:orders.{$this->companyId},NewOrderPlaced" => '$refresh',
            "echo:orders.{$this->companyId},OrderStatusUpdated" => '$refresh',
        ];
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

        $order = $this->isSuperAdmin
            ? Order::withoutGlobalScope(CompanyScope::class)->findOrFail($orderId)
            : Order::findOrFail($orderId);

        if ($newStatus === 'out_for_delivery' && ! $order->isDeliveryOrder()) {
            return;
        }

        $previousStatus = $order->status;

        try {
            if ($newStatus === 'cancelled') {
                if ($previousStatus !== 'cancelled') {
                    app(OrderService::class)->cancelOrderAsAdmin($order, auth()->id());
                }
            } else {
                $order->update(['status' => $newStatus]);
            }
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $order->refresh();

        OrderStatusUpdated::dispatch($order);

        Log::channel('orders')->info('Status do pedido alterado pelo admin via kanban', [
            'order_id' => $order->id,
            'admin_id' => auth()->id(),
            'status_anterior' => $previousStatus,
            'status_novo' => $newStatus,
        ]);
    }

    /**
     * Confirma pagamento coletado na entrega (PDV "receber na entrega"). Restrito a pedidos
     * do PDV pra não abranger pedidos online aguardando webhook Vindi/Asaas (mesmo status
     * 'awaiting_payment' é usado nesse fluxo, mas ali quem confirma é o gateway, não o admin).
     */
    public function confirmPayment(int $orderId): void
    {
        abort_unless($this->canUpdate, 403);

        $order = $this->isSuperAdmin
            ? Order::withoutGlobalScope(CompanyScope::class)->findOrFail($orderId)
            : Order::findOrFail($orderId);

        if ($order->order_type !== 'pdv' || $order->status !== 'awaiting_payment' || $order->payment()->exists()) {
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

    public function render()
    {
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
                ->when($this->search, fn ($q) => $q
                    ->where('order_number', 'like', "%{$this->search}%")
                    ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$this->search}%"))
                )
                ->when($this->isSuperAdmin && $this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter));

            $perPage = self::KANBAN_PER_PAGE;

            $totals = (clone $baseQuery)
                ->whereIn('status', self::KANBAN_STATUSES)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $kanbanColumns = collect(self::KANBAN_STATUSES)
                ->mapWithKeys(function ($status) use ($baseQuery, $perPage, $totals) {
                    $limit = $this->kanbanPages[$status] * $perPage;
                    $fetched = (clone $baseQuery)->where('status', $status)->latest()->limit($limit + 1)->get();
                    $hasMore = $fetched->count() > $limit;

                    return [
                        $status => [
                            'orders' => $fetched->take($limit),
                            'hasMore' => $hasMore,
                            'total' => $totals->get($status, 0),
                        ],
                    ];
                });

            return view('livewire.admin.orders.index', compact('kanbanColumns', 'companies'))
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
            ->when($this->statusFilter === 'new', fn ($q) => $q->whereIn('status', ['pending', 'awaiting_payment', 'paid']))
            ->when($this->statusFilter && $this->statusFilter !== 'new', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search, fn ($q) => $q
                ->where('order_number', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$this->search}%"))
            )
            ->when($this->isSuperAdmin && $this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter))
            ->latest()
            ->paginate(20);

        return view('livewire.admin.orders.index', compact('orders', 'companies'))
            ->layout('layouts.app', ['title' => 'Pedidos']);
    }
}
