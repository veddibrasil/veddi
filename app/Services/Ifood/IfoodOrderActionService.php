<?php

namespace App\Services\Ifood;

use App\Contracts\IfoodGatewayContract;
use App\Events\OrderStatusUpdated;
use App\Models\IfoodIntegration;
use App\Models\Order;
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
        $this->assertValidReason($order, $reasonCode);

        $integration = $this->resolveIntegration($order);
        $this->gateway->rejectOrder($integration, $order->external_order_id, $reasonCode);

        // O evento CAN confirma o cancelamento e restaura o estoque.

        Log::channel('ifood')->info('iFood: pedido recusado', [
            'order_id' => $order->id,
            'ifood_order_id' => $order->external_order_id,
            'reason' => $reasonCode,
        ]);
    }

    /**
     * Solicita cancelamento de um pedido já aceito. Diferente de reject(), o
     * cancelamento no iFood não é imediato — fica pendente de aprovação do lado
     * deles. O status local só muda quando a confirmação chegar via evento CAN
     * (ver App\Jobs\ProcessIfoodOrderJob::handleCancelled), não aqui.
     */
    public function requestCancellation(Order $order, string $reasonCode): void
    {
        $this->assertValidReason($order, $reasonCode);

        $integration = $this->resolveIntegration($order);
        $this->gateway->requestCancellation($integration, $order->external_order_id, $reasonCode);

        Log::channel('ifood')->info('iFood: cancelamento solicitado, aguardando confirmação do iFood', [
            'order_id' => $order->id,
            'ifood_order_id' => $order->external_order_id,
            'reason' => $reasonCode,
        ]);
    }

    public function getCancellationReasons(Order $order): array
    {
        return $this->gateway->getCancellationReasons($this->resolveIntegration($order), $order->external_order_id);
    }

    private function assertValidReason(Order $order, string $reasonCode): void
    {
        if (! collect($this->getCancellationReasons($order))->contains(fn ($reason) => (string) ($reason['code'] ?? '') === $reasonCode)) {
            throw new InvalidArgumentException("Motivo inválido para recusa/cancelamento iFood: '{$reasonCode}'.");
        }
    }

    private function resolveIntegration(Order $order): IfoodIntegration
    {
        if ($order->channel !== 'ifood' || ! $order->external_order_id) {
            throw new InvalidArgumentException('Pedido não pertence ao iFood.');
        }
        $integration = IfoodIntegration::withoutGlobalScopes()->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
            ->where('status', 'active')
            ->first();

        if (! $integration) {
            throw new RuntimeException("iFood: nenhuma integração ativa encontrada para a filial #{$order->branch_id}.");
        }

        return $integration;
    }
}
