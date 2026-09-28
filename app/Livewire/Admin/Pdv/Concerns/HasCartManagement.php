<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\Product;
use Livewire\Attributes\Computed;

trait HasCartManagement
{
    public function addProduct(int $productId): void
    {
        if (! $this->selectedBranchId) {
            return;
        }

        $this->assertSelectedBranchBelongsToCurrentCompany();

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

        $cartKey = (string) $productId;

        if (isset($this->cart[$cartKey])) {
            $this->cart[$cartKey]['qty']++;
        } else {
            $this->cart[$cartKey] = [
                'product_id' => $productId,
                'name' => $product->name,
                'price' => (float) $product->effective_price,
                'image_url' => $product->image_url,
                'qty' => 1,
            ];
        }

        $this->cart = $this->cart;

        $this->dispatch('product-added-to-cart', name: $product->name);
    }

    public function addProductWithOptions(int $productId, array $optionSelections): void
    {
        if (! $this->selectedBranchId) {
            return;
        }

        $this->assertSelectedBranchBelongsToCurrentCompany();

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
                    'image_url' => $product->image_url,
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
                'image_url' => $product->image_url,
                'qty' => 1,
                'options' => $optionSelections,
            ];
        }

        $this->cart = $this->cart;

        $this->dispatch('product-added-to-cart', name: $product->name);
    }

    /** Teto por linha — só barra digitação absurda (ex.: 99999999); o estoque real é conferido logo abaixo e no commit. */
    private const MAX_CART_LINE_QTY = 999;

    public function updateCartQty(string $cartKey, int $qty): void
    {
        // Chave que não está no carrinho (payload forjado ou linha já removida) criaria uma
        // linha fantasma sem product_id, que derrubava buildOrderCart() na confirmação.
        if (! isset($this->cart[$cartKey])) {
            return;
        }

        if ($qty <= 0) {
            $this->removeItem($cartKey);

            return;
        }

        $qty = min($qty, self::MAX_CART_LINE_QTY);

        $productId = (int) ($this->cart[$cartKey]['product_id'] ?? 0);
        $stocks = $this->productStocks;

        if ($productId && array_key_exists($productId, $stocks)) {
            $otherLines = 0;

            foreach ($this->cart as $key => $item) {
                if ((string) $key !== $cartKey && (int) ($item['product_id'] ?? 0) === $productId) {
                    $otherLines += (int) ($item['qty'] ?? 0);
                }
            }

            $maxForLine = max(0, (int) $stocks[$productId] - $otherLines);

            if ($qty > $maxForLine) {
                $this->addError('stock', "Estoque insuficiente para \"{$this->cart[$cartKey]['name']}\": disponível {$stocks[$productId]}.");
                $qty = $maxForLine;

                if ($qty <= 0) {
                    $this->removeItem($cartKey);

                    return;
                }
            }
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

    #[Computed]
    public function cartCount(): int
    {
        return array_sum(array_column($this->cart, 'qty'));
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
}
