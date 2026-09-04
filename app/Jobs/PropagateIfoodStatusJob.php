<?php

namespace App\Jobs;

use App\Contracts\IfoodGatewayContract;
use App\Models\IfoodIntegration;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Propaga mudanças de status locais pro iFood via listener em OrderStatusUpdated
 * (ver App\Listeners\PropagateIfoodOrderStatus) — evita tocar nos call sites
 * existentes que já mudam status (Show.php, HasOpenTabs.php, HasPaymentFlow.php).
 *
 * 'preparing' não propaga: IfoodOrderActionService::accept() já chama
 * confirmOrder() na API do iFood, que cobre essa transição — propagar de novo
 * aqui via updateOrderStatus() duplicaria a chamada.
 */
class PropagateIfoodStatusJob implements ShouldQueue
{
    use Queueable;

    private const PROPAGATABLE_STATUSES = ['ready', 'out_for_delivery'];

    public function __construct(public int $orderId, public string $status)
    {
        $this->onQueue('critical');
    }

    public function handle(IfoodGatewayContract $gateway): void
    {
        if (! in_array($this->status, self::PROPAGATABLE_STATUSES, true)) {
            return;
        }

        $order = Order::withoutGlobalScopes()->find($this->orderId);
        if (! $order || ! $order->external_order_id) {
            return;
        }

        $integration = IfoodIntegration::withoutGlobalScopes()
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

            $gateway->updateOrderStatus($integration, $order->external_order_id, $this->status);
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
