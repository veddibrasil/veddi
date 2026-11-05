<?php

namespace App\Services\Keeta;

use App\Contracts\KeetaEventHandler;
use App\Enums\OrderChannel;
use App\Models\CompanyNotification;
use App\Models\KeetaRequest;
use App\Models\KeetaWebhookEvent;
use App\Models\Order;
use App\Support\Keeta\KeetaOrderDetails;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Eventos de pedido que já está no painel: confirmação, pronto, entregador, despacho, entrega,
 * conclusão e cancelamento confirmado pela Keeta.
 */
class KeetaOrderEventProcessor implements KeetaEventHandler
{
    public const TYPES = [
        'CONFIRMED', 'READY_FOR_PICKUP', 'PICKUP_ONGOING', 'RIDER_ARRIVED_AT_STORE', 'ORDER_COLLECTED',
        'PICKED_UP', 'DISPATCHED', 'DELIVERED', 'CONCLUDED', 'CANCELLED', 'RECOMMENDED_PREPARATION_START',
    ];

    private const RIDER_LABELS = [
        'PICKUP_ONGOING' => 'Entregador a caminho da loja',
        'RIDER_ARRIVED_AT_STORE' => 'Entregador chegou na loja',
        'ORDER_COLLECTED' => 'Pedido coletado pelo entregador',
        'PICKED_UP' => 'Pedido coletado pelo entregador',
    ];

    public function __construct(private readonly KeetaOrderStatusSync $sync) {}

    public function handles(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public function handle(KeetaWebhookEvent $event): bool
    {
        $order = self::findOrder($event);
        if ($order === null) {
            return false;
        }

        $event->update(['order_id' => $order->id]);

        if ($event->event_type === 'CANCELLED') {
            return $this->cancelled($order, $event);
        }

        if ($event->event_type === 'RECOMMENDED_PREPARATION_START') {
            $this->rider($order, $event);

            return true;
        }

        if (isset(self::RIDER_LABELS[$event->event_type])) {
            $this->rider($order, $event);
        }

        $stage = KeetaOrderDetails::EVENT_STAGES[$event->event_type] ?? null;
        if ($stage !== null) {
            $this->sync->advance($order, $stage, eventId: $event->id);
        }

        return true;
    }

    /**
     * Pedido do evento no painel, sempre dentro da empresa da integração. Se o evento de
     * criação dele ainda está sendo processado, falha pra tentar de novo (o evento volta pra fila).
     */
    public static function findOrder(KeetaWebhookEvent $event): ?Order
    {
        $integration = $event->keetaIntegration;
        $orderId = $event->external_order_id;

        if (! $integration || ! $orderId) {
            return null;
        }

        $order = Order::withoutGlobalScopes()
            ->where('company_id', $integration->company_id)
            ->where('channel', OrderChannel::Keeta->value)
            ->where('external_order_id', $orderId)
            ->first();

        if ($order) {
            return $order;
        }

        $creating = KeetaWebhookEvent::where('keeta_integration_id', $integration->id)
            ->whereIn('event_type', ['CREATED', 'PLACED'])
            ->where('external_order_id', $orderId)
            ->whereIn('status', [KeetaWebhookEvent::STATUS_PENDING, KeetaWebhookEvent::STATUS_PROCESSING])
            ->exists();

        if ($creating) {
            throw new RuntimeException("Keeta: pedido {$orderId} ainda sendo criado; {$event->event_type} vai ser reprocessado.");
        }

        return null;
    }

    /**
     * Cancelamento confirmado pela Keeta. Pedido já entregue não volta a "cancelado" aqui: a
     * Keeta pode cancelar até 30 dias depois pelo suporte, e o painel só registra e avisa.
     */
    private function cancelled(Order $order, KeetaWebhookEvent $event): bool
    {
        $metadata = $event->metadata();
        $code = isset($metadata['code']) ? (string) $metadata['code'] : null;
        $requestedByStore = ($order->keetaDetails()?->get('cancellation_request')['status'] ?? null) === 'requested';
        $cancellation = array_filter([
            'origin' => $requestedByStore ? 'store' : 'platform',
            'code' => $code,
            'reason' => isset($metadata['reason']) ? mb_substr((string) $metadata['reason'], 0, 255) : null,
        ], fn ($value) => $value !== null);

        KeetaRequest::withoutGlobalScopes()
            ->where('company_id', $order->company_id)
            ->where('order_id', $order->id)
            ->where('kind', KeetaRequest::KIND_CANCEL)
            ->where('status', KeetaRequest::STATUS_OPEN)
            ->update(['status' => KeetaRequest::STATUS_RESOLVED]);

        if ($order->status === 'delivered') {
            $this->sync->mergeMetadata($order, ['post_delivery_cancellation' => $cancellation + ['at' => now()->toIso8601String()]]);
            $this->notify($order, 'Keeta cancelou o pedido #'.$order->keetaDetails()->displayId().' depois da entrega', 'O pedido segue como entregue no painel. Confira o repasse no portal da Keeta.');

            Log::channel('keeta')->warning('Keeta: cancelamento depois da entrega, só registrado', ['order_id' => $order->id, 'code' => $code]);

            return true;
        }

        $this->sync->cancel($order, $cancellation, $event->id);

        if (! $requestedByStore) {
            $this->notify($order, 'Pedido Keeta #'.$order->keetaDetails()->displayId().' cancelado', 'A Keeta cancelou o pedido'.(isset($cancellation['reason']) ? ': '.$cancellation['reason'] : '.'));
        }

        return true;
    }

    /** Previsão e situação do entregador da Keeta (só exibição). */
    private function rider(Order $order, KeetaWebhookEvent $event): void
    {
        $metadata = $event->metadata();
        $eta = $metadata['pickupEtaDatetime'] ?? $metadata['pickupEtaDateTime'] ?? null;
        $current = $order->keetaDetails()?->rider() ?? [];

        $data = ['rider' => array_filter([
            'status' => self::RIDER_LABELS[$event->event_type] ?? ($current['status'] ?? null),
            'pickup_eta' => is_string($eta) ? $this->localTime($eta) : ($current['pickup_eta'] ?? null),
        ], fn ($value) => $value !== null)];

        if ($event->event_type === 'RECOMMENDED_PREPARATION_START' && is_string($eta)) {
            $data['recommended_preparation_start'] = $this->localTime($eta);
        }

        $this->sync->mergeMetadata($order, $data);
    }

    private function localTime(string $value): ?string
    {
        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'))->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function notify(Order $order, string $title, string $subtitle): void
    {
        CompanyNotification::create([
            'company_id' => $order->company_id,
            'type' => 'keeta_alert',
            'title' => mb_substr($title, 0, 255),
            'subtitle' => mb_substr($subtitle, 0, 255),
            'link' => route('admin.orders.show', $order->id),
        ]);
    }
}
