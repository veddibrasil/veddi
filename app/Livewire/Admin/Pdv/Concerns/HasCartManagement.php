<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\BranchServiceCharge;
use App\Models\Product;
use Livewire\Attributes\Computed;

trait HasCartManagement
{
    // ── Código de barras ─────────────────────────────────────────────────────

    public function lookupByBarcode(): void
    {
        $barcode = trim($this->barcodeInput);
        $this->barcodeInput = '';
        $this->dispatch('pdv-barcode-processed');

        if (blank($barcode) || ! $this->selectedBranchId) {
            return;
        }

        $product = Product::withoutGlobalScopes()
            ->whereHas('branches', fn ($q) => $q
                ->where('branches.id', $this->selectedBranchId)
                ->where('branch_product.available', true)
            )
            ->where('active', true)
            ->where('available_in_pdv', true)
            ->where('barcode', $barcode)
            ->first();

        if (! $product) {
            $this->addError('barcode', "Código '{$barcode}' não encontrado.");

            return;
        }

        $this->resetValidation('barcode');
        $this->addProduct($product->id);
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
            ->where('available_in_pdv', true)
            ->find($productId);

        if (! $product) {
            return;
        }

        if (! $this->checkStockBeforeAdd($productId, $product->name)) {
            return;
        }

        // Modo mesa: o clique já lança o item direto na comanda, sem passar por carrinho pendente.
        if ($this->orderMode === 'mesa') {
            $this->sendProductToComanda($product);

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
            ->where('available_in_pdv', true)
            ->find($productId);

        if (! $product) {
            return;
        }

        if (! $this->checkStockBeforeAdd($productId, $product->name)) {
            return;
        }

        // Modo mesa: o clique já lança o item direto na comanda, sem passar por carrinho pendente.
        if ($this->orderMode === 'mesa') {
            $this->sendProductToComanda($product, $optionSelections);

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
        $this->showSessionHistory = false;
        $this->resetPaymentState();
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    #[Computed]
    public function cartTotal(): float
    {
        if ($this->closingTabOrderId) {
            return (float) ($this->closingTabOrder?->subtotal ?? 0.0);
        }

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
        if ($this->closingTabOrderId) {
            return max(0.0, round($this->cartTotal + $this->serviceFeeAmount + $this->couvertFeeAmount - $this->manualDiscountAmount, 2));
        }

        return max(0.0, round($this->cartTotal + $this->deliveryFeeAmount + $this->serviceFeeAmount + $this->couvertFeeAmount - $this->manualDiscountAmount, 2));
    }

    #[Computed]
    public function branchServiceCharge(): ?BranchServiceCharge
    {
        if (! $this->selectedBranchId) {
            return null;
        }

        return BranchServiceCharge::where('branch_id', $this->selectedBranchId)->first();
    }

    #[Computed]
    public function rawServiceFeeAmount(): float
    {
        if ($this->closingTabOrderId) {
            return (float) ($this->closingTabOrder?->service_fee ?? 0.0);
        }

        if ($this->deliveryType === 'entrega') {
            return 0.0;
        }

        return $this->branchServiceCharge?->calculateServiceFee($this->cartTotal) ?? 0.0;
    }

    #[Computed]
    public function rawCouvertFeeAmount(): float
    {
        if ($this->closingTabOrderId) {
            return (float) ($this->closingTabOrder?->couvert_fee ?? 0.0);
        }

        if ($this->deliveryType === 'entrega') {
            return 0.0;
        }

        return $this->branchServiceCharge?->calculateCouvert($this->cartTotal) ?? 0.0;
    }

    #[Computed]
    public function serviceFeeAmount(): float
    {
        return $this->serviceFeeWaived ? 0.0 : $this->rawServiceFeeAmount;
    }

    #[Computed]
    public function couvertFeeAmount(): float
    {
        return $this->couvertFeeWaived ? 0.0 : $this->rawCouvertFeeAmount;
    }

    #[Computed]
    public function cartCount(): int
    {
        return array_sum(array_column($this->cart, 'qty'));
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

    private function buildOrderCart(): array
    {
        $orderCart = [];
        foreach ($this->cart as $cartKey => $item) {
            $orderCart[$cartKey] = [
                'product_id' => $item['product_id'],
                'qty' => $item['qty'],
                'options' => $item['options'] ?? [],
            ];
        }

        return $orderCart;
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
}
