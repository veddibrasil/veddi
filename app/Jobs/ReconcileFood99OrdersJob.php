<?php

namespace App\Jobs;

use App\Contracts\Food99GatewayContract;
use App\Enums\OrderChannel;
use App\Exceptions\Food99RateLimitedException;
use App\Models\Food99Integration;
use App\Models\Order;
use App\Services\Food99\Food99OrderStatusSync;
use App\Support\Food99\Food99OrderDetails;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O 99Food Protocol não tem polling de eventos: callback perdido deixaria o pedido parado.
 * Relê na 99 os pedidos em aberto das últimas 24 h e aplica a etapa (ou o cancelamento) que
 * estiver à frente. O detalhe aceita 10 req/10s por loja: poucos pedidos por loja a cada rodada,
 * os parados há mais tempo primeiro.
 */
class ReconcileFood99OrdersJob implements ShouldQueue
{
    use Queueable;

    public const PER_INTEGRATION = 8;

    public function handle(Food99GatewayContract $gateway, Food99OrderStatusSync $sync): void
    {
        Food99Integration::operational(includePaused: true)
            ->with('company')
            ->get()
            ->filter(fn (Food99Integration $integration) => $integration->isOperational(includePaused: true))
            ->each(function (Food99Integration $integration) use ($gateway, $sync) {
                $orders = Order::withoutGlobalScopes()
                    ->where('company_id', $integration->company_id)
                    ->where('branch_id', $integration->branch_id)
                    ->where('channel', OrderChannel::Food99->value)
                    ->whereNotIn('status', ['delivered', 'cancelled', 'refunded'])
                    ->where('created_at', '>=', now()->subDay())
                    ->oldest('updated_at')
                    ->limit(self::PER_INTEGRATION)
                    ->get();

                foreach ($orders as $order) {
                    try {
                        $this->reconcile($order, $gateway->getOrderDetails($integration, $order->food99Details()->orderId()), $sync);
                    } catch (Food99RateLimitedException) {
                        break;
                    } catch (Throwable $e) {
                        Log::channel('food99')->warning('99Food: reconciliação do pedido falhou', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function reconcile(Order $order, array $info, Food99OrderStatusSync $sync): void
    {
        $status = (int) ($info['status'] ?? 0);

        if ($status >= 900) {
            $sync->cancel($order, [
                'origin' => 'platform',
                'code' => $status,
                'reason' => Food99OrderDetails::CANCEL_REASONS[$status] ?? null,
            ]);

            return;
        }

        if ($stage = Food99OrderDetails::STATUS_STAGES[$status] ?? null) {
            $sync->advance($order, $stage, source: 'food99_reconcile');
        }
    }
}
