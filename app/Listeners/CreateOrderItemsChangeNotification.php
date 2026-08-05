<?php

namespace App\Listeners;

use App\Events\OrderItemsUpdated;
use App\Models\CompanyNotification;
use Illuminate\Support\Str;

class CreateOrderItemsChangeNotification
{
    public function handle(OrderItemsUpdated $event): void
    {
        if (! $event->summary) {
            return;
        }

        CompanyNotification::create([
            'company_id' => $event->order->company_id,
            'type' => 'order_items',
            'is_delivery' => $event->order->isDeliveryOrder(),
            'is_kitchen' => $event->order->hasItemsForStation('cozinha'),
            'is_bar' => $event->order->hasItemsForStation('bar'),
            'title' => 'Pedido '.$event->order->order_number.' atualizado',
            'subtitle' => Str::limit($event->summary, 255, ''),
            'link' => route('admin.orders.show', $event->order->id),
        ]);
    }
}
