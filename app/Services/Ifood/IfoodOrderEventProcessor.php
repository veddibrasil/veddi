<?php

namespace App\Services\Ifood;

use App\Enums\OrderChannel;
use App\Events\IfoodOrderUpdated;
use App\Models\CompanyNotification;
use App\Models\IfoodDispute;
use App\Models\IfoodOrderEvent;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Eventos do iFood sobre um pedido que já existe (tudo menos o PLACED, que cria o
 * pedido em ProcessIfoodOrderJob). Mudança feita fora do VEDDI, como confirmação pelo
 * Gestor de Pedidos, também chega aqui e é refletida no pedido local.
 */
class IfoodOrderEventProcessor
{
    /** Código curto e código completo de cada evento tratado → etapa do iFood. */
    private const STATUS_EVENTS = [
        'CFM' => 'CONFIRMED', 'CONFIRMED' => 'CONFIRMED',
        'PRS' => 'PREPARATION_STARTED', 'PREPARATION_STARTED' => 'PREPARATION_STARTED',
        'RTP' => 'READY_TO_PICKUP', 'READY_TO_PICKUP' => 'READY_TO_PICKUP',
        'DSP' => 'DISPATCHED', 'DISPATCHED' => 'DISPATCHED',
        'CON' => 'CONCLUDED', 'CONCLUDED' => 'CONCLUDED',
    ];

    private const CANCELLED = ['CAN', 'CANCELLED'];

    private const CANCELLATION_REQUESTED = ['CAR', 'CANCELLATION_REQUESTED'];

    private const CANCELLATION_REQUEST_FAILED = ['CARF', 'CANCELLATION_REQUEST_FAILED'];

    private const DISPUTE = ['HSD', 'HANDSHAKE_DISPUTE'];

    private const SETTLEMENT = ['HSS', 'HANDSHAKE_SETTLEMENT'];

    public function __construct(private readonly IfoodOrderStatusSync $sync) {}

    public static function handles(string $eventType): bool
    {
        return isset(self::STATUS_EVENTS[$eventType])
            || in_array($eventType, [...self::CANCELLED, ...self::CANCELLATION_REQUESTED, ...self::CANCELLATION_REQUEST_FAILED, ...self::DISPUTE, ...self::SETTLEMENT], true);
    }

    public function process(IfoodOrderEvent $event): void
    {
        $type = $event->event_type;
        $ifoodOrderId = $event->payload['orderId'] ?? null;

        if (! $ifoodOrderId) {
            Log::channel('ifood')->warning('iFood: evento sem orderId no payload', [
                'event_id' => $event->event_id,
                'event_type' => $type,
            ]);
            $event->update(['status' => 'processed', 'processed_at' => now()]);

            return;
        }

        $order = Order::withoutGlobalScopes()
            ->where('company_id', $event->ifoodIntegration->company_id)
            ->where('channel', OrderChannel::Ifood->value)
            ->where('external_order_id', $ifoodOrderId)
            ->first();

        if (! $order && $this->placedStillProcessing($event, $ifoodOrderId)) {
            // Webhook entrega eventos em paralelo: o PLACED deste pedido ainda não terminou.
            // A exceção devolve o evento pra 'pending' e ele é reprocessado depois.
            throw new RuntimeException("iFood: pedido {$ifoodOrderId} ainda sendo criado; evento {$event->event_id} será reprocessado.");
        }

        if (! $order) {
            // Pedido nunca chegou a ser criado localmente (ex.: PLC falhou no mapeamento).
            // Negociação ainda é registrada pra não perder o prazo de resposta.
            if (in_array($type, self::DISPUTE, true)) {
                $this->openDispute($event, null);
            }

            Log::channel('ifood')->warning('iFood: evento pra pedido não encontrado localmente', [
                'event_id' => $event->event_id,
                'event_type' => $type,
                'ifood_order_id' => $ifoodOrderId,
            ]);
            $event->update(['status' => 'processed', 'processed_at' => now()]);

            return;
        }

        match (true) {
            isset(self::STATUS_EVENTS[$type]) => $this->sync->advance($order, self::STATUS_EVENTS[$type], null, 'ifood_event', $event->event_id),
            in_array($type, self::CANCELLED, true) => $this->cancelled($event, $order),
            in_array($type, self::CANCELLATION_REQUESTED, true) => $this->cancellationRequested($event, $order),
            in_array($type, self::CANCELLATION_REQUEST_FAILED, true) => $this->cancellationRequestFailed($event, $order),
            in_array($type, self::DISPUTE, true) => $this->openDispute($event, $order),
            in_array($type, self::SETTLEMENT, true) => $this->settleDispute($event, $order),
        };

        $event->update(['status' => 'processed', 'order_id' => $order->id, 'processed_at' => now()]);

        Log::channel('ifood')->info('iFood: evento de pedido processado', [
            'event_id' => $event->event_id,
            'event_type' => $type,
            'order_id' => $order->id,
            'status' => $order->status,
        ]);
    }

    private function placedStillProcessing(IfoodOrderEvent $event, string $ifoodOrderId): bool
    {
        return IfoodOrderEvent::where('ifood_integration_id', $event->ifood_integration_id)
            ->whereIn('event_type', ['PLC', 'PLACED'])
            ->whereIn('status', ['pending', 'processing'])
            ->where('payload->orderId', $ifoodOrderId)
            ->exists();
    }

