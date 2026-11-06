<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewOrderPlaced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Imprimir a via de produção já na chegada: só pedido de fora do PDV que não vai passar
     * pelo aceite (ex.: marketplace que entrou já confirmado). O resto imprime no aceite
     * (OrderAcceptedForProduction). Calculado no disparo: o broadcast vai pra fila e, quando
     * sai, o pedido já pode ter sido aceito, o que imprimiria duas vezes.
     */
    public bool $printOnArrival;

    public function __construct(public Order $order)
    {
        $this->printOnArrival = $order->order_type !== 'pdv' && ! $order->waitsForAcceptance() && ! $order->isClosed();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('orders.'.$this->order->company_id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'branch_id' => $this->order->branch_id,
            'order_type' => $this->order->order_type,
            'channel' => $this->order->channel ?? 'chat',
            'customer_name' => $this->order->customer?->name ?? 'Cliente',
            'total' => $this->order->total,
            'payment_method' => $this->order->payment_method,
            'is_delivery' => $this->order->isDeliveryOrder(),
            'is_kitchen' => $this->order->hasItemsForStation('cozinha'),
            'is_bar' => $this->order->hasItemsForStation('bar'),
            'print_on_arrival' => $this->printOnArrival,
        ];
    }
}
