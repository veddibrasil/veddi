<?php

namespace App\Livewire\Admin\Orders;

use App\Contracts\RefundServiceInterface;
use App\Events\OrderItemsUpdated;
use App\Events\OrderStatusUpdated;
use App\Jobs\IssueFiscalNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Order\FeeCalculator;
use App\Services\Order\OrderService;
use App\Services\Order\StockService;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    public Order $order;

    public bool $canUpdate = false;

    // ── Manual refund ────────────────────────────────────────────────────────

    public bool $showManualRefundModal = false;

    public string $manualRefundType = 'gateway'; // 'gateway' | 'offline'

    public string $manualRefundJustification = '';

    // ── Address editing ──────────────────────────────────────────────────────

    public bool $editingAddress = false;

    public string $editDeliveryAddress = '';

    public string $editDeliveryNumber = '';

    public string $editDeliveryNeighborhood = '';

    public string $editDeliveryCity = '';

    public string $editDeliveryCep = '';

    public string $editDeliveryComplement = '';

    // ── Items editing ────────────────────────────────────────────────────────

    public bool $editingItems = false;

    /**
     * Locked: só é mutado por métodos do componente (addProductToEdit, applySwapItem,
     * updateItemQuantity, etc), nunca por wire:model. Sem lock, o client poderia injetar
     * product_id/unit_price arbitrários direto no payload de update do Livewire.
     *
     * @var array<int, array{id: int|null, product_id?: int, product_name: string, unit_price: float, quantity: int, subtotal: float, options?: array|null}>
     */
    #[Locked]
    public array $editableItems = [];

    public string $productSearch = '';

    /** @var array<int, array{id: int, name: string, price: float}> */
    #[Locked]
    public array $productResults = [];

    // ── Item swapping (same price) ──────────────────────────────────────────

    public bool $swappingItem = false;

    public ?int $swapItemIndex = null;

    public string $swapProductSearch = '';

    /** @var array<int, array{id: int, name: string, base_price: float}> */
    #[Locked]
    public array $swapProductResults = [];

    #[Locked]
    public ?int $swapProductId = null;

    /** @var array<int, array{id: int, name: string, total_qty: int, fixed: bool, options: array<int, array{id: int, name: string, additional_price: float, paused: bool}>}> */
    #[Locked]
    public array $swapGroups = [];

    /** @var array<int, array<int, int>> selections[groupId][optionId] = qty */
    #[Locked]
    public array $swapSelections = [];

    #[Locked]
    public float $swapCalculatedUnitPrice = 0.0;

    public ?string $swapError = null;

    public int $swapQuantity = 1;

    // ── Fiscal note ──────────────────────────────────────────────────────────

    public bool $showFiscalModal = false;

    public string $fiscalCustomerDocument = '';

    public bool $canIssueFiscal = false;

    /** Estação do usuário logado ('cozinha'|'bar'|'entrega') quando o papel é restrito a uma estação, senão null. */
    public ?string $userStation = null;

    /** Escuta eventos do próprio pedido (status/itens) pra manter a tela em tempo real. */
    public function getListeners(): array
    {
        return [
            "echo:order.{$this->order->id},OrderStatusUpdated" => '$refresh',
            "echo:order.{$this->order->id},OrderItemsUpdated" => '$refresh',
        ];
    }

    public function mount(): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            $this->canUpdate = true;
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');
            $this->canUpdate = $user->hasPermission('orders.update', $company);
            $this->canIssueFiscal = $user->hasPermission('fiscal.issue', $company);

            $roleSlug = $user->roleForCompany($company);
            $this->userStation = in_array($roleSlug, ['cozinha', 'bar', 'entrega']) ? $roleSlug : null;
        }

        $this->order->loadMissing('items.product.category');
    }

    /**
     * Itens visíveis pro papel logado. Cozinha só vê itens de categoria 'cozinha', bar só 'bar'.
     * Itens sem categoria ou de categoria sem estação definida ('ambos') aparecem pros dois.
     * Entrega vê todos os itens (precisa conferir o pedido inteiro pra entregar).
     */
    #[Computed]
    public function visibleItems(): \Illuminate\Support\Collection
    {
        if (! $this->userStation || $this->userStation === 'entrega') {
            return $this->order->items;
        }

        return $this->order->items->filter(fn (OrderItem $item) => $item->matchesStation($this->userStation))->values();
    }

    // ── Status ───────────────────────────────────────────────────────────────

    public function updateStatus(string $status): void
    {
        abort_unless($this->canUpdate, 403);

        $allowed = match ($this->userStation) {
            'cozinha', 'bar' => ['preparing', 'ready'],
            'entrega' => ['out_for_delivery', 'delivered'],
            default => ['pending', 'awaiting_payment', 'paid', 'preparing', 'ready', 'out_for_delivery', 'delivered', 'cancelled'],
        };

        if (! in_array($status, $allowed)) {
            $this->addError('status', 'Status inválido.');

            return;
        }

        if ($status === 'out_for_delivery' && ! $this->order->isDeliveryOrder()) {
            $this->addError('status', 'Este pedido não é de entrega.');

            return;
        }

        $previousStatus = $this->order->status;

        try {
            if ($status === 'cancelled') {
                if ($previousStatus !== 'cancelled') {
                    app(OrderService::class)->cancelOrderAsAdmin($this->order, auth()->id());
                }
            } else {
                $this->order->update(['status' => $status]);
            }
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->order->refresh();

        OrderStatusUpdated::dispatch($this->order);

        Log::channel('orders')->info('Status do pedido alterado pelo admin', [
            'order_id' => $this->order->id,
            'admin_id' => auth()->id(),
            'status_anterior' => $previousStatus,
            'status_novo' => $status,
        ]);

        session()->flash('status', 'Status atualizado.');
    }

    /**
     * Confirma pagamento coletado na entrega (PDV "receber na entrega"). Restrito a pedidos
     * do PDV pra não abranger pedidos online aguardando webhook Vindi/Asaas (mesmo status
     * 'awaiting_payment' é usado nesse fluxo, mas ali quem confirma é o gateway, não o admin).
     */
    public function confirmPayment(): void
    {
        abort_unless($this->canUpdate, 403);

        if ($this->order->order_type !== 'pdv' || $this->order->status !== 'awaiting_payment') {
            $this->addError('status', 'Este pedido não está aguardando confirmação de pagamento na entrega.');

            return;
        }

        if ($this->order->payment()->exists()) {
            $this->addError('status', 'Já existe um pagamento registrado para este pedido.');

            return;
        }

        app(PaymentOrchestrator::class)->confirmDeliveryPayment($this->order);

        $this->order->refresh();

        OrderStatusUpdated::dispatch($this->order);

        Log::channel('orders')->info('Pagamento na entrega confirmado pelo admin', [
            'order_id' => $this->order->id,
            'admin_id' => auth()->id(),
        ]);

        session()->flash('status', 'Pagamento confirmado.');
    }

    // ── Address editing ──────────────────────────────────────────────────────

    public function startEditAddress(): void
    {
        abort_unless($this->canUpdate && $this->order->isEditable(), 403);

        $this->editDeliveryAddress = $this->order->delivery_address ?? $this->order->customer?->address ?? '';
        $this->editDeliveryNumber = $this->order->delivery_number ?? $this->order->customer?->number ?? '';
        $this->editDeliveryNeighborhood = $this->order->delivery_neighborhood ?? $this->order->customer?->neighborhood ?? '';
        $this->editDeliveryCity = $this->order->delivery_city ?? $this->order->customer?->city ?? '';
        $this->editDeliveryCep = $this->order->delivery_cep ?? $this->order->customer?->cep ?? '';
        $this->editDeliveryComplement = $this->order->delivery_complement ?? $this->order->customer?->complement ?? '';
        $this->editingAddress = true;
    }

    public function cancelEditAddress(): void
    {
        $this->editingAddress = false;
        $this->resetErrorBag();
        $this->dispatch('deliveryAddressUpdated');
    }

    public function saveAddress(): void
    {
        abort_unless($this->canUpdate && $this->order->isEditable(), 403);

        $validated = $this->validate([
            'editDeliveryAddress' => ['required', 'string', 'max:255'],
            'editDeliveryNumber' => ['nullable', 'string', 'max:20'],
            'editDeliveryNeighborhood' => ['nullable', 'string', 'max:255'],
            'editDeliveryCity' => ['required', 'string', 'max:255'],
            'editDeliveryCep' => ['nullable', 'string', 'max:9'],
            'editDeliveryComplement' => ['nullable', 'string', 'max:100'],
        ]);

        $this->order->delivery_address = $validated['editDeliveryAddress'];
        $this->order->delivery_number = $validated['editDeliveryNumber'];
        $this->order->delivery_neighborhood = $validated['editDeliveryNeighborhood'];
        $this->order->delivery_city = $validated['editDeliveryCity'];
        $this->order->delivery_cep = $validated['editDeliveryCep'];
        $this->order->delivery_complement = $validated['editDeliveryComplement'];
        $this->order->save();

        $this->order->refresh();
        $this->editingAddress = false;
        $this->dispatch('deliveryAddressUpdated');

        Log::channel('orders')->info('Endereço de entrega editado pelo admin', [
            'order_id' => $this->order->id,
            'admin_id' => auth()->id(),
        ]);

        session()->flash('status', 'Endereço atualizado.');
    }

    // ── Items editing ────────────────────────────────────────────────────────

    public function startEditItems(): void
    {
        abort_unless($this->canUpdate && ! $this->userStation && $this->order->isEditable(), 403);

        $this->order->loadMissing('items');

        $this->editableItems = $this->order->items->map(fn (OrderItem $item) => [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product_name' => $item->product_name,
            'unit_price' => (float) $item->unit_price,
            'quantity' => $item->quantity,
            'subtotal' => (float) $item->subtotal,
            'options' => $item->options,
        ])->values()->toArray();

        $this->productSearch = '';
        $this->productResults = [];
        $this->editingItems = true;
    }

    public function cancelEditItems(): void
    {
        $this->editingItems = false;
        $this->productSearch = '';
        $this->productResults = [];
        $this->resetErrorBag();
    }

    public function updatedProductSearch(): void
    {
        if (strlen(trim($this->productSearch)) < 2) {
            $this->productResults = [];

            return;
        }

        $company = app()->bound('current.company') ? app('current.company') : null;

        $query = Product::query()
            ->where('active', true)
            ->where('name', 'like', '%'.trim($this->productSearch).'%')
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $this->order->branch_id)->where('branch_product.available', true));

        if ($company) {
            $query->where('company_id', $company->id);
        }

        $this->productResults = $query->limit(8)
            ->get(['id', 'name', 'price', 'promo_price_enabled', 'promo_price_type', 'promo_price_value'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => (float) $p->effective_price,
            ])
            ->toArray();
    }

    // ── Swap item (same unit price) ─────────────────────────────────────────

    public function openSwapItem(int $index): void
    {
        abort_unless($this->canUpdate && $this->order->isEditable(), 403);

        if (! isset($this->editableItems[$index])) {
            return;
        }

        $this->swapError = null;
        $this->swapItemIndex = $index;
        $this->swapProductSearch = '';
        $this->swapProductResults = [];
        $this->swapProductId = null;
        $this->swapGroups = [];
        $this->swapSelections = [];
        $this->swapCalculatedUnitPrice = 0.0;
        $this->swapQuantity = 1;
        $this->swappingItem = true;
    }

    public function cancelSwapItem(): void
    {
        $this->swappingItem = false;
        $this->swapItemIndex = null;
        $this->swapProductSearch = '';
        $this->swapProductResults = [];
        $this->swapProductId = null;
        $this->swapGroups = [];
        $this->swapSelections = [];
        $this->swapCalculatedUnitPrice = 0.0;
        $this->swapError = null;
        $this->swapQuantity = 1;
    }

    public function updateSwapQuantity(int $quantity): void
    {
        if (! $this->swappingItem || $this->swapItemIndex === null || ! isset($this->editableItems[$this->swapItemIndex])) {
            return;
        }

        $max = max(1, (int) ($this->editableItems[$this->swapItemIndex]['quantity'] ?? 1));
        $this->swapQuantity = min($max, max(1, $quantity));
    }

    public function updatedSwapProductSearch(): void
    {
        if (! $this->swappingItem) {
            return;
        }

        if (strlen(trim($this->swapProductSearch)) < 2) {
            $this->swapProductResults = [];
            $this->swapProductId = null;
            $this->swapGroups = [];
            $this->swapSelections = [];
            $this->swapCalculatedUnitPrice = 0.0;

            return;
        }

        $company = app()->bound('current.company') ? app('current.company') : null;

        $query = Product::query()
            ->where('active', true)
            ->where('name', 'like', '%'.trim($this->swapProductSearch).'%')
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $this->order->branch_id)->where('branch_product.available', true));

        if ($company) {
            $query->where('company_id', $company->id);
        }

        $this->swapProductResults = $query->limit(8)
            ->get(['id', 'name', 'price', 'promo_price_enabled', 'promo_price_type', 'promo_price_value', 'is_variant'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'base_price' => (float) $p->effective_price,
            ])
            ->toArray();
    }

    public function selectSwapProduct(int $productId): void
    {
        abort_unless($this->canUpdate && $this->order->isEditable(), 403);

        if ($this->swapItemIndex === null || ! isset($this->editableItems[$this->swapItemIndex])) {
            return;
        }

        $product = Product::query()
            ->where('id', $productId)
            ->where('active', true)
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $this->order->branch_id)->where('branch_product.available', true))
            ->with(['optionGroups.options', 'optionGroups.inactiveOptions'])
            ->first();

        if (! $product) {
            return;
        }

        $this->swapError = null;
        $this->swapProductId = $product->id;
        $this->swapProductSearch = $product->name;
        $this->swapProductResults = [];

        $this->swapGroups = $product->optionGroups->values()->map(function ($g, $gi) use ($product) {
            $isVariantPriceGroup = (bool) $product->is_variant && $gi === 0;
            $resolvePrice = fn ($o) => ($isVariantPriceGroup || ! $g->fixed) ? (float) $o->additional_price : 0.0;

            return [
                'id' => $g->id,
                'name' => $g->name,
                'total_qty' => (int) $g->total_qty,
                'fixed' => (bool) $g->fixed,
                'options' => [
                    ...$g->options->map(fn ($o) => [
                        'id' => $o->id,
                        'name' => $o->name,
                        'additional_price' => $resolvePrice($o),
                        'paused' => false,
                    ])->toArray(),
                    ...$g->inactiveOptions->map(fn ($o) => [
                        'id' => $o->id,
                        'name' => $o->name,
                        'additional_price' => $resolvePrice($o),
                        'paused' => true,
                    ])->toArray(),
                ],
            ];
        })->toArray();

        // Prefill selections if same product as current item (when possible)
        $current = $this->editableItems[$this->swapItemIndex];
        $prefill = [];
        if (! empty($current['product_id']) && (int) $current['product_id'] === (int) $product->id && ! empty($current['options'])) {
            foreach (($current['options'] ?? []) as $gId => $gData) {
                foreach (($gData['selections'] ?? []) as $oId => $sel) {
                    $prefill[(int) $gId][(int) $oId] = (int) ($sel['qty'] ?? 0);
                }
            }
        }

        $selections = [];
        foreach ($this->swapGroups as $group) {
            $gid = (int) $group['id'];
            $selections[$gid] = [];
            foreach (($group['options'] ?? []) as $opt) {
                $oid = (int) $opt['id'];
                $selections[$gid][$oid] = (int) ($prefill[$gid][$oid] ?? 0);
            }
        }
        $this->swapSelections = $selections;

        $this->recalculateSwapUnitPrice();
    }

    public function closeSwapProductResults(): void
    {
        $this->swapProductResults = [];
    }

    public function updateSwapSelection(int $groupId, int $optionId, int $qty): void
    {
        if (! $this->swappingItem || $this->swapProductId === null) {
            return;
        }

        foreach ($this->swapGroups as $group) {
            if ((int) ($group['id'] ?? 0) !== $groupId) {
                continue;
            }
            foreach (($group['options'] ?? []) as $opt) {
                if ((int) ($opt['id'] ?? 0) !== $optionId) {
                    continue;
                }
                if ((bool) ($opt['paused'] ?? false)) {
                    $qty = 0;
                }
                break 2;
            }
        }

        $qty = max(0, $qty);
        $this->swapSelections[$groupId][$optionId] = $qty;
        $this->recalculateSwapUnitPrice();
    }

    #[Computed]
    public function statusMap(): array
    {
        return [
            'awaiting_payment' => ['label' => 'Ag. Pagamento', 'active' => 'bg-yellow-500 text-white',  'inactive' => 'bg-yellow-50 text-yellow-700 hover:bg-yellow-100 dark:bg-yellow-900/30 dark:text-yellow-400 dark:hover:bg-yellow-900/50'],
            'paid' => ['label' => 'Pago',          'active' => 'bg-green-500 text-white',   'inactive' => 'bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-900/30 dark:text-green-400 dark:hover:bg-green-900/50'],
            'preparing' => ['label' => 'Preparando',    'active' => 'bg-blue-500 text-white',    'inactive' => 'bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-900/50'],
            'ready' => ['label' => 'Pronto',        'active' => 'bg-indigo-500 text-white',  'inactive' => 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-900/30 dark:text-indigo-400 dark:hover:bg-indigo-900/50'],
            'out_for_delivery' => ['label' => 'A caminho',  'active' => 'bg-purple-500 text-white',  'inactive' => 'bg-purple-50 text-purple-700 hover:bg-purple-100 dark:bg-purple-900/30 dark:text-purple-400 dark:hover:bg-purple-900/50'],
            'delivered' => ['label' => 'Entregue',      'active' => 'bg-teal-500 text-white',    'inactive' => 'bg-teal-50 text-teal-700 hover:bg-teal-100 dark:bg-teal-900/30 dark:text-teal-400 dark:hover:bg-teal-900/50'],
            'cancelled' => ['label' => 'Cancelado',     'active' => 'bg-red-500 text-white',     'inactive' => 'bg-red-50 text-red-700 hover:bg-red-100 dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-900/50'],
        ];
    }

    /**
     * Status exibidos como botões: cozinha/bar só veem preparing/ready, entrega só vê
     * out_for_delivery/delivered; "A caminho" só aparece pra pedidos de entrega (não faz
     * sentido em balcão/retirada).
     */
    #[Computed]
    public function visibleStatusMap(): array
    {
        $map = $this->statusMap;

        if (in_array($this->userStation, ['cozinha', 'bar'])) {
            return array_intersect_key($map, array_flip(['preparing', 'ready']));
        }

        if ($this->userStation === 'entrega') {
            return array_intersect_key($map, array_flip(['out_for_delivery', 'delivered']));
        }

        if (! $this->order->isDeliveryOrder()) {
            unset($map['out_for_delivery']);
        }

        return $map;
    }

    private function recalculateSwapUnitPrice(): void
    {
        if ($this->swapProductId === null) {
            $this->swapCalculatedUnitPrice = 0.0;

            return;
        }

        $product = Product::find($this->swapProductId);
        if (! $product) {
            $this->swapCalculatedUnitPrice = 0.0;

            return;
        }

        $extra = 0.0;
        foreach ($this->swapGroups as $group) {
            $gid = (int) ($group['id'] ?? 0);
            foreach (($group['options'] ?? []) as $opt) {
                $oid = (int) ($opt['id'] ?? 0);
                $qty = (int) ($this->swapSelections[$gid][$oid] ?? 0);
                $extra += $qty * (float) ($opt['additional_price'] ?? 0);
            }
        }

        $this->swapCalculatedUnitPrice = round(((float) $product->effective_price) + $extra, 2);
    }

    private function normalizeSwapOptionsForStorage(): ?array
    {
        if ($this->swapProductId === null) {
            return null;
        }

        if (empty($this->swapGroups)) {
            return null;
        }

        $out = [];
        foreach ($this->swapGroups as $group) {
            $gid = (int) ($group['id'] ?? 0);
            $gName = (string) ($group['name'] ?? '');
            $gTotal = (int) ($group['total_qty'] ?? 0);

            $selections = [];
            foreach (($group['options'] ?? []) as $opt) {
                $oid = (int) ($opt['id'] ?? 0);
                $qty = (int) ($this->swapSelections[$gid][$oid] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $selections[$oid] = [
                    'name' => (string) ($opt['name'] ?? ''),
                    'qty' => $qty,
                    'additional_price' => (float) ($opt['additional_price'] ?? 0),
                ];
            }

            if (empty($selections)) {
                continue;
            }

            $out[$gid] = [
                'group_name' => $gName,
                'total_qty' => $gTotal,
                'selections' => $selections,
            ];
        }

        return empty($out) ? null : $out;
    }

    public function applySwapItem(): void
    {
        abort_unless($this->canUpdate && $this->order->isEditable(), 403);

        $this->swapError = null;

        if (! $this->swappingItem || $this->swapItemIndex === null || ! isset($this->editableItems[$this->swapItemIndex])) {
            return;
        }

        if ($this->swapProductId === null) {
            $this->swapError = 'Selecione um produto.';

            return;
        }

        // Validate group totals
        foreach ($this->swapGroups as $group) {
            $gid = (int) ($group['id'] ?? 0);
            $isFixed = (bool) ($group['fixed'] ?? false);
            if ($isFixed) {
                continue;
            }
            $limit = (int) ($group['total_qty'] ?? 0);
            if ($limit <= 0) {
                continue;
            }
            $total = array_sum(array_map('intval', $this->swapSelections[$gid] ?? []));
            if ($total > $limit) {
                $this->swapError = 'Revise as opções: você selecionou mais do que o permitido em "'.$group['name'].'".';

                return;
            }
        }

        $this->recalculateSwapUnitPrice();

        $targetUnit = (float) ($this->editableItems[$this->swapItemIndex]['unit_price'] ?? 0.0);
        $candidateUnit = (float) $this->swapCalculatedUnitPrice;

        if (abs($targetUnit - $candidateUnit) > 0.009) {
            $this->swapError = 'A troca só é permitida quando o valor unitário permanece o mesmo.';

            return;
        }

        $product = Product::find($this->swapProductId);
        if (! $product) {
            $this->swapError = 'Produto inválido.';

            return;
        }

        $this->editableItems[$this->swapItemIndex]['product_id'] = $product->id;
        $newOptions = $this->normalizeSwapOptionsForStorage();

        $originalQty = max(1, (int) ($this->editableItems[$this->swapItemIndex]['quantity'] ?? 1));
        $swapQty = min($originalQty, max(1, (int) $this->swapQuantity));

        if ($swapQty < $originalQty) {
            // Split: swap only part of the quantity into a new line item
            $this->editableItems[$this->swapItemIndex]['quantity'] = $originalQty - $swapQty;
            $this->editableItems[$this->swapItemIndex]['subtotal'] = round($targetUnit * ($originalQty - $swapQty), 2);

            $this->editableItems[] = [
                'id' => null,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit_price' => $targetUnit, // must remain equal
                'quantity' => $swapQty,
                'subtotal' => round($targetUnit * $swapQty, 2),
                'options' => $newOptions,
            ];
        } else {
            // Full replace
            $this->editableItems[$this->swapItemIndex]['product_id'] = $product->id;
            $this->editableItems[$this->swapItemIndex]['product_name'] = $product->name;
            $this->editableItems[$this->swapItemIndex]['options'] = $newOptions;
            $this->editableItems[$this->swapItemIndex]['subtotal'] = round($targetUnit * $originalQty, 2);
        }

        $this->cancelSwapItem();
    }

    public function addProductToEdit(int $productId): void
    {
        abort_unless($this->canUpdate && $this->order->isEditable(), 403);

        $product = collect($this->productResults)->firstWhere('id', $productId);

        if (! $product) {
            return;
        }

        // Check if already in list — increase quantity instead
        foreach ($this->editableItems as $i => $item) {
            if (isset($item['product_id']) && $item['product_id'] === $productId) {
                $this->editableItems[$i]['quantity']++;
                $this->editableItems[$i]['subtotal'] = $item['unit_price'] * $this->editableItems[$i]['quantity'];
                $this->productSearch = '';
                $this->productResults = [];

                return;
            }
        }

        $this->editableItems[] = [
            'id' => null, // new item, no DB id yet
            'product_id' => $productId,
            'product_name' => $product['name'],
            'unit_price' => $product['price'],
            'quantity' => 1,
            'subtotal' => $product['price'],
        ];

        $this->productSearch = '';
        $this->productResults = [];
    }

    public function updateItemQuantity(int $index, int $quantity): void
    {
        if ($quantity < 1) {
            return;
        }

        if (! isset($this->editableItems[$index])) {
            return;
        }

        $this->editableItems[$index]['quantity'] = $quantity;
        $this->editableItems[$index]['subtotal'] = $this->editableItems[$index]['unit_price'] * $quantity;
    }

    public function removeItem(int $index): void
    {
        abort_unless($this->canUpdate && $this->order->isEditable(), 403);

        unset($this->editableItems[$index]);
        $this->editableItems = array_values($this->editableItems);
    }

    public function saveItems(): void
    {
        abort_unless($this->canUpdate && $this->order->isEditable(), 403);

        if (empty($this->editableItems)) {
            $this->addError('editableItems', 'O pedido deve ter pelo menos um item.');

            return;
        }

        $this->order->refresh();
        $originalSubtotal = round((float) $this->order->subtotal, 2);
        $newSubtotal = round(collect($this->editableItems)->sum(function ($item) {
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $unit = (float) ($item['unit_price'] ?? 0.0);

            return $qty * $unit;
        }), 2);

        if (abs($originalSubtotal - $newSubtotal) > 0.009) {
            $this->addError('editableItems', 'Você só pode salvar se o subtotal permanecer igual ao original.');

            return;
        }

        $productIds = collect($this->editableItems)->pluck('product_id')->filter()->unique();
        if ($productIds->isNotEmpty()) {
            $validCount = Product::whereIn('id', $productIds)->count();
            if ($validCount !== $productIds->count()) {
                $this->addError('editableItems', 'Um ou mais produtos são inválidos para esta empresa.');

                return;
            }
        }

        $currentCompany = app()->bound('current.company') ? app('current.company') : null;

        DB::transaction(function () use ($currentCompany) {
            $existingIds = collect($this->editableItems)
                ->filter(fn ($item) => ! empty($item['id']))
                ->pluck('id')
                ->all();

            // Delete removed items
            $this->order->items()->whereNotIn('id', $existingIds)->delete();

            foreach ($this->editableItems as $item) {
                $quantity = max(1, (int) $item['quantity']);
                $unitPrice = (float) $item['unit_price'];
                $subtotal = round($unitPrice * $quantity, 2);

                if (! empty($item['id'])) {
                    OrderItem::where('id', $item['id'])->update([
                        'product_id' => $item['product_id'] ?? null,
                        'product_name' => $item['product_name'],
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'subtotal' => $subtotal,
                        'options' => $item['options'] ?? null,
                    ]);
                } else {
                    OrderItem::create([
                        'order_id' => $this->order->id,
                        'product_id' => $item['product_id'],
                        'product_name' => $item['product_name'],
                        'unit_price' => $unitPrice,
                        'quantity' => $quantity,
                        'subtotal' => $subtotal,
                        'options' => $item['options'] ?? null,
                    ]);
                }
            }

            $this->order->refresh();

            $subtotal = $this->order->items()->sum('subtotal');
            $discount = (float) $this->order->discount;
            $deliveryFee = (float) $this->order->delivery_fee;
            $total = max(0, $subtotal + $deliveryFee - $discount);

            $fee = 0.0;
            $netValue = $total;
            if ($currentCompany) {
                $feeBase = max(0.0, $subtotal - $discount);
                $fees = app(FeeCalculator::class)->calculate($currentCompany, $feeBase, $total);
                $fee = $fees['fee'];
                $netValue = $fees['net_value'];
            }

            $this->order->update([
                'subtotal' => $subtotal,
                'total' => $total,
                'fee' => $fee,
                'net_value' => $netValue,
            ]);
        });

        $this->order->refresh();
        $this->editingItems = false;
        $this->productSearch = '';
        $this->productResults = [];

        OrderItemsUpdated::dispatch($this->order);

        Log::channel('orders')->info('Itens do pedido editados pelo admin', [
            'order_id' => $this->order->id,
            'admin_id' => auth()->id(),
            'novo_total' => $this->order->total,
        ]);

        session()->flash('status', 'Itens atualizados.');
    }

    // ── Manual refund ────────────────────────────────────────────────────────

    public function openManualRefundModal(): void
    {
        abort_unless($this->canUpdate, 403);

        $this->order->loadMissing('payment');

        if (! $this->order->payment || $this->order->payment->status !== 'paid') {
            session()->flash('error', 'Pagamento não elegível para reembolso.');

            return;
        }

        $this->manualRefundType = 'gateway';
        $this->manualRefundJustification = '';
        $this->showManualRefundModal = true;
    }

    public function closeManualRefundModal(): void
    {
        $this->showManualRefundModal = false;
    }

    public function manualRefund(): void
    {
        abort_unless($this->canUpdate, 403);

        $this->validate([
            'manualRefundType' => ['required', 'in:gateway,offline'],
            'manualRefundJustification' => $this->manualRefundType === 'offline'
                ? ['required', 'string', 'min:10']
                : ['nullable'],
        ]);

        $this->order->loadMissing('payment');

        $payment = $this->order->payment;

        if (! $payment || $payment->status !== 'paid') {
            session()->flash('error', 'Pagamento não elegível para reembolso.');
            $this->showManualRefundModal = false;

            return;
        }

        if ($this->order->status !== 'cancelled') {
            $this->order->update(['status' => 'cancelled']);
            $this->order->refresh();
            app(StockService::class)->restoreForOrder($this->order);
            OrderStatusUpdated::dispatch($this->order);
        }

        $reason = $this->manualRefundType === 'offline'
            ? 'store_issue'
            : 'customer_request';

        $refund = app(RefundServiceInterface::class)->initiateRefund(
            $this->order,
            $payment,
            (float) $payment->amount,
            'admin',
            auth()->id(),
            $reason,
        );

        if ($this->manualRefundType === 'offline') {
            // Mark immediately as succeeded — no gateway call needed
            app(RefundServiceInterface::class)->markSucceeded($refund, [
                'external_refund_id' => null,
                'external_status' => 'OFFLINE',
                'raw' => ['justification' => $this->manualRefundJustification],
            ]);
        }

        Log::channel('payments')->info('Reembolso manual iniciado pelo admin', [
            'order_id' => $this->order->id,
            'admin_id' => auth()->id(),
            'refund_id' => $refund->id,
            'type' => $this->manualRefundType,
            'amount' => $payment->amount,
        ]);

        $this->showManualRefundModal = false;
        session()->flash('status', $this->manualRefundType === 'offline'
            ? 'Reembolso offline registrado com sucesso.'
            : 'Reembolso via gateway iniciado. Acompanhe o status abaixo.'
        );
    }

    // ── Fiscal note ──────────────────────────────────────────────────────────

    public function openFiscalModal(): void
    {
        abort_unless($this->canIssueFiscal, 403);

        $this->fiscalCustomerDocument = '';
        $this->showFiscalModal = true;
    }

    public function closeFiscalModal(): void
    {
        $this->showFiscalModal = false;
    }

    public function issueFiscalNote(): void
    {
        abort_unless($this->canIssueFiscal, 403);

        $this->validate([
            'fiscalCustomerDocument' => ['nullable', 'string', 'max:20'],
        ]);

        IssueFiscalNote::dispatch($this->order->id, $this->fiscalCustomerDocument ?: null);

        $this->showFiscalModal = false;
        session()->flash('status', 'Emissão de NFC-e enfileirada.');
    }

    public function render()
    {
        $this->order->loadMissing('activeFiscalNote');

        return view('livewire.admin.orders.show')
            ->layout('layouts.app', ['title' => "Pedido {$this->order->order_number}"]);
    }
}
