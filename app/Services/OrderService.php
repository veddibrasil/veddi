<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Jobs\RefundPayment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
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
        float $deliveryFee = 0.0
    ): Order {
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

        return DB::transaction(function () use ($customerId, $branchId, $cart, $notes, $paymentMethod, $orderType, $status, $deliveryFee, $products) {
            $subtotal = 0.0;
            foreach ($cart as $productId => $item) {
                $subtotal += (float) $products[$productId]->price * $item['qty'];
            }

            $order = Order::create([
                'customer_id'    => $customerId,
                'branch_id'      => $branchId,
                'subtotal'       => $subtotal,
                'delivery_fee'   => $deliveryFee,
                'total'          => $subtotal + $deliveryFee,
                'status'         => $status,
                'notes'          => $notes,
                'payment_method' => strtolower($paymentMethod),
                'order_type'     => $orderType,
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

            app(StockService::class)->deductForOrder($order);

            Log::channel('orders')->info('Pedido criado', [
                'order_id'       => $order->id,
                'order_number'   => $order->order_number,
                'customer_id'    => $customerId,
                'branch_id'      => $branchId,
                'subtotal'       => $order->subtotal,
                'delivery_fee'   => $order->delivery_fee,
                'total'          => $order->total,
                'payment_method' => $paymentMethod,
                'order_type'     => $orderType,
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

        $order->loadMissing('payment');
        if ($order->payment?->status === 'paid') {
            RefundPayment::dispatch($order);
        }

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

        $lines[] = "Total: R$ " . number_format($order->total, 2, ',', '.');

        return implode("\n", $lines);
    }
}
