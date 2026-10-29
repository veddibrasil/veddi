<?php

namespace App\Services\Food99;

use App\Contracts\Food99EventHandler;
use App\Contracts\Food99GatewayContract;
use App\Enums\OrderChannel;
use App\Jobs\RefreshFood99OrderDetailsJob;
use App\Models\CompanyNotification;
use App\Models\Food99WebhookEvent;
use App\Models\Order;
use App\Support\Food99\Food99OrderDetails;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Callbacks de pedido que já está no painel: confirmação, pronto, conclusão, cancelamento
 * (total ou parcial) e andamento da entrega 99.
 */
class Food99OrderEventProcessor implements Food99EventHandler
{
    public const TYPES = ['orderConfirm', 'orderReady', 'orderFinish', 'orderCancel', 'orderPartialCancel', 'deliveryStatus'];

    /** deliveryStatus → etapa do pedido. */
    private const DELIVERY_STAGES = [140 => 'DISPATCHED', 150 => 'ARRIVED', 160 => 'FINISHED'];

    private const DELIVERY_LABELS = [
        120 => 'Entregador atribuído',
        130 => 'Entregador chegou na loja',
        140 => 'Pedido coletado pelo entregador',
        150 => 'Entregador chegou ao cliente',
        160 => 'Pedido entregue',
        170 => 'Entrega cancelada',
        180 => 'Entregador reatribuído',
        190 => 'Entrega abortada',
    ];

    public function __construct(
        private readonly Food99OrderStatusSync $sync,
        private readonly Food99GatewayContract $gateway,
    ) {}

