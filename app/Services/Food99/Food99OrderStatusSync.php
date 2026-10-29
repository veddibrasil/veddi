<?php

namespace App\Services\Food99;

use App\Events\Food99OrderUpdated;
use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Services\Order\OrderService;
use App\Services\Order\StockService;
use App\Support\Food99\Food99OrderDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aplica no pedido local uma etapa da 99Food, venha ela de uma ação da loja no VEDDI
 * (Food99OrderActionService) ou de um callback (confirmação pelo app 99 Loja, entregador que
 * coletou, conclusão...). Só avança: callback atrasado ou repetido nunca faz o pedido voltar.
 */
class Food99OrderStatusSync
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
        'CONFIRMED' => 'Pedido confirmado na 99Food',
        'READY' => 'Pedido pronto na 99Food',
        'DISPATCHED' => 'Pedido saiu para entrega (99Food)',
        'ARRIVED' => 'Entregador chegou ao cliente (99Food)',
        'FINISHED' => 'Pedido concluído na 99Food',
    ];

    /**
     * @param  string  $source  'veddi' (ação da loja nesta tela) ou 'food99_event'
     * @return bool se algo mudou (status local ou etapa na 99)
     */
    public function advance(Order $order, string $stage, ?int $userId = null, string $source = 'food99_event', ?int $eventId = null): bool
    {
        $newRank = Food99OrderDetails::STAGE_RANK[$stage] ?? null;
        if ($newRank === null) {
            return false;
        }

        $result = DB::transaction(function () use ($order, $stage, $newRank, $userId, $source, $eventId) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
            $details = Food99OrderDetails::for($locked);

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
                $metadata['food99_stage'] = $stage;
                $metadata['food99_history'][] = ['stage' => $stage, 'at' => now()->toIso8601String(), 'source' => $source];
                $attributes['external_metadata'] = $metadata;
            }
            if ($statusChanged) {
                $attributes['status'] = $targetStatus;
            }
            $locked->update($attributes);

            if ($statusChanged) {
                app(OrderService::class)->recordStatusHistory(
                    $locked, $userId, $previousStatus, $targetStatus, self::STAGE_LABELS[$stage] ?? null,
                    array_filter(['source' => $source, 'food99_webhook_event_id' => $eventId, 'food99_stage' => $stage]),
                );
            }

            if ($stage === 'FINISHED') {
                $this->settlePendingPayments($locked);
            }

            return [$locked, $statusChanged];
        });

        if ($result === null) {
            return false;
        }

        [$updated, $statusChanged] = $result;
        $order->setRawAttributes($updated->getAttributes(), true);
        $statusChanged ? OrderStatusUpdated::dispatch($order) : Food99OrderUpdated::dispatch($order);

        Log::channel('food99')->info('99Food: etapa do pedido sincronizada', [
            'order_id' => $order->id,
            'food99_stage' => $stage,
            'status' => $order->status,
            'source' => $source,
        ]);

        return true;
    }

    /**
     * Cancelamento confirmado na 99 (9xx), de qualquer origem. Único ponto que devolve o
     * estoque (e o cupom, pelo caminho padrão do StockService) de pedido 99Food.
     *
     * @param  array{origin: ?string, code: ?int, reason: ?string}  $cancellation
     */
    public function cancel(Order $order, array $cancellation, ?int $eventId = null): bool
    {
        $changed = DB::transaction(function () use ($order, $cancellation, $eventId) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status === 'cancelled') {
                return null;
            }

            $metadata = $locked->external_metadata ?? [];
            $metadata['food99_stage'] = 'CANCELLED';
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
                array_filter(['source' => 'food99_event', 'food99_webhook_event_id' => $eventId, 'food99_stage' => 'CANCELLED', 'code' => $cancellation['code'] ?? null]),
            );

            return $locked;
        });

        if ($changed === null) {
            return false;
        }

        $order->setRawAttributes($changed->getAttributes(), true);
        app(StockService::class)->restoreForOrder($order);
        OrderStatusUpdated::dispatch($order);

        Log::channel('food99')->info('99Food: pedido cancelado', [
            'order_id' => $order->id,
            'code' => $cancellation['code'] ?? null,
        ]);

        return true;
    }

    /** Grava chaves no snapshot 99Food do pedido sem mexer no status. */
    public function mergeMetadata(Order $order, array $data, bool $broadcast = true): void
    {
        $updated = DB::transaction(function () use ($order, $data) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
            $locked->update(['external_metadata' => array_merge($locked->external_metadata ?? [], $data)]);

            return $locked;
        });

        $order->setRawAttributes($updated->getAttributes(), true);

        if ($broadcast) {
            Food99OrderUpdated::dispatch($order);
        }
    }

    /** Pagamento na entrega conta como recebido quando a 99 conclui (ou a loja confirma o dinheiro). */
    public function settlePendingPayments(Order $order): void
    {
        $order->payments()
            ->where('payment_gateway', '99food')
            ->where('status', 'pending')
            ->update(['status' => 'paid', 'paid_at' => now()]);
    }

    private function localStatusFor(Order $order, string $stage): ?string
    {
        return match ($stage) {
            'CONFIRMED' => 'preparing',
            'READY' => 'ready',
            'DISPATCHED' => $order->isDeliveryOrder() ? 'out_for_delivery' : 'ready',
            'FINISHED' => 'delivered',
            default => null,
        };
    }

    private function cancellationText(array $cancellation): string
    {
        $code = isset($cancellation['code']) ? (int) $cancellation['code'] : null;
        $reason = $cancellation['reason'] ?? ($code ? (Food99OrderDetails::CANCEL_REASONS[$code] ?? null) : null);

        return trim('Cancelado na 99Food'.($reason ? ": {$reason}" : '').($code ? " (código {$code})" : ''));
    }
}
