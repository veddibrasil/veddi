<?php

namespace App\Services\Ifood;

use App\Contracts\IfoodGatewayContract;
use App\Enums\IfoodRejectReason;
use App\Events\OrderStatusUpdated;
use App\Models\IfoodIntegration;
use App\Models\Order;
use App\Services\Order\StockService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class IfoodOrderActionService
{
    public function __construct(private readonly IfoodGatewayContract $gateway) {}

    /**
     * Aceita o pedido. iFood não tem um estado "confirmado" separado no schema
     * interno — aceitar já inicia o preparo (mesmo racional do confirmOrder da
     * Order API do iFood, que assume preparo em seguida da confirmação).
     */
    public function accept(Order $order): void
    {
        $integration = $this->resolveIntegration($order);
        $this->gateway->confirmOrder($integration, $order->external_order_id);

        $order->update(['status' => 'preparing']);
        $order->refresh();
        OrderStatusUpdated::dispatch($order);

        Log::channel('ifood')->info('iFood: pedido aceito', [
            'order_id' => $order->id,
            'ifood_order_id' => $order->external_order_id,
        ]);
    }

    /**
     * Recusa o pedido antes de aceitar. Motivo precisa ser um dos códigos
     * fechados aceitos pelo iFood — validado ANTES de chamar a API externa.
     */
    public function reject(Order $order, string $reasonCode): void
    {
        $this->assertValidReason($reasonCode);

        $integration = $this->resolveIntegration($order);
        $this->gateway->rejectOrder($integration, $order->external_order_id, $reasonCode);

        $order->update(['status' => 'cancelled']);
        $order->refresh();
        app(StockService::class)->restoreForOrder($order);
        OrderStatusUpdated::dispatch($order);

        Log::channel('ifood')->info('iFood: pedido recusado', [
            'order_id' => $order->id,
            'ifood_order_id' => $order->external_order_id,
            'reason' => $reasonCode,
        ]);
    }

    /**
     * Solicita cancelamento de um pedido já aceito. Diferente de reject(), o
     * cancelamento no iFood não é imediato — fica pendente de aprovação do lado
     * deles. O status local só deve mudar quando a confirmação chegar via evento
     * (CAN), que ainda não é tratado pelo pipeline de eventos (Fase 3 cobre só
     * PLC) — fica como próximo passo, não é resolvido silenciosamente aqui.
     */
    public function requestCancellation(Order $order, string $reasonCode): void
    {
        $this->assertValidReason($reasonCode);

        $integration = $this->resolveIntegration($order);
        $this->gateway->requestCancellation($integration, $order->external_order_id, $reasonCode);

        Log::channel('ifood')->info('iFood: cancelamento solicitado, aguardando confirmação do iFood', [
            'order_id' => $order->id,
            'ifood_order_id' => $order->external_order_id,
            'reason' => $reasonCode,
        ]);
    }

    private function assertValidReason(string $reasonCode): void
    {
        if (! IfoodRejectReason::tryFrom($reasonCode)) {
            throw new InvalidArgumentException("Motivo inválido para recusa/cancelamento iFood: '{$reasonCode}'.");
        }
    }

    private function resolveIntegration(Order $order): IfoodIntegration
    {
        $integration = IfoodIntegration::where('branch_id', $order->branch_id)
            ->where('status', 'active')
            ->first();

        if (! $integration) {
            throw new RuntimeException("iFood: nenhuma integração ativa encontrada para a filial #{$order->branch_id}.");
        }

        return $integration;
    }
}
