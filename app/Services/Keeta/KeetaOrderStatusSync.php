<?php

namespace App\Services\Keeta;

use App\Events\KeetaOrderUpdated;
use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Services\Order\OrderService;
use App\Services\Order\StockService;
use App\Support\Keeta\KeetaOrderDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aplica no pedido local uma etapa da Keeta, venha ela de uma ação da loja no VEDDI
 * (KeetaOrderActionService) ou de um evento (confirmação pelo portal, entregador que coletou,
 * conclusão...). Só avança: evento atrasado ou repetido nunca faz o pedido voltar.
 */
class KeetaOrderStatusSync
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

    private const STAGE_LABELS = [
        'CONFIRMED' => 'Pedido confirmado na Keeta',
        'READY' => 'Pedido pronto na Keeta',
        'PICKUP_ONGOING' => 'Entregador da Keeta a caminho da loja',
        'DISPATCHED' => 'Pedido saiu para entrega (Keeta)',
        'DELIVERED' => 'Pedido entregue (Keeta)',
        'CONCLUDED' => 'Pedido concluído na Keeta',
    ];

    /**
     * @param  string  $source  'veddi' (ação da loja nesta tela) ou 'keeta_event'
     * @return bool se algo mudou (status local ou etapa na Keeta)
     */
    public function advance(Order $order, string $stage, ?int $userId = null, string $source = 'keeta_event', ?int $eventId = null): bool
    {
        $newRank = KeetaOrderDetails::STAGE_RANK[$stage] ?? null;
        if ($newRank === null) {
            return false;
        }

        $result = DB::transaction(function () use ($order, $stage, $newRank, $userId, $source, $eventId) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
            $details = KeetaOrderDetails::for($locked);

            if ($details->stage() === 'CANCELLED' || in_array($locked->status, ['cancelled', 'refunded'], true)) {
                return null;
            }

            $metadataChanged = $newRank > $details->stageRank();
            $previousStatus = $locked->status;
            $targetStatus = $this->localStatusFor($locked, $stage);
            $statusChanged = $targetStatus !== null
                && (self::LOCAL_RANK[$targetStatus] ?? 0) > (self::LOCAL_RANK[$previousStatus] ?? 0);

            if (! $metadataChanged && ! $statusChanged) {
                return null;
            }

            $attributes = [];
            if ($metadataChanged) {
                $metadata = $locked->external_metadata ?? [];
                $metadata['keeta_stage'] = $stage;
                $metadata['keeta_history'][] = ['stage' => $stage, 'at' => now()->toIso8601String(), 'source' => $source];
                $attributes['external_metadata'] = $metadata;
            }
            if ($statusChanged) {
                $attributes['status'] = $targetStatus;
            }
            $locked->update($attributes);

            if ($statusChanged) {
                app(OrderService::class)->recordStatusHistory(
                    $locked, $userId, $previousStatus, $targetStatus, self::STAGE_LABELS[$stage] ?? null,
                    array_filter(['source' => $source, 'keeta_webhook_event_id' => $eventId, 'keeta_stage' => $stage]),
                );
            }

            if (in_array($stage, ['DELIVERED', 'CONCLUDED'], true)) {
                $this->settlePendingPayments($locked);
            }

            return [$locked, $statusChanged];
        });

        if ($result === null) {
            return false;
        }

        [$updated, $statusChanged] = $result;
        $order->setRawAttributes($updated->getAttributes(), true);
        $statusChanged ? OrderStatusUpdated::dispatch($order) : KeetaOrderUpdated::dispatch($order);

        Log::channel('keeta')->info('Keeta: etapa do pedido sincronizada', [
            'order_id' => $order->id,
            'keeta_stage' => $stage,
            'status' => $order->status,
            'source' => $source,
        ]);

        return true;
    }

    /**
     * Cancelamento confirmado pela Keeta (evento CANCELLED), de qualquer origem. Único ponto
     * que devolve o estoque (e o cupom, pelo caminho padrão do StockService) de pedido Keeta.
     *
     * @param  array{origin: ?string, code: ?string, reason: ?string}  $cancellation
     */
    public function cancel(Order $order, array $cancellation, ?int $eventId = null): bool
    {
        $changed = DB::transaction(function () use ($order, $cancellation, $eventId) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status === 'cancelled') {
                return null;
            }

            $metadata = $locked->external_metadata ?? [];
            $metadata['keeta_stage'] = 'CANCELLED';
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
                array_filter(['source' => 'keeta_event', 'keeta_webhook_event_id' => $eventId, 'keeta_stage' => 'CANCELLED', 'code' => $cancellation['code'] ?? null]),
            );

            // Dentro da transação: falhou aqui, o pedido não fica cancelado e o job tenta de novo.
            app(StockService::class)->restoreForOrder($locked);

            return $locked;
        });

        if ($changed === null) {
            return false;
        }

        $order->setRawAttributes($changed->getAttributes(), true);
        OrderStatusUpdated::dispatch($order);

        Log::channel('keeta')->info('Keeta: pedido cancelado', [
            'order_id' => $order->id,
            'code' => $cancellation['code'] ?? null,
        ]);

        return true;
    }

    /** Grava chaves no snapshot Keeta do pedido sem mexer no status. */
    public function mergeMetadata(Order $order, array $data, bool $broadcast = true): void
    {
        $updated = DB::transaction(function () use ($order, $data) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
            $locked->update(['external_metadata' => array_merge($locked->external_metadata ?? [], $data)]);

            return $locked;
        });

        $order->setRawAttributes($updated->getAttributes(), true);

        if ($broadcast) {
            KeetaOrderUpdated::dispatch($order);
        }
    }

    /** Pagamento na entrega conta como recebido quando a Keeta entrega/conclui o pedido. */
    public function settlePendingPayments(Order $order): void
    {
        $order->payments()
            ->where('payment_gateway', 'keeta')
            ->where('status', 'pending')
            ->update(['status' => 'paid', 'paid_at' => now()]);
    }

    private function localStatusFor(Order $order, string $stage): ?string
    {
        return match ($stage) {
            'CONFIRMED' => 'preparing',
            'READY', 'PICKUP_ONGOING' => 'ready',
            'DISPATCHED' => $order->isDeliveryOrder() ? 'out_for_delivery' : 'ready',
            'DELIVERED', 'CONCLUDED' => 'delivered',
            default => null,
        };
    }

    private function cancellationText(array $cancellation): string
    {
        $code = isset($cancellation['code']) ? (string) $cancellation['code'] : null;
        $reason = $cancellation['reason'] ?? ($code ? (KeetaOrderDetails::CANCEL_REASONS[$code] ?? null) : null);

        return trim('Cancelado na Keeta'.($reason ? ": {$reason}" : '').($code ? " ({$code})" : ''));
    }
}
