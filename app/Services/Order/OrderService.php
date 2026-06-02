<?php

namespace App\Services\Order;

use App\Contracts\OrderServiceInterface;
use App\Contracts\RefundServiceInterface;
use App\Jobs\NotifyScheduledOrderJob;
use App\Models\CompanyNotification;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OrderService implements OrderServiceInterface
{
    /**
     * Cria um pedido com validação de preços no servidor.
     * Os preços são buscados do banco de dados, ignorando os valores do client-side.
     *
     * @param  array<int, array{qty: int, name: string, price: float}>  $cart
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
        ?Carbon $scheduledAt = null,
        float $extraDiscount = 0.0,
    ): Order {
        $currentCompany = app()->bound('current.company') ? app('current.company') : null;

        // Extrai product_ids únicos do carrinho (suporta chaves simples "42" e compostas "42_1")
        $productIds = array_unique(array_values(array_map(
            fn ($item, $key) => (int) ($item['product_id'] ?? explode('_', (string) $key)[0]),
            array_values($cart),
            array_keys($cart)
        )));

        $products = Product::withoutGlobalScopes()
            ->whereIn('id', $productIds)
            ->whereHas('branches', fn ($q) => $q
                ->where('branches.id', $branchId)
                ->where('branch_product.available', true)
            )
            ->where('active', true)
            ->with('optionGroups')
            ->get()
            ->keyBy('id');

        foreach ($productIds as $productId) {
            if (! $products->has($productId)) {
                throw new RuntimeException("Produto #{$productId} não está disponível nesta filial.");
            }
        }

        $customer = \App\Models\Customer::withoutGlobalScopes()->find($customerId);

        $order = DB::transaction(function () use ($customerId, $branchId, $cart, $notes, $paymentMethod, $orderType, $status, $deliveryFee, $products, $coupon, $currentCompany, $scheduledAt, $customer, $extraDiscount) {
            $subtotal = 0.0;
            $optionPricing = app(CartOptionPricing::class);
            foreach ($cart as $cartKey => $item) {
                $pid = (int) ($item['product_id'] ?? explode('_', (string) $cartKey)[0]);
                $product = $products[$pid];
                $resolved = $optionPricing->resolve($product, is_array($item) ? $item : []);
                $optionsExtra = (float) $resolved['extra'];
                $subtotal += ((float) $product->effective_price + $optionsExtra) * (int) ($item['qty'] ?? 0);
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

            $safeExtraDiscount = max(0.0, (float) $extraDiscount);
            $total = max(0, $subtotal + $deliveryFee - $discount - $safeExtraDiscount);

            // Calculate platform fee based on company plan (fee applies only to products, not shipping)
            $fee = 0.0;
            $netValue = $total;
            if ($currentCompany) {
                $feeBase = max(0.0, $subtotal - $discount - $safeExtraDiscount);
                $fees = app(FeeCalculator::class)->calculate($currentCompany, $feeBase, $total);
                $fee = $fees['fee'];
                $netValue = $fees['net_value'];
            }

            $order = Order::create([
                'customer_id' => $customerId,
                'branch_id' => $branchId,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'discount' => $discount,
                'manual_discount' => $safeExtraDiscount,
                'total' => $total,
                'fee' => $fee,
                'net_value' => $netValue,
                'status' => $status,
                'notes' => $notes,
                'scheduled_at' => $scheduledAt,
                'payment_method' => strtolower($paymentMethod),
                'order_type' => $orderType,
                'coupon_id' => $coupon?->id,
            ]);

            if ($orderType === 'delivery' && $customer) {
                $order->delivery_address = $customer->address;
                $order->delivery_number = $customer->number;
                $order->delivery_neighborhood = $customer->neighborhood;
                $order->delivery_city = $customer->city;
                $order->delivery_cep = $customer->cep;
                $order->delivery_complement = $customer->complement;
                $order->save();
            }

            foreach ($cart as $cartKey => $item) {
                $pid = (int) ($item['product_id'] ?? explode('_', (string) $cartKey)[0]);
                $product = $products[$pid];
                $resolved = $optionPricing->resolve($product, is_array($item) ? $item : []);
                $optionsExtra = (float) $resolved['extra'];
                $unitPrice = (float) $product->effective_price + $optionsExtra;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $pid,
                    'product_name' => $product->name,
                    'unit_price' => $unitPrice,
                    'quantity' => (int) ($item['qty'] ?? 0),
                    'subtotal' => $unitPrice * (int) ($item['qty'] ?? 0),
                    'options' => $resolved['options'] !== [] ? $resolved['options'] : null,
                ]);
            }

            // Produto grátis: adicionar item extra com preço zero
            if ($coupon && $coupon->type === 'free_product' && $coupon->freeProduct) {
                $freeProduct = $coupon->freeProduct;
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $freeProduct->id,
                    'product_name' => $freeProduct->name.' (Brinde)',
                    'unit_price' => 0.0,
                    'quantity' => 1,
                    'subtotal' => 0.0,
                ]);
            }

            app(StockService::class)->deductForOrder($order);

            if ($coupon) {
                app(CouponService::class)->recordUsage($coupon, $order, $customerId, $discount);
            }

            Log::channel('orders')->info('Pedido criado', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'customer_id' => $customerId,
                'branch_id' => $branchId,
                'subtotal' => $order->subtotal,
                'delivery_fee' => $order->delivery_fee,
                'discount' => $order->discount,
                'total' => $order->total,
                'fee' => $order->fee,
                'net_value' => $order->net_value,
                'payment_method' => $paymentMethod,
                'order_type' => $orderType,
                'coupon_code' => $coupon?->code,
                'scheduled_at' => $scheduledAt?->toIso8601String(),
            ]);

            return $order;
        });

        if ($scheduledAt && $scheduledAt->isFuture()) {
            $notifyAt = $scheduledAt->copy()->subMinutes(15);
            NotifyScheduledOrderJob::dispatch($order->id)
                ->delay($notifyAt->isFuture() ? $notifyAt : $scheduledAt);
        }

        if ($currentCompany && $currentCompany->isFree() && ! $currentCompany->isWithinOrderLimit()) {
            CompanyNotification::create([
                'company_id' => $currentCompany->id,
                'type' => 'plan_limit',
                'title' => 'Limite de pedidos do plano gratuito ultrapassado',
                'subtitle' => 'Você ultrapassou 50 pedidos este mês. A taxa da plataforma foi ajustada de 1% para 3%. Considere fazer upgrade para o plano Essencial ou PRO.',
            ]);
        }

        return $order;
    }

    /**
     * Cancela um pedido pelo cliente.
     */
    public function cancelOrder(Order $order, int $customerId): void
    {
        $policy = app(OrderCancellationPolicy::class);
        $policy->authorizeCustomerCancel($order);

        $order->update(['status' => 'cancelled']);

        app(StockService::class)->restoreForOrder($order);

        Log::channel('orders')->info('Pedido cancelado pelo cliente', [
            'order_id' => $order->id,
            'customer_id' => $customerId,
        ]);

        if ($policy->requiresRefund($order)) {
            $payment = $order->payment;
            app(RefundServiceInterface::class)->initiateRefund(
                $order,
                $payment,
                (float) $payment->amount,
                'customer',
                $customerId,
                'customer_request',
            );
        }
    }

    /**
     * Monta o resumo textual do pedido usando os valores reais do banco de dados.
     */
    public function buildOrderSummaryFromOrder(Order $order): string
    {
        $order->loadMissing('items');

        $lines = ["Pedido {$order->order_number} recebido! Aqui está o resumo:"];

        foreach ($order->items as $item) {
            $lines[] = "• {$item->quantity}x {$item->product_name} — R$ ".number_format($item->subtotal, 2, ',', '.');
        }

        if ($order->order_type === 'delivery') {
            $lines[] = "\nSubtotal: R$ ".number_format($order->subtotal, 2, ',', '.');
            if ($order->delivery_fee > 0) {
                $lines[] = 'Frete: R$ '.number_format($order->delivery_fee, 2, ',', '.');
            } else {
                $lines[] = 'Frete: Grátis';
            }
        }

        if ($order->discount > 0) {
            $lines[] = 'Desconto: -R$ '.number_format($order->discount, 2, ',', '.');
        }

        $lines[] = 'Total: R$ '.number_format($order->total, 2, ',', '.');

        return implode("\n", $lines);
    }
}
