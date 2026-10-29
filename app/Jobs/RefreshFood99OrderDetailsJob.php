<?php

namespace App\Jobs;

use App\Contracts\Food99GatewayContract;
use App\DTOs\Food99OrderDTO;
use App\Exceptions\Food99RateLimitedException;
use App\Models\Order;
use App\Services\Food99\Food99OrderActionService;
use App\Services\Food99\Food99OrderStatusSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Relê o pedido na 99 pra preencher o que só existe depois de uma etapa: previsões de preparo
 * e chegada (após confirmar) e shop_paid_money (entrega 99 em dinheiro, após atribuir entregador).
 */
class RefreshFood99OrderDetailsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [15, 60];

    public function __construct(public int $orderId)
    {
        $this->onQueue('default');
    }

    public function handle(Food99GatewayContract $gateway, Food99OrderActionService $actions, Food99OrderStatusSync $sync): void
    {
        $order = Order::withoutGlobalScopes()->find($this->orderId);
        $details = $order?->food99Details();

        if (! $details || $details->isFinished()) {
            return;
        }

        try {
            $info = $gateway->getOrderDetails($actions->resolveIntegration($order), $details->orderId());
        } catch (Food99RateLimitedException $e) {
            $this->release(max(10, $e->retryAfter));

            return;
        }

        $dto = Food99OrderDTO::fromArray($info + ['order_id' => $details->orderId()]);
        $data = array_filter([
            'expected_cook_eta' => $dto->expectedCookEta,
            'expected_arrived_eta' => $dto->expectedArrivedEta,
            'handover_code' => $dto->handoverCode,
            'takeaway_code' => $dto->takeawayCode,
        ], fn ($value) => $value !== null);

        if ($details->get('pending_estimated') && ! $dto->pendingEstimated) {
            $data += [
                'pending_amount' => $dto->pendingAmount,
                'pending_estimated' => false,
                'shop_paid_money' => $dto->shopPaidMoney,
                'payment_methods' => $dto->paymentMethods(),
            ];
            $order->payments()
                ->where('payment_gateway', '99food')
                ->where('status', 'pending')
                ->update(['amount' => min((float) $order->total, $dto->pendingAmount)]);
        } elseif ($dto->shopPaidMoney > 0) {
            $data['shop_paid_money'] = $dto->shopPaidMoney;
        }

        if ($data !== []) {
            $sync->mergeMetadata($order, $data);
        }
    }
}
