<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderItemsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public ?string $summary = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('order.'.$this->order->id),
            new Channel('orders.'.$this->order->company_id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'total' => $this->order->total,
            'summary' => $this->summary,
            'is_delivery' => $this->order->isDeliveryOrder(),
            'is_kitchen' => $this->order->hasItemsForStation('cozinha'),
            'is_bar' => $this->order->hasItemsForStation('bar'),
        ];
    }
}
