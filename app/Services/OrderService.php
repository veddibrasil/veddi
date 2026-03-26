<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\OrderLimitExceededException;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\CouponService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OrderService
{
    /**
     * Cria um pedido com validação de preços no servidor.
     * Os preços são buscados do banco de dados, ignorando os valores do client-side.
     *
     * @param  array<int, array{qty: int, name: string, price: float}> $cart
     */
    public function createOrder(
        int $customerId,
        int $branchId,
        array $cart,
        string $notes,
        string $paymentMethod,
        string $orderType,
        string $status = 'pending',
        float $deliveryFee = 0.0,
        ?Coupon $coupon = null,
    ): Order {
        $currentCompany = app()->bound('current.company') ? app('current.company') : null;

        if ($currentCompany && ! $currentCompany->isWithinOrderLimit()) {
            throw new OrderLimitExceededException(
                'Limite de 50 pedidos mensais atingido. Faça upgrade para o plano Essencial ou PRO.'
            );
        }

        $productIds = array_keys($cart);

        $products = Product::withoutGlobalScopes()
            ->whereIn('id', $productIds)
            ->whereHas('branches', fn ($q) => $q
                ->where('branches.id', $branchId)
                ->where('branch_product.available', true)
            )
            ->where('active', true)
            ->get()
            ->keyBy('id');

        foreach ($productIds as $productId) {
            if (! $products->has($productId)) {
                throw new RuntimeException("Produto #{$productId} não está disponível nesta filial.");
            }
        }

        return DB::transaction(function () use ($customerId, $branchId, $cart, $notes, $paymentMethod, $orderType, $status, $deliveryFee, $products, $coupon) {
            $subtotal = 0.0;
            foreach ($cart as $productId => $item) {
                $subtotal += (float) $products[$productId]->price * $item['qty'];
            }

            $discount = 0.0;
            if ($coupon) {
                $discount = app(CouponService::class)->calculateDiscount($coupon, $cart, $subtotal, $deliveryFee);
                // Frete grátis: zera o delivery_fee no cálculo do total
                if ($coupon->type === 'free_delivery') {
                    $deliveryFee = 0.0;
                    $discount = 0.0; // desconto já está embutido no frete zerado
                }
            }

            $total = max(0, $subtotal + $deliveryFee - $discount);

            // Calculate platform fee based on company plan (fee applies only to products, not shipping)
            $fee      = 0.0;
            $netValue = $total;
            if ($currentCompany) {
                $feeBase  = max(0.0, $subtotal - $discount);
                $fees     = app(FeeCalculator::class)->calculate($currentCompany, $feeBase, $total);
                $fee      = $fees['fee'];
                $netValue = $fees['net_value'];
            }

            $order = Order::create([
                'customer_id'    => $customerId,
                'branch_id'      => $branchId,
                'subtotal'       => $subtotal,
                'delivery_fee'   => $deliveryFee,
                'discount'       => $discount,
                'total'          => $total,
                'fee'            => $fee,
                'net_value'      => $netValue,
                'status'         => $status,
                'notes'          => $notes,
                'payment_method' => strtolower($paymentMethod),
                'order_type'     => $orderType,
                'coupon_id'      => $coupon?->id,
            ]);

            foreach ($cart as $productId => $item) {
                $product = $products[$productId];
                $unitPrice = (float) $product->price;

                OrderItem::create([
                    'order_id'     => $order->id,
                    'product_id'   => $productId,
                    'product_name' => $product->name,
                    'unit_price'   => $unitPrice,
                    'quantity'     => $item['qty'],
                    'subtotal'     => $unitPrice * $item['qty'],
                ]);
            }

            // Produto grátis: adicionar item extra com preço zero
            if ($coupon && $coupon->type === 'free_product' && $coupon->freeProduct) {
                $freeProduct = $coupon->freeProduct;
                OrderItem::create([
                    'order_id'     => $order->id,
                    'product_id'   => $freeProduct->id,
                    'product_name' => $freeProduct->name . ' (Brinde)',
                    'unit_price'   => 0.0,
                    'quantity'     => 1,
                    'subtotal'     => 0.0,
                ]);
            }

            app(StockService::class)->deductForOrder($order);

            if ($coupon) {
                app(CouponService::class)->recordUsage($coupon, $order, $customerId, $discount);
            }

            Log::channel('orders')->info('Pedido criado', [
                'order_id'       => $order->id,
                'order_number'   => $order->order_number,
                'customer_id'    => $customerId,
                'branch_id'      => $branchId,
                'subtotal'       => $order->subtotal,
                'delivery_fee'   => $order->delivery_fee,
                'discount'       => $order->discount,
                'total'          => $order->total,
                'fee'            => $order->fee,
                'net_value'      => $order->net_value,
                'payment_method' => $paymentMethod,
                'order_type'     => $orderType,
                'coupon_code'    => $coupon?->code,
            ]);

            return $order;
        });
    }

    /**
     * Cancela um pedido pelo cliente.
     */
    public function cancelOrder(Order $order, int $customerId): void
    {
        if (! in_array($order->status, ['paid', 'preparing'])) {
            throw new RuntimeException('Pedido não pode ser cancelado no momento.');
        }

        $order->update(['status' => 'cancelled']);

        app(StockService::class)->restoreForOrder($order);

        Log::channel('orders')->info('Pedido cancelado pelo cliente', [
            'order_id'    => $order->id,
            'customer_id' => $customerId,
        ]);
    }

    /**
     * Monta o resumo textual do pedido usando os valores reais do banco de dados.
     */
    public function buildOrderSummaryFromOrder(Order $order): string
    {
        $order->loadMissing('items');

        $lines = ["Pedido {$order->order_number} recebido! Aqui está o resumo:"];

        foreach ($order->items as $item) {
            $lines[] = "• {$item->quantity}x {$item->product_name} — R$ " . number_format($item->subtotal, 2, ',', '.');
        }

        if ($order->order_type === 'delivery') {
            $lines[] = "\nSubtotal: R$ " . number_format($order->subtotal, 2, ',', '.');
            if ($order->delivery_fee > 0) {
                $lines[] = "Frete: R$ " . number_format($order->delivery_fee, 2, ',', '.');
            } else {
                $lines[] = "Frete: Grátis";
            }
        }

        if ($order->discount > 0) {
            $lines[] = "Desconto: -R$ " . number_format($order->discount, 2, ',', '.');
        }

        $lines[] = "Total: R$ " . number_format($order->total, 2, ',', '.');

        return implode("\n", $lines);
    }
}