    private function cancelled(IfoodOrderEvent $event, Order $order): void
    {
        $metadata = $event->payload['metadata'] ?? [];

        $this->sync->cancel($order, [
            'origin' => $metadata['CANCEL_ORIGIN'] ?? null,
            'code' => isset($metadata['CANCEL_CODE']) ? (string) $metadata['CANCEL_CODE'] : null,
            'reason' => $metadata['CANCEL_CODE_DESCRIPTION'] ?? $metadata['CANCEL_REASON_DESCRIPTION'] ?? null,
        ], $event->event_id);
    }

    /** O iFood registrou o pedido de cancelamento feito pela loja; o desfecho vem em CAN ou CARF. */
    private function cancellationRequested(IfoodOrderEvent $event, Order $order): void
    {
        $current = $order->ifoodDetails()->cancellationRequest() ?? [];

        if (($current['status'] ?? null) === 'accepted') {
            return;
        }

        $this->sync->mergeMetadata($order, ['cancellation_request' => array_merge($current, [
            'status' => 'requested',
            'at' => $current['at'] ?? $this->eventTime($event),
        ])]);
    }

    /** iFood recusou o cancelamento pedido pela loja: o pedido segue ativo e a loja precisa saber. */
    private function cancellationRequestFailed(IfoodOrderEvent $event, Order $order): void
    {
        $metadata = $event->payload['metadata'] ?? [];
        $reason = collect([
            $metadata['CANCELLATION_REQUEST_FAILED_REASON'] ?? null,
            $metadata['reason'] ?? null,
            $metadata['message'] ?? null,
            $metadata['details'] ?? null,
            $metadata['CANCEL_CODE_DESCRIPTION'] ?? null,
        ])->first(fn ($value) => is_string($value) && trim($value) !== '');

        $current = $order->ifoodDetails()->cancellationRequest() ?? [];
        $this->sync->mergeMetadata($order, ['cancellation_request' => array_merge($current, [
            'status' => 'failed',
            'failure_reason' => $reason,
            'failed_at' => $this->eventTime($event),
        ])]);

        $this->notify($order, 'O iFood recusou o cancelamento do pedido #'.$order->ifoodDetails()->displayId(),
            $reason ? "Motivo: {$reason}. O pedido continua ativo." : 'O pedido continua ativo. Siga com o preparo ou tente outro motivo.');
    }

    private function openDispute(IfoodOrderEvent $event, ?Order $order): void
    {
        $metadata = $event->payload['metadata'] ?? [];
        $disputeId = $metadata['disputeId'] ?? $metadata['id'] ?? null;

        if (! $disputeId) {
            Log::channel('ifood')->warning('iFood: negociação sem disputeId, ignorada', ['event_id' => $event->event_id]);

            return;
        }

        try {
            $dispute = IfoodDispute::withoutGlobalScopes()->create([
                'company_id' => $event->ifoodIntegration->company_id,
                'ifood_integration_id' => $event->ifood_integration_id,
                'order_id' => $order?->id,
                'dispute_id' => (string) $disputeId,
                'ifood_order_id' => (string) $event->payload['orderId'],
                'action' => $metadata['action'] ?? null,
                'handshake_type' => $metadata['handshakeType'] ?? null,
                'timeout_action' => $metadata['timeoutAction'] ?? null,
                'message' => $metadata['message'] ?? null,
                'expires_at' => isset($metadata['expiresAt']) ? Carbon::parse($metadata['expiresAt'])->setTimezone(config('app.timezone')) : null,
                'alternatives' => $metadata['alternatives'] ?? [],
                'metadata' => $metadata,
                'status' => IfoodDispute::STATUS_PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        if ($order) {
            IfoodOrderUpdated::dispatch($order);
            $this->notify($order, 'Negociação iFood no pedido #'.$order->ifoodDetails()->displayId(),
                $dispute->actionLabel().'. Responda antes de '.($dispute->expires_at?->format('H:i') ?? 'o prazo acabar').'.');
        }
    }

    private function settleDispute(IfoodOrderEvent $event, Order $order): void
    {
        $metadata = $event->payload['metadata'] ?? [];
        $disputeId = $metadata['disputeId'] ?? null;

        $dispute = $disputeId
            ? IfoodDispute::withoutGlobalScopes()->where('dispute_id', (string) $disputeId)->first()
            : null;

        if (! $dispute) {
            Log::channel('ifood')->warning('iFood: desfecho de negociação sem disputa registrada', [
                'event_id' => $event->event_id,
                'dispute_id' => $disputeId,
            ]);

            return;
        }

        $dispute->update([
            'order_id' => $dispute->order_id ?? $order->id,
            'status' => IfoodDispute::STATUS_SETTLED,
            'settlement' => $metadata,
            'settled_at' => now(),
        ]);

        IfoodOrderUpdated::dispatch($order);
    }

    private function notify(Order $order, string $title, string $subtitle): void
    {
        CompanyNotification::create([
            'company_id' => $order->company_id,
            'type' => 'ifood',
            'is_delivery' => false,
            'is_kitchen' => false,
            'is_bar' => false,
            'title' => mb_substr($title, 0, 255),
            'subtitle' => mb_substr($subtitle, 0, 255),
            'link' => route('admin.orders.show', $order->id),
        ]);
    }

    private function eventTime(IfoodOrderEvent $event): string
    {
        $createdAt = $event->payload['createdAt'] ?? null;

        return (is_string($createdAt) ? Carbon::parse($createdAt) : now())->setTimezone(config('app.timezone'))->toIso8601String();
    }
}
