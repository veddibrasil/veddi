<?php

namespace App\Livewire\Admin\Pdv;

use App\Exceptions\CouponException;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PdvCashSession;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Order\CouponService;
use App\Services\Order\OrderCancellationPolicy;
use App\Services\Order\OrderService;
use App\Services\Order\StockService;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Terminal extends Component
{
    // ── Estado da interface ──────────────────────────────────────────────────
    public string $step = 'catalog'; // open_cash | catalog | payment | success | close_cash

    // ── Filial ───────────────────────────────────────────────────────────────
    public ?int $selectedBranchId = null;

    // ── Catálogo ─────────────────────────────────────────────────────────────
    public string $search = '';

    public ?int $activeCategoryId = null;

    // ── Carrinho: [cartKey => [product_id, name, price, qty, options?]] ──────
    public array $cart = [];

    // ── Cliente (opcional) ───────────────────────────────────────────────────
    public string $customerQuery = '';

    public string $customerName = '';

    public ?int $customerId = null;

    public bool $customerFound = false;

    public array $customerResults = [];

    // ── Pagamento ─────────────────────────────────────────────────────────────
    public string $paymentMethod = 'cash';

    public string $cashReceivedInput = '';

    // ── PIX ───────────────────────────────────────────────────────────────────
    public ?string $pixCopyPaste = null;

    public ?string $pixQrCode = null;

    public ?int $pixOrderId = null;

    // ── Cupom ─────────────────────────────────────────────────────────────────
    public string $couponInput = '';

    public ?array $appliedCoupon = null;

    public float $couponDiscount = 0.0;

    public ?string $couponError = null;

    // ── Observação ────────────────────────────────────────────────────────────
    public string $notes = '';

    // ── Resultado ─────────────────────────────────────────────────────────────
    public float $changeAmount = 0.0;

    public ?string $lastOrderNumber = null;

    public ?float $lastOrderTotal = null;

    public ?int $lastOrderId = null;

    public bool $confirmingCancelOrder = false;

    // ── Cliente novo (criação inline) ─────────────────────────────────────────
    public bool $showCreateCustomer = false;

    public string $newCustomerName = '';

    public string $newCustomerPhone = '';

    public ?string $createCustomerError = null;

    // ── Caixa (sessão PDV) ────────────────────────────────────────────────────
    public ?int $cashSessionId = null;

    public string $openingAmountInput = '';

    public string $closingAmountInput = '';

    // ── Permissões ────────────────────────────────────────────────────────────
    public bool $canOperate = false;

    public function mount(): void
    {
        $company = app()->bound('current.company') ? app('current.company') : null;
        $user = auth()->user();

        abort_unless($company, 403);
        abort_unless($company->plan?->hasPdv(), 403, 'Seu plano não inclui acesso ao PDV.');
        abort_unless($user?->hasPermission('pdv.operate', $company), 403);

        $this->canOperate = true;

        $branch = Branch::where('company_id', $company->id)
            ->where('active', true)
            ->orderBy('id')
            ->first();

        $this->selectedBranchId = $branch?->id;
        $this->syncCashSession();
    }

    // ── Filial ───────────────────────────────────────────────────────────────

    public function updatedSelectedBranchId(): void
    {
        $this->cart = [];
        $this->activeCategoryId = null;
        $this->search = '';
        $this->syncCashSession();
    }

    // ── Catálogo ─────────────────────────────────────────────────────────────

    public function selectCategory(?int $categoryId): void
    {
        $this->activeCategoryId = $categoryId;
        $this->search = '';
    }

    // ── Carrinho ─────────────────────────────────────────────────────────────

    public function addProduct(int $productId): void
    {
        if (! $this->selectedBranchId) {
            return;
        }

        $product = Product::withoutGlobalScopes()
            ->whereHas('branches', fn ($q) => $q
                ->where('branches.id', $this->selectedBranchId)
                ->where('branch_product.available', true)
            )
            ->where('active', true)
            ->find($productId);

        if (! $product) {
            return;
        }

        if (! $this->checkStockBeforeAdd($productId, $product->name)) {
            return;
        }

        $cartKey = (string) $productId;

        if (isset($this->cart[$cartKey])) {
            $this->cart[$cartKey]['qty']++;
        } else {
            $this->cart[$cartKey] = [
                'product_id' => $productId,
                'name' => $product->name,
                'price' => (float) $product->effective_price,
                'qty' => 1,
            ];
        }

        $this->cart = $this->cart;
    }

    public function addProductWithOptions(int $productId, array $optionSelections): void
    {
        if (! $this->selectedBranchId) {
            return;
        }

        $product = Product::withoutGlobalScopes()
            ->with('optionGroups')
            ->whereHas('branches', fn ($q) => $q
                ->where('branches.id', $this->selectedBranchId)
                ->where('branch_product.available', true)
            )
            ->where('active', true)
            ->find($productId);

        if (! $product) {
            return;
        }

        if (! $this->checkStockBeforeAdd($productId, $product->name)) {
            return;
        }

        $allFixed = $product->optionGroups->isNotEmpty()
            && $product->optionGroups->every(fn ($g) => $g->fixed);

        if ($allFixed) {
            $key = (string) $productId;
            if (isset($this->cart[$key])) {
                $this->cart[$key]['qty']++;
            } else {
                $this->cart[$key] = [
                    'product_id' => $productId,
                    'name' => $product->name,
                    'price' => (float) $product->effective_price,
                    'qty' => 1,
                    'options' => $optionSelections,
                ];
            }
        } else {
            $index = 1;
            while (isset($this->cart["{$productId}_{$index}"])) {
                $index++;
            }
            $this->cart["{$productId}_{$index}"] = [
                'product_id' => $productId,
                'name' => $product->name,
                'price' => (float) $product->effective_price,
                'qty' => 1,
                'options' => $optionSelections,
            ];
        }

        $this->cart = $this->cart;
    }

    public function updateCartQty(string $cartKey, int $qty): void
    {
        if ($qty <= 0) {
            $this->removeItem($cartKey);

            return;
        }
        $this->cart[$cartKey]['qty'] = $qty;
        $this->cart = $this->cart;
    }

    public function decrementProductFromCart(int $productId): void
    {
        $cart = $this->cart;
        $directKey = (string) $productId;

        if (isset($cart[$directKey])) {
            $this->updateCartQty($directKey, (int) ($cart[$directKey]['qty'] ?? 0) - 1);

            return;
        }

        $keys = array_keys($cart);
        for ($i = count($keys) - 1; $i >= 0; $i--) {
            $key = (string) $keys[$i];
            $item = $cart[$key] ?? null;
            $itemProductId = (int) ($item['product_id'] ?? (int) explode('_', $key)[0]);

            if ($itemProductId !== $productId) {
                continue;
            }

            $this->updateCartQty($key, (int) ($item['qty'] ?? 0) - 1);

            return;
        }
    }

    public function removeItem(string $cartKey): void
    {
        unset($this->cart[$cartKey]);
        $this->cart = $this->cart;
    }

    public function clearCart(): void
    {
        $this->cart = [];
        $this->step = 'catalog';
        $this->resetPaymentState();
    }

    // ── Cliente ───────────────────────────────────────────────────────────────

    public function lookupCustomer(): void
    {
        $this->customerFound = false;
        $this->customerId = null;
        $this->customerResults = [];

        $query = trim($this->customerQuery);

        if (blank($query)) {
            return;
        }

        $company = app('current.company');
        $normalized = preg_replace('/\D/', '', $query);

        $results = Customer::where('company_id', $company->id)
            ->where('phone', '!=', 'pdv-guest')
            ->where(function ($q) use ($query, $normalized) {
                $q->where('name', 'like', '%'.$query.'%');
                if (filled($normalized)) {
                    $q->orWhere('phone', 'like', '%'.$normalized.'%')
                        ->orWhere('tax_id', 'like', '%'.$normalized.'%');
                }
            })
            ->limit(5)
            ->get(['id', 'name', 'phone', 'tax_id']);

        if ($results->isEmpty()) {
            return;
        }

        if ($results->count() === 1) {
            $customer = $results->first();
            $this->customerId = $customer->id;
            $this->customerName = $customer->name ?? '';
            $this->customerFound = true;

            return;
        }

        $this->customerResults = $results->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name ?? '—',
            'phone' => $c->phone,
            'tax_id' => $c->tax_id,
        ])->toArray();
    }

    public function selectCustomer(int $customerId): void
    {
        $company = app('current.company');

        $customer = Customer::where('company_id', $company->id)->find($customerId);

        if (! $customer) {
            return;
        }

        $this->customerId = $customer->id;
        $this->customerName = $customer->name ?? '';
        $this->customerFound = true;
        $this->customerResults = [];
        $this->customerQuery = $customer->name ?? '';
    }

    public function showCreateCustomerForm(): void
    {
        $this->showCreateCustomer = true;
        $this->newCustomerName = trim($this->customerQuery);
        $this->newCustomerPhone = '';
        $this->createCustomerError = null;
    }

    public function cancelCreateCustomer(): void
    {
        $this->showCreateCustomer = false;
        $this->newCustomerName = '';
        $this->newCustomerPhone = '';
        $this->createCustomerError = null;
    }

    public function createCustomer(): void
    {
        $this->createCustomerError = null;
        $name = trim($this->newCustomerName);
        $phone = preg_replace('/\D/', '', $this->newCustomerPhone);

        if (blank($name)) {
            $this->createCustomerError = 'Nome obrigatório.';

            return;
        }

        if (blank($phone)) {
            $this->createCustomerError = 'Telefone obrigatório.';

            return;
        }

        $company = app('current.company');

        $existing = Customer::where('company_id', $company->id)
            ->where('phone', $phone)
            ->first();

        if ($existing) {
            $this->createCustomerError = 'Já existe um cliente com esse telefone.';

            return;
        }

        $customer = Customer::create([
            'company_id' => $company->id,
            'name' => $name,
            'phone' => $phone,
        ]);

        $this->customerId = $customer->id;
        $this->customerName = $customer->name;
        $this->customerFound = true;
        $this->customerQuery = $customer->name;
        $this->customerResults = [];
        $this->showCreateCustomer = false;
        $this->newCustomerName = '';
        $this->newCustomerPhone = '';
    }

    // ── Pagamento ─────────────────────────────────────────────────────────────

    public function proceedToPayment(): void
    {
        if (empty($this->cart) || ! $this->selectedBranchId) {
            return;
        }

        $this->step = 'payment';
        $this->resetPaymentState();
    }

    public function backToCatalog(): void
    {
        $this->step = 'catalog';
        $this->resetPaymentState();
    }

    // ── Cupom ─────────────────────────────────────────────────────────────────

    public function applyCoupon(): void
    {
        $this->couponError = null;
        $code = strtoupper(trim($this->couponInput));

        if ($code === '') {
            $this->couponError = 'Informe um código de cupom.';

            return;
        }

        try {
            $couponService = app(CouponService::class);

            $coupon = $couponService->validate(
                $code,
                $this->customerId ?? 0,
                $this->cart,
                $this->cartTotal
            );

            $discount = $couponService->calculateDiscount($coupon, $this->cart, $this->cartTotal, 0.0);

            $this->appliedCoupon = [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'type' => $coupon->type,
                'discount' => $discount,
                'label' => $coupon->name,
            ];
            $this->couponDiscount = $discount;
        } catch (CouponException $e) {
            $this->couponError = $e->getMessage();
        }
    }

    public function removeCoupon(): void
    {
        $this->appliedCoupon = null;
        $this->couponDiscount = 0.0;
        $this->couponError = null;
        $this->couponInput = '';
    }

    public function processOrder(): void
    {
        if (empty($this->cart) || ! $this->selectedBranchId) {
            return;
        }

        $company = app('current.company');
        $customerId = $this->resolveCustomerId($company);

        $orderCart = [];
        foreach ($this->cart as $cartKey => $item) {
            $orderCart[$cartKey] = [
                'product_id' => $item['product_id'],
                'qty' => $item['qty'],
                'options' => $item['options'] ?? [],
            ];
        }

        $coupon = $this->appliedCoupon
            ? Coupon::find($this->appliedCoupon['id'])
            : null;

        DB::beginTransaction();
        try {
            $isPaidOnCreate = in_array($this->paymentMethod, ['cash', 'credit_card']);

            $order = app(OrderService::class)->createOrder(
                customerId: $customerId,
                branchId: $this->selectedBranchId,
                cart: $orderCart,
                notes: $this->notes,
                paymentMethod: $this->paymentMethod,
                orderType: 'pdv',
                status: $isPaidOnCreate ? 'paid' : 'awaiting_payment',
                coupon: $coupon,
            );

            if ($this->paymentMethod === 'cash') {
                $cashReceived = (float) str_replace(',', '.', $this->cashReceivedInput ?: $order->total);
                $order->cash_received = $cashReceived;
                $order->cash_change = max(0.0, round($cashReceived - (float) $order->total, 2));
                $order->save();

                $result = app(PaymentOrchestrator::class)->processCash($order);
                $this->changeAmount = $result['change'];
            } elseif ($this->paymentMethod === 'credit_card') {
                app(PaymentOrchestrator::class)->processCardMachine($order);
            } elseif ($this->paymentMethod === 'pix') {
                $customer = Customer::withoutGlobalScopes()->find($customerId);
                $result = app(PaymentOrchestrator::class)->processPix($order, $customer, $company);
                $this->pixCopyPaste = $result['copy_paste'] ?? null;
                $this->pixQrCode = $result['qr_code'] ?? null;
                $this->pixOrderId = $order->id;
                DB::commit();
                $this->step = 'pix';
                $this->lastOrderNumber = $order->order_number;
                $this->lastOrderTotal = (float) $order->total;
                $this->lastOrderId = $order->id;

                return;
            }

            DB::commit();
            $this->lastOrderTotal = (float) $order->total;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->addError('order', $e->getMessage());

            return;
        }

        $this->lastOrderNumber = $order->order_number;
        $this->lastOrderId = $order->id;
        $this->step = 'success';
    }

    public function checkPixStatus(): void
    {
        if (! $this->pixOrderId) {
            return;
        }

        $payment = \App\Models\Payment::where('order_id', $this->pixOrderId)
            ->where('status', 'paid')
            ->first();

        if ($payment) {
            $this->step = 'success';
        }
    }

    public function cancelLastOrder(): void
    {
        $company = app('current.company');

        $order = Order::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('order_type', 'pdv')
            ->find($this->lastOrderId);

        if (! $order) {
            return;
        }

        if ($order->created_at->diffInMinutes(now()) > 5) {
            $this->addError('cancel', 'Cancelamento disponível apenas nos 5 minutos após criação.');

            return;
        }

        try {
            app(OrderCancellationPolicy::class)->authorizeAdminCancel($order);
        } catch (\RuntimeException $e) {
            $this->addError('cancel', $e->getMessage());

            return;
        }

        $order->update(['status' => 'cancelled']);
        app(StockService::class)->restoreForOrder($order);

        Log::channel('orders')->info('Pedido PDV cancelado pelo operador', [
            'order_id' => $order->id,
            'user_id' => auth()->id(),
        ]);

        $this->confirmingCancelOrder = false;
        $this->resetTerminal();
    }

    public function resetTerminal(): void
    {
        $this->cart = [];
        $this->customerQuery = '';
        $this->customerName = '';
        $this->customerId = null;
        $this->customerFound = false;
        $this->customerResults = [];
        $this->lastOrderNumber = null;
        $this->lastOrderTotal = null;
        $this->lastOrderId = null;
        $this->confirmingCancelOrder = false;
        $this->changeAmount = 0.0;
        $this->notes = '';
        $this->appliedCoupon = null;
        $this->couponDiscount = 0.0;
        $this->couponError = null;
        $this->couponInput = '';
        $this->step = 'catalog';
        $this->resetPaymentState();
    }

    // ── Caixa ─────────────────────────────────────────────────────────────────

    public function openCashSession(): void
    {
        $amount = (float) str_replace(',', '.', $this->openingAmountInput ?: '0');
        $company = app('current.company');

        $session = PdvCashSession::create([
            'company_id' => $company->id,
            'branch_id' => $this->selectedBranchId,
            'user_id' => auth()->id(),
            'opening_amount' => $amount,
        ]);

        $this->cashSessionId = $session->id;
        $this->openingAmountInput = '';
        $this->step = 'catalog';
    }

    public function proceedToCloseCash(): void
    {
        $this->closingAmountInput = '';
        $this->step = 'close_cash';
    }

    public function closeCashSession(): void
    {
        if (! $this->cashSessionId) {
            $this->step = 'catalog';

            return;
        }

        $session = PdvCashSession::find($this->cashSessionId);

        if (! $session || $session->closed_at) {
            $this->cashSessionId = null;
            $this->step = 'open_cash';

            return;
        }

        $expected = $this->cashSessionExpected($session);
        $closing = (float) str_replace(',', '.', $this->closingAmountInput ?: '0');

        $session->update([
            'closing_amount' => $closing,
            'expected_amount' => $expected,
            'closed_at' => now(),
        ]);

        $this->cashSessionId = null;
        $this->closingAmountInput = '';
        $this->cart = [];
        $this->step = 'open_cash';
    }

    public function cancelCloseCash(): void
    {
        $this->step = 'catalog';
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    #[Computed]
    public function cartTotal(): float
    {
        return round(array_sum(array_map(function ($item) {
            $optionsExtra = 0.0;
            foreach ($item['options'] ?? [] as $group) {
                foreach ($group['selections'] ?? [] as $sel) {
                    $optionsExtra += ($sel['qty'] ?? 0) * ($sel['additional_price'] ?? 0);
                }
            }

            return $item['qty'] * ((float) $item['price'] + $optionsExtra);
        }, $this->cart)), 2);
    }

    #[Computed]
    public function cartTotalAfterDiscount(): float
    {
        return max(0.0, round($this->cartTotal - $this->couponDiscount, 2));
    }

    #[Computed]
    public function cartCount(): int
    {
        return array_sum(array_column($this->cart, 'qty'));
    }

    #[Computed]
    public function branches(): \Illuminate\Database\Eloquent\Collection
    {
        $company = app()->bound('current.company') ? app('current.company') : null;

        if (! $company) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return Branch::where('company_id', $company->id)
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function categories(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->selectedBranchId) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return ProductCategory::withoutGlobalScopes()
            ->whereHas('products', fn ($q) => $q
                ->where('active', true)
                ->whereHas('branches', fn ($bq) => $bq
                    ->where('branches.id', $this->selectedBranchId)
                    ->where('branch_product.available', true)
                )
            )
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function products(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->selectedBranchId) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        $query = Product::withoutGlobalScopes()
            ->whereHas('branches', fn ($q) => $q
                ->where('branches.id', $this->selectedBranchId)
                ->where('branch_product.available', true)
            )
            ->where('active', true)
            ->with([
                'optionGroups.options' => fn ($q) => $q->where('active', true),
                'optionGroups.inactiveOptions',
            ]);

        if ($this->activeCategoryId) {
            $query->where('product_category_id', $this->activeCategoryId);
        }

        if (filled($this->search)) {
            $query->where('name', 'like', '%'.$this->search.'%');
        }

        return $query->orderBy('sort_order')->orderBy('name')->get();
    }

    #[Computed]
    public function productStocks(): array
    {
        if (! $this->selectedBranchId) {
            return [];
        }

        return DB::table('branch_product')
            ->where('branch_id', $this->selectedBranchId)
            ->where('track_stock', true)
            ->pluck('quantity', 'product_id')
            ->toArray();
    }

    #[Computed]
    public function cashSession(): ?PdvCashSession
    {
        if (! $this->cashSessionId) {
            return null;
        }

        return PdvCashSession::find($this->cashSessionId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function buildProductDataForSidebar(Product $product): ?array
    {
        if ($product->optionGroups->isEmpty()) {
            return null;
        }

        $existingSels = [];
        foreach ($this->cart[(string) $product->id]['options'] ?? [] as $gId => $gData) {
            foreach ($gData['selections'] ?? [] as $oId => $sel) {
                $existingSels[(int) $gId][(int) $oId] = (int) ($sel['qty'] ?? 0);
            }
        }

        $allFixed = $product->optionGroups->every(fn ($g) => $g->fixed);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'price' => (float) $product->effective_price,
            'allFixed' => $allFixed,
            'groups' => $product->optionGroups->values()->map(function ($g, $gi) use ($product, $existingSels) {
                $isVariantPriceGroup = $product->is_variant && $gi === 0;
                $resolvePrice = fn ($o) => ($isVariantPriceGroup || ! $g->fixed) ? (float) $o->additional_price : 0.0;

                return [
                    'id' => $g->id,
                    'name' => $g->name,
                    'image_url' => $g->image_url,
                    'total_qty' => $g->total_qty,
                    'fixed' => (bool) $g->fixed,
                    'options' => [
                        ...$g->options->map(fn ($o) => [
                            'id' => $o->id,
                            'name' => $o->name,
                            'image_url' => $o->image_url,
                            'description' => $o->description,
                            'additional_price' => $resolvePrice($o),
                            'paused' => false,
                            'prefilledQty' => $g->fixed
                                ? (int) $o->default_qty
                                : ($existingSels[$g->id][$o->id] ?? 0),
                        ])->toArray(),
                        ...$g->inactiveOptions->map(fn ($o) => [
                            'id' => $o->id,
                            'name' => $o->name,
                            'image_url' => $o->image_url,
                            'description' => $o->description,
                            'additional_price' => $resolvePrice($o),
                            'paused' => true,
                            'prefilledQty' => 0,
                        ])->toArray(),
                    ],
                ];
            })->toArray(),
        ];
    }

    private function checkStockBeforeAdd(int $productId, string $productName): bool
    {
        $stocks = $this->productStocks;

        if (! array_key_exists($productId, $stocks)) {
            return true;
        }

        $available = (int) $stocks[$productId];
        $inCart = 0;

        foreach ($this->cart as $key => $item) {
            $pid = (int) ($item['product_id'] ?? explode('_', (string) $key)[0]);
            if ($pid === $productId) {
                $inCart += (int) ($item['qty'] ?? 0);
            }
        }

        if ($inCart >= $available) {
            $this->addError('stock', "Estoque insuficiente para \"{$productName}\": disponível {$available}.");

            return false;
        }

        return true;
    }

    private function syncCashSession(): void
    {
        if (! $this->selectedBranchId) {
            return;
        }

        $company = app()->bound('current.company') ? app('current.company') : null;

        if (! $company) {
            return;
        }

        $session = PdvCashSession::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('branch_id', $this->selectedBranchId)
            ->whereNull('closed_at')
            ->latest()
            ->first();

        if ($session) {
            $this->cashSessionId = $session->id;
            if ($this->step === 'open_cash') {
                $this->step = 'catalog';
            }
        } else {
            $this->cashSessionId = null;
            $this->step = 'open_cash';
        }
    }

    public function cashSessionExpected(PdvCashSession $session): float
    {
        $cashSales = DB::table('orders')
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->where('orders.branch_id', $session->branch_id)
            ->where('orders.payment_method', 'cash')
            ->where('payments.status', 'paid')
            ->where('orders.created_at', '>=', $session->created_at)
            ->sum('payments.amount');

        return round($session->opening_amount + (float) $cashSales, 2);
    }

    private function resolveCustomerId(\App\Models\Company $company): int
    {
        if ($this->customerId) {
            return $this->customerId;
        }

        // Cliente balcão anônimo reusado por empresa
        $guest = Customer::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('phone', 'pdv-guest')
            ->first();

        if (! $guest) {
            $guest = Customer::forceCreate([
                'company_id' => $company->id,
                'phone' => 'pdv-guest',
                'name' => 'Cliente Balcão',
            ]);
        }

        return $guest->id;
    }

    private function resetPaymentState(): void
    {
        $this->paymentMethod = 'cash';
        $this->cashReceivedInput = '';
        $this->pixCopyPaste = null;
        $this->pixQrCode = null;
        $this->pixOrderId = null;
        $this->appliedCoupon = null;
        $this->couponDiscount = 0.0;
        $this->couponError = null;
        $this->couponInput = '';
    }

    public function render()
    {
        return view('livewire.admin.pdv.terminal')
            ->layout('layouts.app.pdv');
    }
}
