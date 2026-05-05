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
                'price' => (float) $product->effective_price,
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
                    'price' => (float) $product->effective_price,
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
                'price' => (float) $product->effective_price,
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

    /**
     * Decrementa em 1 a quantidade total de um produto no carrinho.
     *
     * Para produtos "simples" e para produtos com todos os grupos fixos, o carrinho usa a key do próprio produto.
     * Para produtos com grupos variáveis, existem múltiplas entradas (ex.: "{productId}_1", "{productId}_2"...),
     * então removemos/decrementamos a entrada mais recentemente adicionada.
     */
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
            $itemProductId = (int) ($item['product_id'] ?? (int) $key);

            if ($itemProductId !== $productId) {
                continue;
            }

            $this->updateCartQty($key, (int) ($item['qty'] ?? 0) - 1);

            return;
        }
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
