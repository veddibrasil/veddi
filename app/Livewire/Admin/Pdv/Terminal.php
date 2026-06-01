<?php

namespace App\Livewire\Admin\Pdv;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Customer\CustomerService;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Terminal extends Component
{
    // ── Estado da interface ──────────────────────────────────────────────────
    public string $step = 'catalog'; // catalog | payment | success

    // ── Filial ───────────────────────────────────────────────────────────────
    public ?int $selectedBranchId = null;

    // ── Catálogo ─────────────────────────────────────────────────────────────
    public string $search = '';

    public ?int $activeCategoryId = null;

    // ── Carrinho: [cartKey => [product_id, name, price, qty, options?]] ──────
    public array $cart = [];

    // ── Cliente (opcional) ───────────────────────────────────────────────────
    public string $customerPhone = '';

    public string $customerName = '';

    public ?int $customerId = null;

    public bool $customerFound = false;

    // ── Pagamento ─────────────────────────────────────────────────────────────
    public string $paymentMethod = 'cash';

    public string $cashReceivedInput = '';

    // ── PIX ───────────────────────────────────────────────────────────────────
    public ?string $pixCopyPaste = null;

    public ?string $pixQrCode = null;

    // ── Resultado ─────────────────────────────────────────────────────────────
    public float $changeAmount = 0.0;

    public ?string $lastOrderNumber = null;

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
    }

    // ── Filial ───────────────────────────────────────────────────────────────

    public function updatedSelectedBranchId(): void
    {
        $this->cart = [];
        $this->activeCategoryId = null;
        $this->search = '';
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

        if (blank($this->customerPhone)) {
            return;
        }

        $customer = app(CustomerService::class)->findByPhone($this->customerPhone);

        if ($customer) {
            $this->customerId = $customer->id;
            $this->customerName = $customer->name ?? '';
            $this->customerFound = true;
        }
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

        DB::beginTransaction();
        try {
            $order = app(OrderService::class)->createOrder(
                customerId: $customerId,
                branchId: $this->selectedBranchId,
                cart: $orderCart,
                notes: '',
                paymentMethod: $this->paymentMethod,
                orderType: 'pdv',
                status: $this->paymentMethod === 'cash' ? 'paid' : 'awaiting_payment',
            );

            if ($this->paymentMethod === 'cash') {
                $cashReceived = (float) str_replace(',', '.', $this->cashReceivedInput ?: $order->total);
                $order->cash_received = $cashReceived;
                $order->cash_change = max(0.0, round($cashReceived - (float) $order->total, 2));
                $order->save();

                $result = app(PaymentOrchestrator::class)->processCash($order);
                $this->changeAmount = $result['change'];
            } elseif ($this->paymentMethod === 'pix') {
                $customer = Customer::withoutGlobalScopes()->find($customerId);
                $result = app(PaymentOrchestrator::class)->processPix($order, $customer, $company);
                $this->pixCopyPaste = $result['copy_paste'] ?? null;
                $this->pixQrCode = $result['qr_code'] ?? null;
                DB::commit();
                $this->step = 'pix';
                $this->lastOrderNumber = $order->order_number;

                return;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->addError('order', $e->getMessage());

            return;
        }

        $this->lastOrderNumber = $order->order_number;
        $this->step = 'success';
    }

    public function resetTerminal(): void
    {
        $this->cart = [];
        $this->customerPhone = '';
        $this->customerName = '';
        $this->customerId = null;
        $this->customerFound = false;
        $this->lastOrderNumber = null;
        $this->changeAmount = 0.0;
        $this->step = 'catalog';
        $this->resetPaymentState();
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
    public function cartCount(): int
    {
        return array_sum(array_column($this->cart, 'qty'));
    }

    #[Computed]
    public function branches(): \Illuminate\Database\Eloquent\Collection
    {
        $company = app()->bound('current.company') ? app('current.company') : null;

        if (! $company) {
            return collect();
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
            return collect();
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
            return collect();
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
    }

    public function render()
    {
        return view('livewire.admin.pdv.terminal')
            ->layout('layouts.app.pdv');
    }
}
