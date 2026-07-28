<?php

namespace App\Listeners;

use App\Events\NewOrderPlaced;
use App\Models\CompanyNotification;

class CreateOrderNotification
{
    public function handle(NewOrderPlaced $event): void
    {
        CompanyNotification::create([
            'company_id' => $event->order->company_id,
            'type' => 'order',
            'is_delivery' => $event->order->isDeliveryOrder(),
            'title' => 'Novo pedido: '.$event->order->order_number,
            'subtitle' => $event->order->customer?->name ?? 'Cliente',
            'link' => route('admin.orders.show', $event->order->id),
        ]);
    }
}