    public function handles(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public function handle(Food99WebhookEvent $event): bool
    {
        $order = $this->findOrder($event);
        if ($order === null) {
            return false;
        }

        $event->update(['order_id' => $order->id]);

        return match ($event->event_type) {
            'orderConfirm' => $this->confirmed($order, $event),
            'orderReady' => $this->advance($order, 'READY', $event),
            'orderFinish' => $this->advance($order, 'FINISHED', $event),
            'orderCancel' => $this->cancelled($order, $event),
            'orderPartialCancel' => $this->partiallyCancelled($order, $event),
            'deliveryStatus' => $this->deliveryStatus($order, $event),
            default => false,
        };
    }

    /** Etapa repetida ou atrasada não muda nada, mas o callback conta como processado. */
    private function advance(Order $order, string $stage, Food99WebhookEvent $event): bool
    {
        $this->sync->advance($order, $stage, eventId: $event->id);

        return true;
    }

    private function confirmed(Order $order, Food99WebhookEvent $event): bool
    {
        // Confirmado pelo app 99 Loja (ou repetição da nossa confirmação).
        if ($this->sync->advance($order, 'CONFIRMED', eventId: $event->id)) {
            RefreshFood99OrderDetailsJob::dispatch($order->id)->delay(now()->addSeconds(20));
        }

        return true;
    }

    /** Status 9xx, horário e reembolso só vêm no detalhe; sem ele cancela com motivo genérico. */
    private function cancelled(Order $order, Food99WebhookEvent $event): bool
    {
        $details = $order->food99Details();
        $code = null;
        $refund = null;

        try {
            $info = $this->gateway->getOrderDetails(app(Food99OrderActionService::class)->resolveIntegration($order), $details->orderId());
            $code = isset($info['status']) && (int) $info['status'] >= 900 ? (int) $info['status'] : null;
            $refund = isset($info['refund_price']) ? round(((float) $info['refund_price']) / 100, 2) : null;
        } catch (Throwable $e) {
            Log::channel('food99')->warning('99Food: detalhe do cancelamento indisponível, cancelando com motivo genérico', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->sync->cancel($order, array_filter([
            'origin' => $code !== null ? $this->originFor($code) : null,
            'code' => $code,
            'reason' => $code !== null ? (Food99OrderDetails::CANCEL_REASONS[$code] ?? null) : null,
            'refund' => $refund,
        ], fn ($value) => $value !== null), $event->id);

        return true;
    }

    private function partiallyCancelled(Order $order, Food99WebhookEvent $event): bool
    {
        $details = $order->food99Details();
        $info = $this->gateway->getOrderDetails(app(Food99OrderActionService::class)->resolveIntegration($order), $details->orderId());

        $refunded = collect($info['order_items'] ?? [])
            ->filter(fn ($item) => is_array($item) && (int) ($item['submit_refund_amount'] ?? 0) > 0)
            ->map(fn (array $item) => [
                'mdu_id' => (string) ($item['mdu_id'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'amount' => (int) $item['submit_refund_amount'],
            ])->values()->all();

        $history = $details->get('partial_cancellations', []);
        $history[] = [
            'items' => $refunded,
            'refund' => isset($info['refund_price']) ? round(((float) $info['refund_price']) / 100, 2) : null,
            'refund_fee' => isset($info['in_sale_refund_to_c_fee']) ? round(((float) $info['in_sale_refund_to_c_fee']) / 100, 2) : null,
            'at' => now()->toIso8601String(),
            'source' => 'food99_event',
        ];
        $this->sync->mergeMetadata($order, ['partial_cancellations' => $history]);

        $this->notify($order, 'Itens cancelados no pedido 99Food #'.$details->displayId(), 'Parte dos itens foi cancelada e reembolsada ao cliente. Confira no pedido.');

        return true;
    }

    private function deliveryStatus(Order $order, Food99WebhookEvent $event): bool
    {
        $data = $event->data();
        $status = (int) ($data['delivery_status'] ?? 0);
        $details = $order->food99Details();

        $this->sync->mergeMetadata($order, ['rider' => array_filter([
            'name' => $data['rider_name'] ?? $details->rider()['name'] ?? null,
            'phone' => $data['rider_phone'] ?? $details->rider()['phone'] ?? null,
            'eta' => isset($data['rider_to_B_ETA']) ? (string) $data['rider_to_B_ETA'] : ($details->rider()['eta'] ?? null),
            'status' => $status,
            'label' => self::DELIVERY_LABELS[$status] ?? null,
        ], fn ($value) => $value !== null)]);

        if (isset(self::DELIVERY_STAGES[$status])) {
            $this->sync->advance($order, self::DELIVERY_STAGES[$status], eventId: $event->id);
        }

        // Entregador atribuído: shop_paid_money (dinheiro que ele paga à loja) passa a existir.
        if ($status === 120 && $details->get('pending_estimated')) {
            RefreshFood99OrderDetailsJob::dispatch($order->id);
        }

        if (in_array($status, [170, 190], true)) {
            $this->notify($order, 'Entrega 99Food '.(self::DELIVERY_LABELS[$status] ?? 'interrompida').' #'.$details->displayId(), 'A 99Food interrompeu a entrega deste pedido. Confira no app da 99Food.');
        }

        return true;
    }

    /**
     * Pedido do callback no painel, sempre dentro da empresa da integração. Se o orderNew dele
     * ainda está sendo processado, falha pra tentar de novo (o evento volta pra fila).
     */
    private function findOrder(Food99WebhookEvent $event): ?Order
    {
        $integration = $event->food99Integration;
        $orderId = $event->external_order_id;

        if (! $integration || ! $orderId) {
            return null;
        }

        $order = Order::withoutGlobalScopes()
            ->where('company_id', $integration->company_id)
            ->where('channel', OrderChannel::Food99->value)
            ->where('external_order_id', $orderId)
            ->first();

        if ($order) {
            return $order;
        }

        $creating = Food99WebhookEvent::where('food99_integration_id', $integration->id)
            ->where('event_type', 'orderNew')
            ->where('external_order_id', $orderId)
            ->whereIn('status', [Food99WebhookEvent::STATUS_PENDING, Food99WebhookEvent::STATUS_PROCESSING])
            ->exists();

        if ($creating) {
            throw new RuntimeException("99Food: pedido {$orderId} ainda sendo criado; {$event->event_type} vai ser reprocessado.");
        }

        return null;
    }

    private function originFor(int $code): string
    {
        return match (true) {
            in_array($code, [901, 945], true) => 'customer',
            in_array($code, [921, 922, 923, 944, 946], true) => 'store',
            $code >= 940 && $code < 950 => 'courier',
            default => 'platform',
        };
    }

    private function notify(Order $order, string $title, string $subtitle): void
    {
        CompanyNotification::create([
            'company_id' => $order->company_id,
            'type' => 'food99_alert',
            'title' => mb_substr($title, 0, 255),
            'subtitle' => mb_substr($subtitle, 0, 255),
            'link' => route('admin.orders.show', $order->id),
        ]);
    }
}
