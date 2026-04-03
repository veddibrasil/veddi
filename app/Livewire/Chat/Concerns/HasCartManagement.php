<?php

namespace App\Livewire\Chat\Concerns;

trait HasCartManagement
{
    public function addToCart(int $productId, int $quantity = 1): void
    {
        $this->cartError = null;
        $product = \App\Models\Product::findOrFail($productId);

        $cart = $this->cart;
        if (isset($cart[$productId])) {
            $cart[$productId]['qty'] += $quantity;
        } else {
            $cart[$productId] = [
                'qty'   => $quantity,
                'name'  => $product->name,
                'price' => (float) $product->price,
            ];
        }
        $this->cart = $cart;
    }

    public function removeFromCart(int $productId): void
    {
        $cart = $this->cart;
        unset($cart[$productId]);
        $this->cart = $cart;
    }

    public function updateCartQty(int $productId, int $qty): void
    {
        if ($qty <= 0) {
            $this->removeFromCart($productId);
            return;
        }
        $cart                    = $this->cart;
        $cart[$productId]['qty'] = $qty;
        $this->cart              = $cart;
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
