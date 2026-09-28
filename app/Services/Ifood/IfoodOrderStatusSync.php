<?php

namespace App\Services\Ifood;

use App\Events\IfoodOrderUpdated;
use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Services\Order\OrderService;
use App\Services\Order\StockService;
use App\Support\Ifood\IfoodOrderDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aplica no pedido local uma etapa do iFood, venha ela de uma ação da loja no VEDDI
 * (IfoodOrderActionService) ou de um evento (confirmação pelo Gestor de Pedidos,
 * entregador que retirou, conclusão automática...). Só avança: evento atrasado ou
 * repetido nunca faz o pedido voltar de etapa.
 */
class IfoodOrderStatusSync
{
    /** Posição de cada status local no fluxo; cancelado/reembolsado ficam fora (terminais). */
    private const LOCAL_RANK = [
        'pending' => 0,
        'awaiting_payment' => 0,
        'paid' => 0,
        'scheduled' => 0,
        'preparing' => 2,
        'ready' => 3,
        'out_for_delivery' => 4,
        'delivered' => 5,
    ];

    private const IFOOD_STATUS_LABELS = [
        'CONFIRMED' => 'Pedido confirmado no iFood',
        'PREPARATION_STARTED' => 'Preparo iniciado no iFood',
        'READY_TO_PICKUP' => 'Pedido pronto no iFood',
        'DISPATCHED' => 'Pedido despachado no iFood',
        'CONCLUDED' => 'Conclusão confirmada pelo iFood',
    ];

    /**
     * @param  string  $source  'veddi' (ação da loja nesta tela) ou 'ifood_event'
     * @return bool se algo mudou (status local ou etapa iFood)
     */
    public function advance(Order $order, string $ifoodStatus, ?int $userId = null, string $source = 'ifood_event', ?string $eventId = null): bool
    {
        $newRank = IfoodOrderDetails::STATUS_RANK[$ifoodStatus] ?? null;
        if ($newRank === null) {
            return false;
        }

        $result = DB::transaction(function () use ($order, $ifoodStatus, $newRank, $userId, $source, $eventId) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
            $details = IfoodOrderDetails::for($locked);

            if ($details->status() === 'CANCELLED' || in_array($locked->status, ['cancelled', 'refunded'], true)) {
                return null;
            }

            $metadataChanged = $newRank > $details->statusRank();
            $previousStatus = $locked->status;
            $targetStatus = $this->localStatusFor($locked, $details, $ifoodStatus);
            $statusChanged = $targetStatus !== null
                && (self::LOCAL_RANK[$targetStatus] ?? 0) > (self::LOCAL_RANK[$previousStatus] ?? 0);

            if (! $metadataChanged && ! $statusChanged) {
                return null;
            }

            $attributes = [];
            if ($metadataChanged) {
                $attributes['external_metadata'] = array_merge($locked->external_metadata ?? [], ['ifood_status' => $ifoodStatus]);
            }
            if ($statusChanged) {
                $attributes['status'] = $targetStatus;
            }
            $locked->update($attributes);

            if ($statusChanged) {
                app(OrderService::class)->recordStatusHistory(
                    $locked, $userId, $previousStatus, $targetStatus, self::IFOOD_STATUS_LABELS[$ifoodStatus] ?? null,
                    array_filter(['source' => $source, 'event_id' => $eventId, 'ifood_status' => $ifoodStatus]),
                );
            }

            if ($ifoodStatus === 'CONCLUDED') {
                $this->settlePendingPayments($locked);
            }

            return [$locked, $statusChanged];
        });

        if ($result === null) {
            return false;
        }

        [$updated, $statusChanged] = $result;
        $order->setRawAttributes($updated->getAttributes(), true);
        $statusChanged ? OrderStatusUpdated::dispatch($order) : IfoodOrderUpdated::dispatch($order);

        Log::channel('ifood')->info('iFood: etapa do pedido sincronizada', [
            'order_id' => $order->id,
            'ifood_status' => $ifoodStatus,
            'status' => $order->status,
            'source' => $source,
            'event_id' => $eventId,
        ]);

