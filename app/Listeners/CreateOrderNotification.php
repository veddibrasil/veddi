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
            'is_kitchen' => $event->order->hasItemsForStation('cozinha'),
            'is_bar' => $event->order->hasItemsForStation('bar'),
            'title' => ($marketplace = $event->order->marketplaceDetails())
                ? 'Novo pedido '.$marketplace->channelLabel().' #'.$marketplace->displayId()
                : 'Novo pedido: '.$event->order->order_number,
            'subtitle' => $marketplace?->awaitingConfirmation()
                ? ($marketplace->isScheduled() ? 'Agendado: aceite no '.$marketplace->channelLabel() : 'Aceite em até '.$marketplace->confirmationMinutes().' min')
                : ($event->order->customer?->name ?? 'Cliente'),
            'link' => route('admin.orders.show', $event->order->id),
        ]);
    }
}
