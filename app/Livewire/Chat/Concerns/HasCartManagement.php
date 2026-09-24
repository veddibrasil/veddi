<?php

namespace App\Livewire\Chat\Concerns;

use App\Services\Order\CartOptionPricing;
use App\Services\Order\MenuCache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait HasCartManagement
{
    public function addToCart(int $productId, int $quantity = 1): void
    {
        $this->cartError = null;
        $product = \App\Models\Product::findOrFail($productId);

        if (! $this->productCanBeAdded($product, $quantity)) {
            return;
        }

        if ((float) $product->effective_price <= 0) {
            $this->cartError = "\"{$product->name}\" está sem preço e não pode ser pedido.";

            return;
        }

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

        if (! $this->productCanBeAdded($product, 1)) {
            return;
        }

        // O client já trava isso no modal, mas o payload do Livewire é forjável: valida mínimos/máximos
        // e preço final aqui, com os valores do banco, antes de o item entrar no carrinho.
        try {
            $pricing = app(CartOptionPricing::class);
            $resolved = $pricing->resolve($product, ['options' => $optionSelections]);
            $pricing->assertRequiredGroupsSelected($product, $resolved['options']);
        } catch (RuntimeException $e) {
            $this->cartError = $e->getMessage();

            return;
        }

        if ((float) $product->effective_price + (float) $resolved['extra'] <= 0) {
            $this->cartError = "Escolha as opções de \"{$product->name}\" para adicionar ao carrinho.";

            return;
        }

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

    /**
     * O cardápio vem de cache (MenuCache), então disponibilidade/estoque exibidos podem estar defasados.
     * Antes de aceitar o item, confere no banco o mesmo que `OrderService::resolveProducts` confere ao
     * criar o pedido — falhar aqui evita o cliente montar o carrinho inteiro e só descobrir no fim.
     */
    private function productCanBeAdded(\App\Models\Product $product, int $quantity): bool
    {
        if (! $this->selectedBranchId) {
            return true;
        }

        $pivot = DB::table('branch_product')
            ->where('branch_id', $this->selectedBranchId)
            ->where('product_id', $product->id)
            ->first();

        $orderable = $product->active && $product->available_in_delivery && $pivot && $pivot->available;

        if ($orderable && $pivot->track_stock) {
            $inCart = 0;
            foreach ($this->cart as $key => $item) {
                if ((int) ($item['product_id'] ?? $key) === $product->id) {
                    $inCart += (int) ($item['qty'] ?? 0);
                }
            }

            $orderable = $pivot->quantity >= $inCart + $quantity;
        }

        if ($orderable) {
            return true;
        }

        $this->cartError = "\"{$product->name}\" não está mais disponível em quantidade suficiente nesta filial.";
        app(MenuCache::class)->forgetBranch((int) $this->selectedBranchId, (int) $this->companyId);

        return false;
    }
}
