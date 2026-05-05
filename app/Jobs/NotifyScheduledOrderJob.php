<?php

namespace App\Jobs;

use App\Events\OrderStatusUpdated;
use App\Models\CompanyNotification;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class NotifyScheduledOrderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $orderId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $order = Order::with(['customer', 'branch'])->find($this->orderId);

        if (! $order || ! $order->isScheduled()) {
            return;
        }

        if (in_array($order->status, ['cancelled', 'refunded', 'delivered'])) {
            Log::channel('orders')->info('NotifyScheduledOrderJob: pedido já finalizado, ignorado', [
                'order_id' => $this->orderId,
                'status' => $order->status,
            ]);

            return;
        }

        $scheduledLabel = $order->scheduled_at->setTimezone(config('app.timezone'))->format('d/m/Y \à\s H:i');

        CompanyNotification::create([
            'company_id' => $order->company_id,
            'type' => 'scheduled_order',
            'title' => 'Pedido agendado: hora de preparar!',
            'subtitle' => "Pedido {$order->order_number} de {$order->customer->name} estava agendado para {$scheduledLabel}.",
            'link' => route('admin.orders.show', $order),
        ]);

        OrderStatusUpdated::dispatch($order);

        Log::channel('orders')->info('Notificação de pedido agendado disparada', [
            'order_id' => $order->id,
            'scheduled_at' => $order->scheduled_at->toIso8601String(),
        ]);
    }
}
