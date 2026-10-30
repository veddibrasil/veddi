<?php

namespace App\Jobs;

use App\Contracts\Food99GatewayContract;
use App\Enums\OrderChannel;
use App\Exceptions\Food99RateLimitedException;
use App\Models\Food99Integration;
use App\Models\Food99WebhookEvent;
use App\Models\Order;
use App\Services\Food99\Food99OrderStatusSync;
use App\Support\Food99\Food99OrderDetails;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O 99Food Protocol não tem polling de eventos: callback perdido deixaria o pedido parado.
 * Relê na 99 os pedidos em aberto das últimas 24 h e aplica a etapa (ou o cancelamento) que
 * estiver à frente. O detalhe aceita 10 req/10s por loja: poucos pedidos por loja a cada rodada,
 * em rodízio (cursor por integração), pra todos serem relidos.
 *
 * Também reenfileira callback preso: evento 'pending' que nenhum worker pegou ou 'processing'
 * de um worker que morreu no meio (timeout, deploy).
 */
class ReconcileFood99OrdersJob implements ShouldQueue
{
    use Queueable;

    public const PER_INTEGRATION = 8;

    /** Processamento que passa disso é de worker que morreu (o job leva segundos). */
    public const STUCK_MINUTES = 5;

    public function handle(Food99GatewayContract $gateway, Food99OrderStatusSync $sync): void
    {
        $this->requeueStuckEvents();

        Food99Integration::operational(includePaused: true)
            ->with('company')
            ->get()
            ->filter(fn (Food99Integration $integration) => $integration->isOperational(includePaused: true))
            ->each(function (Food99Integration $integration) use ($gateway, $sync) {
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
            });
    }

    private function requeueStuckEvents(): void
    {
        Food99WebhookEvent::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', Food99WebhookEvent::STATUS_PENDING)->where('updated_at', '<', now()->subMinutes(2)))
                ->orWhere(fn ($q) => $q->where('status', Food99WebhookEvent::STATUS_PROCESSING)->where('updated_at', '<', now()->subMinutes(self::STUCK_MINUTES))))
            ->where('created_at', '>=', now()->subDay())
            ->limit(100)
            ->get()
            ->each(function (Food99WebhookEvent $event) {
                if ($event->status === Food99WebhookEvent::STATUS_PROCESSING) {
                    $event->update(['status' => Food99WebhookEvent::STATUS_PENDING]);
                }
                ProcessFood99EventJob::dispatch($event->id);
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
