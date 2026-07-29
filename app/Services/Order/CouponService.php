<?php

namespace App\Services\Order;

use App\Exceptions\CouponException;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\Product;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\Cache;

class CouponService
{
    /**
     * Valida um código de cupom e retorna o model Coupon.
     *
     * @param  array<int, array{qty: int, name: string, price: float}>  $cart
     *
     * @throws CouponException
     */
    public function validate(string $code, int $customerId, array $cart, float $subtotal): Coupon
    {
        $normalizedCode = strtoupper(trim($code));

        $coupon = Cache::remember(
            "coupon:code:{$normalizedCode}",
            now()->addMinutes(5),
            fn () => Coupon::where('code', $normalizedCode)->where('active', true)->first()
        );

        if (! $coupon) {
            throw new CouponException('Cupom inválido ou inativo.');
        }

        if ($coupon->isExpired()) {
            throw new CouponException('Cupom expirado ou ainda não está vigente.');
        }

        if ($coupon->minimum_order_value !== null && $subtotal < $coupon->minimum_order_value) {
            $min = number_format($coupon->minimum_order_value, 2, ',', '.');
            throw new CouponException("Pedido mínimo de R$ {$min} para usar este cupom.");
        }

        if ($coupon->hasReachedMaxUses()) {
            throw new CouponException('Limite de usos deste cupom foi atingido.');
        }

        if ($coupon->hasReachedMaxUsesForCustomer($customerId)) {
            throw new CouponException('Você já utilizou este cupom o número máximo de vezes.');
        }

        if ($coupon->type === 'free_product' && ! $coupon->free_product_id) {
            throw new CouponException('Cupom de produto grátis inválido.');
        }

        return $coupon;
    }

    /**
     * Relock + revalida o cupom no momento da criação do pedido, dentro da transação.
     * O cupom chega aqui a partir de uma propriedade Livewire pública — não confiar no
     * model já resolvido antes da transação: ele pode estar expirado, sem uso disponível,
     * ou (mais grave) ser um id forjado que nunca passou por validate(). O lockForUpdate
     * também serializa checagens concorrentes de max_uses.
     *
     * @throws CouponException
     */
    public function revalidateForOrder(int $couponId, int $customerId, float $subtotal, ?string $paymentMethod = null): Coupon
    {
        $coupon = Coupon::whereKey($couponId)->lockForUpdate()->first();

        if (! $coupon || ! $coupon->active) {
            throw new CouponException('Cupom inválido ou inativo.');
        }

        if ($coupon->isExpired()) {
            throw new CouponException('Cupom expirado ou ainda não está vigente.');
        }

        if ($paymentMethod !== null && ! $coupon->allowsPaymentMethod($paymentMethod)) {
            throw new CouponException('Este cupom é válido apenas para pagamento via '.$coupon->payment_method.'.');
        }

        if ($coupon->minimum_order_value !== null && $subtotal < $coupon->minimum_order_value) {
            $min = number_format($coupon->minimum_order_value, 2, ',', '.');
            throw new CouponException("Pedido mínimo de R$ {$min} para usar este cupom.");
        }

        if ($coupon->hasReachedMaxUses()) {
            throw new CouponException('Limite de usos deste cupom foi atingido.');
        }

        if ($coupon->hasReachedMaxUsesForCustomer($customerId)) {
            throw new CouponException('Você já utilizou este cupom o número máximo de vezes.');
        }

        if ($coupon->type === 'free_product' && ! $coupon->free_product_id) {
            throw new CouponException('Cupom de produto grátis inválido.');
        }

        return $coupon;
    }

    /**
     * Calcula o valor de desconto a ser aplicado.
     *
     * @param  array<int, array{qty: int, name: string, price: float}>  $cart
     */
    public function calculateDiscount(Coupon $coupon, array $cart, float $subtotal, float $deliveryFee): float
    {
        return match ($coupon->type) {
            'percentage' => $this->calculatePercentageDiscount($coupon, $cart, $subtotal),
            'fixed' => $this->calculateFixedDiscount($coupon, $cart, $subtotal),
            'free_delivery' => $deliveryFee,
            'free_product' => $this->getFreeProductPrice($coupon),
            default => 0.0,
        };
    }

    /**
     * Registra o uso do cupom após a criação do pedido.
     */
    public function recordUsage(Coupon $coupon, Order $order, int $customerId, float $discountApplied): CouponUsage
    {
        return CouponUsage::create([
            'coupon_id' => $coupon->id,
            'order_id' => $order->id,
            'customer_id' => $customerId,
            'company_id' => $order->company_id,
            'discount_applied' => $discountApplied,
        ]);
    }

    private function calculatePercentageDiscount(Coupon $coupon, array $cart, float $subtotal): float
    {
        $base = $this->getScopeBase($coupon, $cart, $subtotal);

        return round($base * ($coupon->discount_value / 100), 2);
    }

    private function calculateFixedDiscount(Coupon $coupon, array $cart, float $subtotal): float
    {
        $base = $this->getScopeBase($coupon, $cart, $subtotal);

        return min($coupon->discount_value, $base);
    }

    /**
     * Retorna a base de cálculo de acordo com o escopo do cupom.
     */
    private function getScopeBase(Coupon $coupon, array $cart, float $subtotal): float
    {
        if ($coupon->scope === 'order' || empty($coupon->scope_ids)) {
            return $subtotal;
        }

        $scopeIds = $coupon->scope_ids;

        // Para escopos por produto ou categoria, busca os produtos do carrinho com dados do DB
        $productIds = array_unique(array_values(array_map(
            fn ($item, $key) => (int) ($item['product_id'] ?? explode('_', (string) $key)[0]),
            array_values($cart),
            array_keys($cart)
        )));
        $sortedIds = $productIds;
        sort($sortedIds);
        $products = Cache::remember(
            'products:scope:'.md5(implode(',', $sortedIds)),
            now()->addMinutes(5),
            fn () => Product::withoutGlobalScope(CompanyScope::class)
                ->whereIn('id', $productIds)
                ->with('optionGroups')
                ->get()
                ->keyBy('id')
        );

        $base = 0.0;
        $optionPricing = app(CartOptionPricing::class);

        foreach ($cart as $cartKey => $item) {
            $pid = (int) ($item['product_id'] ?? explode('_', (string) $cartKey)[0]);
            $product = $products->get($pid);
            if (! $product) {
                continue;
            }

            $match = match ($coupon->scope) {
                'product' => in_array($pid, $scopeIds),
                'category' => in_array($product->product_category_id, $scopeIds),
                default => false,
            };

            if ($match) {
                $resolved = $optionPricing->resolve($product, is_array($item) ? $item : []);
                $optionsExtra = (float) $resolved['extra'];
                $base += ((float) $product->effective_price + $optionsExtra) * (int) ($item['qty'] ?? 0);
            }
        }

        return $base;
    }

    private function getFreeProductPrice(Coupon $coupon): float
    {
        if (! $coupon->free_product_id) {
            return 0.0;
        }

        $product = Product::withoutGlobalScope(CompanyScope::class)->find($coupon->free_product_id);

        return $product ? (float) $product->effective_price : 0.0;
    }
}
