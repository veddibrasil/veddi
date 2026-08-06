<?php

namespace App\Services\Order;

use App\Contracts\OrderServiceInterface;
use App\Contracts\RefundServiceInterface;
use App\Events\OrderItemsUpdated;
use App\Jobs\NotifyScheduledOrderJob;
use App\Models\CompanyNotification;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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
        float $serviceFee = 0.0,
        float $couvertFee = 0.0,
    ): Order {
        $currentCompany = app()->bound('current.company') ? app('current.company') : null;

        $products = $this->resolveProducts($cart, $branchId, $orderType === 'pdv' ? 'available_in_pdv' : 'available_in_delivery');

        $customer = \App\Models\Customer::withoutGlobalScopes()->find($customerId);

        $order = $this->transactionRetryingOnOrderNumberCollision(function () use ($customerId, $branchId, $cart, $notes, $paymentMethod, $orderType, $status, $deliveryFee, $products, $coupon, $currentCompany, $scheduledAt, $customer, $extraDiscount, $serviceFee, $couvertFee) {
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
                // Revalida e trava o cupom aqui dentro, nunca confiar no model resolvido
                // antes da transação (pode vir de um id forjado client-side).
                $coupon = app(CouponService::class)->revalidateForOrder($coupon->id, $customerId, $subtotal, $paymentMethod);
                $discount = app(CouponService::class)->calculateDiscount($coupon, $cart, $subtotal, $deliveryFee);
                // Frete grátis: zera o delivery_fee no cálculo do total
                if ($coupon->type === 'free_delivery') {
                    $deliveryFee = 0.0;
                    $discount = 0.0; // desconto já está embutido no frete zerado
                }
            }

            $safeExtraDiscount = max(0.0, (float) $extraDiscount);
            $safeServiceFee = max(0.0, (float) $serviceFee);
            $safeCouvertFee = max(0.0, (float) $couvertFee);
            $total = max(0, $subtotal + $deliveryFee + $safeServiceFee + $safeCouvertFee - $discount - $safeExtraDiscount);

            // Calculate platform fee based on company plan (fee applies only to products, not shipping)
            $fee = 0.0;
            $netValue = $total;
            if ($currentCompany) {
                $feeBase = max(0.0, $subtotal - $discount - $safeExtraDiscount);
                $fees = app(FeeCalculator::class)->calculate($currentCompany, $feeBase, $total);
                $fee = $fees['fee'];
                $netValue = $fees['net_value'];
            } else {
                Log::channel('orders')->warning('Pedido criado sem current.company vinculada — taxa da plataforma zerada', [
                    'customer_id' => $customerId,
                    'branch_id' => $branchId,
                    'total' => $total,
                ]);
            }

            $order = Order::create([
                'customer_id' => $customerId,
                'branch_id' => $branchId,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'discount' => $discount,
                'manual_discount' => $safeExtraDiscount,
                'service_fee' => $safeServiceFee,
                'couvert_fee' => $safeCouvertFee,
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
     * generateOrderNumber() já usa lockForUpdate() pra serializar o incremento do
     * order_sequence, mas dois pedidos batendo em terminais/abas diferentes ao mesmo
     * tempo ainda podem colidir na constraint única (ex.: o segundo lê o sequence
     * antes do primeiro commitar). Em vez de estourar erro de SQL pro caixa, tenta de
     * novo — o retry relê o sequence já atualizado e gera um número novo.
     */
    private function transactionRetryingOnOrderNumberCollision(\Closure $callback, int $maxAttempts = 3): Order
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return DB::transaction($callback);
            } catch (\Illuminate\Database\QueryException $e) {
                $isOrderNumberCollision = str_contains($e->getMessage(), 'orders_company_id_order_number_unique');

                if (! $isOrderNumberCollision || $attempt === $maxAttempts) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Não foi possível gerar um número de pedido único.');
    }

    /**
     * Lança um único produto direto na comanda (clique no catálogo = já entra na comanda). Se já existir
     * um item do mesmo produto com as mesmas opções, soma a quantidade nele em vez de criar outra linha.
     */
    public function addOrIncrementItem(Order $order, Product $product, array $optionSelections = []): Order
    {
        return DB::transaction(function () use ($order, $product, $optionSelections) {
            $optionPricing = app(CartOptionPricing::class);
            $resolved = $optionPricing->resolve($product, ['options' => $optionSelections]);
            $optionsExtra = (float) $resolved['extra'];
            $unitPrice = (float) $product->effective_price + $optionsExtra;
            $normalizedOptions = $resolved['options'] !== [] ? $resolved['options'] : null;

            $existing = $order->items()
                ->where('product_id', $product->id)
                ->get()
                ->first(fn (OrderItem $i) => json_encode($i->options) === json_encode($normalizedOptions));

            app(StockService::class)->deductQuantity($order, $product->id, 1);

            if ($existing) {
                $existing->quantity += 1;
                $existing->subtotal = $unitPrice * $existing->quantity;
                $existing->save();
                $summary = "{$product->name}: quantidade alterada para {$existing->quantity}x";
            } else {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $unitPrice,
                    'quantity' => 1,
                    'subtotal' => $unitPrice,
                    'options' => $normalizedOptions,
                ]);
                $summary = "{$product->name} adicionado";
            }

            $this->recalculateOrderTotals($order, summary: $summary);

            return $order->fresh();
        });
    }

    /**
     * Remove um item já lançado de uma comanda aberta, restaura o estoque deduzido e recalcula os totais.
     */
    public function removeItemFromOrder(Order $order, OrderItem $item): Order
    {
        return DB::transaction(function () use ($order, $item) {
            app(StockService::class)->restoreQuantity($order, $item->product_id, $item->quantity);

            $item->delete();

            $this->recalculateOrderTotals($order, summary: "{$item->product_name} removido");

            Log::channel('orders')->info('Item removido da comanda', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'product_name' => $item->product_name,
            ]);

            return $order->fresh();
        });
    }

    /**
     * Altera a quantidade de um item já lançado numa comanda aberta, ajustando o estoque pela
     * diferença (deduz se aumentou, restaura se diminuiu) e recalcula os totais. Quantidade <= 0
     * remove o item.
     */
    public function updateOrderItemQuantity(Order $order, OrderItem $item, int $quantity): Order
    {
        if ($quantity <= 0) {
            return $this->removeItemFromOrder($order, $item);
        }

        return DB::transaction(function () use ($order, $item, $quantity) {
            $delta = $quantity - $item->quantity;

            if ($delta > 0) {
                app(StockService::class)->deductQuantity($order, $item->product_id, $delta);
            } elseif ($delta < 0) {
                app(StockService::class)->restoreQuantity($order, $item->product_id, -$delta);
            }

            $item->update([
                'quantity' => $quantity,
                'subtotal' => $item->unit_price * $quantity,
            ]);

            $this->recalculateOrderTotals($order, summary: "{$item->product_name}: quantidade alterada para {$quantity}x");

            Log::channel('orders')->info('Quantidade de item ajustada na comanda', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'product_name' => $item->product_name,
                'quantity' => $quantity,
            ]);

            return $order->fresh();
        });
    }

    /**
     * Aplica desconto manual a um pedido já existente e recalcula os totais (fechamento de comanda no PDV).
     */
    public function applyManualDiscountToOrder(
        Order $order,
        float $manualDiscount,
        bool $waiveServiceFee = false,
        bool $waiveCouvertFee = false,
    ): Order {
        $order->manual_discount = max(0.0, $manualDiscount);
        $this->recalculateOrderTotals($order, $waiveServiceFee, $waiveCouvertFee);

        return $order->fresh();
    }

    /**
     * Aplica taxa de serviço/couvert já calculadas por fora (fechamento em grupo de todas as
     * comandas de uma mesa no PDV — a taxa é única por mesa, não por comanda, então quem chama
     * decide o valor uma vez e manda aqui só pra uma comanda "carregar" a fatia; as demais do
     * grupo recebem 0/0). Sem desconto manual — isso continua sendo feito comanda por comanda.
     */
    public function applyGroupFeesToOrder(Order $order, float $serviceFee, float $couvertFee): Order
    {
        $order->manual_discount = 0.0;
        $this->recalculateOrderTotals($order, serviceFeeOverride: $serviceFee, couvertFeeOverride: $couvertFee);

        return $order->fresh();
    }

    /**
     * Recalcula subtotal/total/fee/net_value a partir dos OrderItem e do manual_discount atuais. Sem
     * cupom/frete — usado pelo fluxo de comanda do PDV, que não tem esses conceitos. Os overrides de
     * taxa existem só pro fechamento em grupo (ver {@see applyGroupFeesToOrder}); no fechamento normal
     * a taxa é sempre recalculada a partir do subtotal da própria comanda.
     */
    private function recalculateOrderTotals(
        Order $order,
        bool $waiveServiceFee = false,
        bool $waiveCouvertFee = false,
        ?string $summary = null,
        ?float $serviceFeeOverride = null,
        ?float $couvertFeeOverride = null,
    ): void {
        $subtotal = (float) $order->items()->sum('subtotal');
        $manualDiscount = (float) $order->manual_discount;

        $charge = $order->branch?->serviceCharge;
        $serviceFee = $serviceFeeOverride ?? (($charge && ! $waiveServiceFee) ? $charge->calculateServiceFee($subtotal) : 0.0);
        $couvertFee = $couvertFeeOverride ?? (($charge && ! $waiveCouvertFee) ? $charge->calculateCouvert($subtotal) : 0.0);

        $total = max(0, $subtotal - $manualDiscount + $serviceFee + $couvertFee);

        $currentCompany = app()->bound('current.company') ? app('current.company') : null;
        $fee = 0.0;
        $netValue = $total;
        if ($currentCompany) {
            $feeBase = max(0.0, $subtotal - $manualDiscount);
            $fees = app(FeeCalculator::class)->calculate($currentCompany, $feeBase, $total);
            $fee = $fees['fee'];
            $netValue = $fees['net_value'];
        }

        $order->update([
            'subtotal' => $subtotal,
            'service_fee' => $serviceFee,
            'couvert_fee' => $couvertFee,
            'total' => $total,
            'fee' => $fee,
            'net_value' => $netValue,
        ]);

        OrderItemsUpdated::dispatch($order, $summary);
    }

    private function resolveProducts(array $cart, int $branchId, string $channelColumn): Collection
    {
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
            ->where($channelColumn, true)
            ->with('optionGroups')
            ->get()
            ->keyBy('id');

        foreach ($productIds as $productId) {
            if (! $products->has($productId)) {
                Log::channel('orders')->info('Produto indisponível ao criar pedido', [
                    'product_id' => $productId,
                    'branch_id' => $branchId,
                    'channel_column' => $channelColumn,
                ]);

                throw new RuntimeException("Produto #{$productId} não está disponível nesta filial.");
            }
        }

        return $products;
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
            foreach ($order->payments()->where('status', 'paid')->get() as $payment) {
                Log::channel('orders')->info('Cancelamento do cliente disparando reembolso', [
                    'order_id' => $order->id,
                    'customer_id' => $customerId,
                    'payment_id' => $payment->id,
                    'payment_amount' => $payment->amount,
                ]);

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
    }

    /**
     * Cancela um pedido pelo admin/staff, restaurando estoque e disparando
     * reembolso quando o pedido já estava pago (mesma regra do cancelamento
     * pelo cliente — cancelar não pode deixar um pagamento capturado sem estorno).
     */
    public function cancelOrderAsAdmin(Order $order, ?int $adminUserId): void
    {
        $policy = app(OrderCancellationPolicy::class);
        $policy->authorizeAdminCancel($order);

        $order->update(['status' => 'cancelled']);

        app(StockService::class)->restoreForOrder($order);

        Log::channel('orders')->info('Pedido cancelado pelo admin', [
            'order_id' => $order->id,
            'admin_id' => $adminUserId,
        ]);

        if ($policy->requiresRefund($order)) {
            foreach ($order->payments()->where('status', 'paid')->get() as $payment) {
                Log::channel('orders')->info('Cancelamento do admin disparando reembolso', [
                    'order_id' => $order->id,
                    'admin_id' => $adminUserId,
                    'payment_id' => $payment->id,
                    'payment_amount' => $payment->amount,
                ]);

                app(RefundServiceInterface::class)->initiateRefund(
                    $order,
                    $payment,
                    (float) $payment->amount,
                    'admin',
                    $adminUserId,
                    'admin_cancel',
                );
            }
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