        return true;
    }

    /**
     * Cancelamento confirmado pelo iFood (CANCELLED), de qualquer origem. É o único ponto
     * que devolve o estoque de pedido iFood.
     *
     * @param  array{origin: ?string, code: ?string, reason: ?string}  $cancellation
     */
    public function cancel(Order $order, array $cancellation, ?string $eventId = null): bool
    {
        $changed = DB::transaction(function () use ($order, $cancellation, $eventId) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status === 'cancelled') {
                return null;
            }

            $metadata = $locked->external_metadata ?? [];
            $metadata['ifood_status'] = 'CANCELLED';
            $metadata['cancellation'] = $cancellation;
            if (($metadata['cancellation_request']['status'] ?? null) === 'requested') {
                $metadata['cancellation_request']['status'] = 'accepted';
            }

            $previousStatus = $locked->status;
            $reason = $this->cancellationText($cancellation);
            $locked->update([
                'status' => 'cancelled',
                'external_metadata' => $metadata,
                'cancellation_reason' => mb_substr($reason, 0, 500),
                'cancelled_at' => now(),
            ]);

            app(OrderService::class)->recordStatusHistory(
                $locked, null, $previousStatus, 'cancelled', $reason,
                array_filter(['source' => 'ifood_event', 'event_id' => $eventId, 'ifood_status' => 'CANCELLED', 'origin' => $cancellation['origin'] ?? null]),
            );

            return $locked;
        });

        if ($changed === null) {
            return false;
        }

        $order->setRawAttributes($changed->getAttributes(), true);
        app(StockService::class)->restoreForOrder($order);
        OrderStatusUpdated::dispatch($order);

        return true;
    }

    /** Grava chaves no snapshot iFood do pedido sem mexer no status. */
    public function mergeMetadata(Order $order, array $data, bool $broadcast = true): void
    {
        $updated = DB::transaction(function () use ($order, $data) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
            $locked->update(['external_metadata' => array_merge($locked->external_metadata ?? [], $data)]);

            return $locked;
        });

        $order->setRawAttributes($updated->getAttributes(), true);

        if ($broadcast) {
            IfoodOrderUpdated::dispatch($order);
        }
    }

    private function localStatusFor(Order $order, IfoodOrderDetails $details, string $ifoodStatus): ?string
    {
        return match ($ifoodStatus) {
            // Agendado confirmado continua na coluna de agendados até o preparo começar.
            'CONFIRMED' => $details->isScheduled() ? null : 'preparing',
            'PREPARATION_STARTED' => 'preparing',
            'READY_TO_PICKUP' => 'ready',
            'DISPATCHED' => $order->isDeliveryOrder() ? 'out_for_delivery' : 'ready',
            'CONCLUDED' => 'delivered',
            default => null,
        };
    }

    /** Pagamento na entrega (dinheiro/maquininha) conta como recebido quando o iFood conclui o pedido. */
    private function settlePendingPayments(Order $order): void
    {
        $order->payments()
            ->where('payment_gateway', 'ifood')
            ->where('status', 'pending')
            ->update(['status' => 'paid', 'paid_at' => now()]);
    }

    private function cancellationText(array $cancellation): string
    {
        $origin = match ($cancellation['origin'] ?? null) {
            'RESTAURANT', 'MERCHANT' => 'pela loja',
            'CONSUMER', 'CUSTOMER' => 'pelo cliente',
            'LOGISTIC', 'LOGISTICS' => 'pela logística',
            'IFOOD' => 'pelo iFood',
            null => null,
            default => 'por '.$cancellation['origin'],
        };

        return trim('Cancelado no iFood'.($origin ? " {$origin}" : '')
            .(! empty($cancellation['reason']) ? ": {$cancellation['reason']}" : '')
            .(! empty($cancellation['code']) ? " (código {$cancellation['code']})" : ''));
    }
}
