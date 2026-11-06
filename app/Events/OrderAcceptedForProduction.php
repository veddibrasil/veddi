<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pedido saiu de "aguardando aceite" (chat, iFood, 99Food, Keeta; pelo painel, pelo app da
 * plataforma ou aceite automático). É a hora de imprimir a via da cozinha/bar: imprimir na
 * chegada soltava PIX ainda não pago e pedido que acabaria recusado. As estações são
 * calculadas no disparo, com os itens que o pedido tem naquele momento.
 */
class OrderAcceptedForProduction implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $isKitchen;

    public bool $isBar;

    public function __construct(public Order $order)
    {
        $this->isKitchen = $order->hasItemsForStation('cozinha');
        $this->isBar = $order->hasItemsForStation('bar');
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
            'branch_id' => $this->order->branch_id,
            'order_type' => $this->order->order_type,
            'is_kitchen' => $this->isKitchen,
            'is_bar' => $this->isBar,
        ];
    }
}
