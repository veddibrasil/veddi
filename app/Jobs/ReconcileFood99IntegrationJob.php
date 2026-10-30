<?php

namespace App\Jobs;

use App\Contracts\Food99GatewayContract;
use App\Enums\OrderChannel;
use App\Exceptions\Food99RateLimitedException;
use App\Models\Food99Integration;
use App\Models\Order;
use App\Services\Food99\Food99OrderStatusSync;
use App\Support\Food99\Food99OrderDetails;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Relê na 99 os pedidos em aberto das últimas 24 h de uma loja e aplica a etapa (ou o
 * cancelamento) que estiver à frente. O detalhe aceita 10 req/10s: poucos pedidos a cada rodada,
 * em rodízio (cursor por integração), pra todos serem relidos.
 */
class ReconcileFood99IntegrationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const PER_INTEGRATION = 8;

    public int $uniqueFor = 180;

    public function __construct(public readonly int $integrationId) {}

    public function uniqueId(): string
    {
        return (string) $this->integrationId;
    }

    public function handle(Food99GatewayContract $gateway, Food99OrderStatusSync $sync): void
    {
        // Fora de request: sem escopo de empresa, a integração define a empresa dos pedidos.
        $integration = Food99Integration::withoutGlobalScopes()->with('company')->find($this->integrationId);

        if (! $integration?->isOperational(includePaused: true)) {
            return;
        }

        $open = Order::withoutGlobalScopes()
            ->where('company_id', $integration->company_id)
            ->where('branch_id', $integration->branch_id)
            ->where('channel', OrderChannel::Food99->value)
            ->whereNotIn('status', ['delivered', 'cancelled', 'refunded'])
            ->where('created_at', '>=', now()->subDay());

        $cursorKey = "food99:reconcile-cursor:{$integration->id}";
        $cursor = (int) Cache::get($cursorKey, 0);
        $orders = (clone $open)->where('id', '>', $cursor)->orderBy('id')->limit(self::PER_INTEGRATION)->get();
        if ($orders->count() < self::PER_INTEGRATION) {
            // Fim da fila: volta pro começo.
            $orders = $orders->concat((clone $open)->where('id', '<=', $cursor)->orderBy('id')->limit(self::PER_INTEGRATION - $orders->count())->get());
        }

        foreach ($orders as $order) {
            Cache::put($cursorKey, $order->id, now()->addDay());

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
