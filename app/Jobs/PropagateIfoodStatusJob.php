<?php

namespace App\Jobs;

use App\Contracts\IfoodGatewayContract;
use App\Models\IfoodIntegration;
use App\Models\Order;
use App\Services\Ifood\IfoodOrderStatusSync;
use App\Support\Ifood\IfoodOrderDetails;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rede de segurança pra mudança de status local de pedido iFood feita fora de
 * IfoodOrderActionService (ver App\Listeners\PropagateIfoodOrderStatus). As telas de
 * pedido já chamam o iFood antes de mudar o status, e os eventos do iFood gravam a
 * etapa no pedido; nos dois casos a etapa já foi alcançada e este job não faz nada.
 *
 * 'preparing' não propaga: aceitar é confirmOrder (IfoodOrderActionService::accept).
 */
class PropagateIfoodStatusJob implements ShouldQueue
{
    use Queueable;

    /** Status local → etapa do iFood que ele exige. */
    private const PROPAGATABLE_STATUSES = [
        'ready' => 'READY_TO_PICKUP',
        'out_for_delivery' => 'DISPATCHED',
    ];

    public function __construct(public int $orderId, public string $status)
    {
        $this->onQueue('critical');
    }

    public function handle(IfoodGatewayContract $gateway): void
    {
        $ifoodStatus = self::PROPAGATABLE_STATUSES[$this->status] ?? null;
        if ($ifoodStatus === null) {
            return;
        }

        $order = Order::withoutGlobalScopes()->find($this->orderId);
        if (! $order || ! $order->external_order_id || $order->status !== $this->status) {
            return;
        }

        $details = $order->ifoodDetails();
        // Só a etapa gravada pelo iFood/ações conta aqui: inferir do status local diria que
        // a etapa já foi alcançada justamente porque o status local mudou.
        $known = $details?->get('ifood_status');
        $knownRank = IfoodOrderDetails::STATUS_RANK[$known] ?? -1;
        if (! $details || $known === 'CANCELLED' || $knownRank >= IfoodOrderDetails::STATUS_RANK[$ifoodStatus]) {
            return;
        }

        if ($ifoodStatus === 'DISPATCHED' && ! $details->canBeDispatchedByStore()) {
            Log::channel('ifood')->warning('iFood: pedido marcado "a caminho" localmente, mas o despacho é do iFood', [
                'order_id' => $order->id,
                'order_type' => $details->orderType(),
                'delivered_by' => $details->get('delivered_by'),
            ]);

            return;
        }

        $integration = IfoodIntegration::withoutGlobalScopes()
            ->where('company_id', $order->company_id)
            ->where('branch_id', $order->branch_id)
            ->where('status', 'active')
            ->first();

        if (! $integration) {
            Log::channel('ifood')->warning('iFood: status local mudou mas não há integração ativa pra propagar', [
                'order_id' => $order->id,
                'branch_id' => $order->branch_id,
                'status' => $this->status,
            ]);

            return;
        }

        try {
            app()->instance('current.company', $order->company);
            $sync = app(IfoodOrderStatusSync::class);

            // O iFood exige o aviso de pronto antes do despacho.
            if ($ifoodStatus === 'DISPATCHED' && $known !== null && $knownRank < IfoodOrderDetails::STATUS_RANK['READY_TO_PICKUP']) {
                $gateway->updateOrderStatus($integration, $order->external_order_id, 'ready');
                $sync->advance($order, 'READY_TO_PICKUP', null, 'veddi');
            }

            $gateway->updateOrderStatus($integration, $order->external_order_id, $this->status);
            $sync->advance($order, $ifoodStatus, null, 'veddi');
        } catch (Throwable $e) {
            Log::channel('ifood')->error('iFood: falha ao propagar status', [
                'order_id' => $order->id,
                'status' => $this->status,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            app()->forgetInstance('current.company');
        }
    }
}
