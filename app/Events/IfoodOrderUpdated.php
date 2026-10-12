<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Algo do pedido iFood mudou sem mudar o status (negociação aberta/respondida,
 * cancelamento recusado...). Só atualiza as telas; não passa pelos listeners de
 * OrderStatusUpdated, que propagam status pro iFood e disparam notificações.
 */
class IfoodOrderUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('order.'.$this->order->id),
            new PrivateChannel('orders.'.$this->order->company_id),
        ];
    }

    public function broadcastWith(): array
    {
        return ['order_id' => $this->order->id];
    }
}
