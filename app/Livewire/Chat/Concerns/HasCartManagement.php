<?php

namespace App\Livewire\Chat\Concerns;

trait HasCartManagement
{
    public function addToCart(int $productId, int $quantity = 1): void
    {
        $this->cartError = null;
        $product = \App\Models\Product::findOrFail($productId);

        $cart = $this->cart;
        $key = (string) $productId;
        if (isset($cart[$key])) {
            $cart[$key]['qty'] += $quantity;
        } else {
            $cart[$key] = [
                'product_id' => $productId,
                'qty' => $quantity,
                'name' => $product->name,
                'price' => (float) $product->price,
            ];
        }
        $this->cart = $cart;
    }

    /**
     * Adiciona ao carrinho um produto com opções.
     * Produtos com todos os grupos fixos (ex.: centos) são agrupados em uma única entrada;
     * produtos com grupos variáveis criam entradas separadas por composição.
     */
    public function addToCartWithOptions(int $productId, array $optionSelections): void
    {
        $this->cartError = null;
        $product = \App\Models\Product::with('optionGroups')->findOrFail($productId);
        $allFixed = $product->optionGroups->isNotEmpty()
            && $product->optionGroups->every(fn ($g) => $g->fixed);

        $cart = $this->cart;

        if ($allFixed) {
            $key = (string) $productId;
            if (isset($cart[$key])) {
                $cart[$key]['qty'] += 1;
            } else {
                $cart[$key] = [
                    'product_id' => $productId,
                    'qty' => 1,
                    'name' => $product->name,
                    'price' => (float) $product->price,
                    'options' => $optionSelections,
                ];
            }
        } else {
            $index = 1;
            while (isset($cart["{$productId}_{$index}"])) {
                $index++;
            }
            $cart["{$productId}_{$index}"] = [
                'product_id' => $productId,
                'qty' => 1,
                'name' => $product->name,
                'price' => (float) $product->price,
                'options' => $optionSelections,
            ];
        }

        $this->cart = $cart;
    }

    public function removeFromCart(string $cartKey): void
    {
        $cart = $this->cart;
        unset($cart[$cartKey]);
        $this->cart = $cart;
    }

    public function updateCartQty(string $cartKey, int $qty): void
    {
        if ($qty <= 0) {
            $this->removeFromCart($cartKey);

            return;
        }
        $cart = $this->cart;
        $cart[$cartKey]['qty'] = $qty;
        $this->cart = $cart;
    }

    public function proceedToCheckout(): void
    {
        if (empty($this->cart)) {
            $this->cartError = 'Adicione pelo menos um produto ao carrinho.';

            return;
        }
        $this->cartError = null;
        $this->transitionTo('CART_REVIEW');
    }
}
