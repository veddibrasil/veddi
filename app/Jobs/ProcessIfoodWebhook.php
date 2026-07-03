<?php

namespace App\Jobs;

use App\Contracts\OrderServiceInterface;
use App\Events\OrderStatusUpdated;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Portal;
use App\Models\ProductPortalMapping;
use App\Services\Payment\PaymentOrchestrator;
use App\Services\Portal\PortalGatewayFactory;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessIfoodWebhook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public int $uniqueFor = 900; // iFood reentrega o mesmo evento por até 15min

    public function uniqueId(): string
    {
        return hash('sha256', (string) ($this->event['id'] ?? ''));
    }

    public function __construct(public array $event)
    {
        $this->onQueue('critical');
    }

    public function handle(): void
    {
        $fullCode = $this->event['fullCode'] ?? $this->event['code'] ?? null;
        $merchantId = $this->event['merchantId'] ?? null;

        $portal = Portal::withoutGlobalScopes()
            ->where('channel', 'ifood')
            ->where('external_merchant_id', $merchantId)
            ->first();

        if (! $portal) {
            Log::channel('webhook')->warning('iFood webhook: portal não encontrado para merchant', [
                'merchant_id' => $merchantId,
                'event_id' => $this->event['id'] ?? null,
            ]);

            return;
        }

        match ($fullCode) {
            'PLACED' => $this->handlePlaced($portal),
            'CONFIRMED', 'SEPARATION_STARTED', 'SEPARATION_ENDED', 'READY_TO_PICKUP', 'DISPATCHED', 'CONCLUDED', 'CANCELLED' => $this->handleStatusUpdate($fullCode),
            default => Log::channel('webhook')->info('iFood webhook: evento ignorado', ['full_code' => $fullCode]),
        };
    }

    private function handlePlaced(Portal $portal): void
    {
        if (! $portal->company->portals_module_enabled) {
            Log::channel('discord')->error('iFood: pedido recebido mas módulo Portais não está mais contratado — não criado', [
                'external_order_id' => $this->event['orderId'] ?? null,
                'portal_id' => $portal->id,
                'company_id' => $portal->company_id,
            ]);

            return;
        }

        $portalOrder = app(PortalGatewayFactory::class)->for($portal)->fetchOrder(
            (string) ($this->event['orderId'] ?? '')
        );

        $externalItemIds = collect($portalOrder->items)->pluck('external_item_id')->unique();

        $productIdsByExternalId = ProductPortalMapping::where('portal_id', $portal->id)
            ->whereIn('external_item_id', $externalItemIds)
            ->pluck('product_id', 'external_item_id');

        $unmapped = $externalItemIds->diff($productIdsByExternalId->keys());
        if ($unmapped->isNotEmpty()) {
            Log::channel('discord')->critical('iFood: pedido com produto sem mapeamento — NÃO criado, requer ação manual em /admin/portals', [
                'external_order_id' => $portalOrder->externalOrderId,
                'portal_id' => $portal->id,
                'unmapped_external_item_ids' => $unmapped->values()->all(),
            ]);

            return;
        }

        $cart = collect($portalOrder->items)->map(fn (array $item) => [
            'product_id' => (int) $productIdsByExternalId[$item['external_item_id']],
            'qty' => $item['quantity'],
        ])->all();

        app()->instance('current.company', $portal->company);

        try {
            $customer = Customer::updateOrCreate(
                [
                    'company_id' => $portal->company_id,
                    'phone' => $portalOrder->customerPhone ?? 'ifood-'.$portalOrder->externalOrderId,
                ],
                ['name' => $portalOrder->customerName ?? 'Cliente iFood'],
            );

            $order = app(OrderServiceInterface::class)->createOrder(
                customerId: $customer->id,
                branchId: $portal->branch_id,
                cart: $cart,
                notes: '',
                paymentMethod: 'external_portal',
                orderType: $portalOrder->orderType,
                status: 'preparing',
                deliveryFee: $portalOrder->deliveryFee,
                channel: 'ifood',
                portalId: $portal->id,
                externalOrderId: $portalOrder->externalOrderId,
            );

            app(PaymentOrchestrator::class)->processExternalPortal($order, 'ifood', $portalOrder->isPaid);

            OrderStatusUpdated::dispatch($order->fresh());

            try {
                app(PortalGatewayFactory::class)->for($portal)->confirmOrder($portalOrder->externalOrderId);
            } catch (Throwable $e) {
                Log::channel('discord')->error('iFood: pedido criado localmente mas falhou ao confirmar no portal — iFood pode auto-cancelar se não for confirmado a tempo', [
                    'order_id' => $order->id,
                    'external_order_id' => $portalOrder->externalOrderId,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::channel('orders')->info('Pedido iFood criado', [
                'order_id' => $order->id,
                'portal_id' => $portal->id,
                'external_order_id' => $portalOrder->externalOrderId,
            ]);
        } finally {
            app()->forgetInstance('current.company');
        }
    }

    private function handleStatusUpdate(string $fullCode): void
    {
        $externalOrderId = $this->event['orderId'] ?? null;

        $order = Order::withoutGlobalScopes()
            ->where('channel', 'ifood')
            ->where('external_order_id', $externalOrderId)
            ->first();

        if (! $order) {
            Log::channel('webhook')->warning('iFood webhook: pedido local não encontrado', [
                'external_order_id' => $externalOrderId,
                'full_code' => $fullCode,
            ]);

            return;
        }

        $order->update(['external_status' => $fullCode]);

        if ($fullCode === 'CANCELLED' && $order->status !== 'cancelled') {
            $order->update(['status' => 'cancelled']);
        }

        OrderStatusUpdated::dispatch($order->fresh());

        Log::channel('webhook')->info('iFood webhook: status atualizado', [
            'order_id' => $order->id,
            'full_code' => $fullCode,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Falha ao processar webhook iFood', [
            'type' => 'webhook',
            'event' => $this->event,
            'error' => $exception->getMessage(),
        ]);
    }
}
