<?php

namespace App\Listeners;

use App\Events\NewOrderPlaced;
use App\Jobs\SendWhatsAppOrderNotificationJob;
use App\Services\Messaging\WhatsAppService;

class SendWhatsAppOrderNotification
{
    public function __construct(private WhatsAppService $whatsApp) {}

    public function handle(NewOrderPlaced $event): void
    {
        $order = $event->order;

        // Pedido em dinheiro agendado nasce 'scheduled' sem nenhum OrderStatusUpdated depois;
        // o template "agendado" já traz a confirmação. O aviso tardio do
        // NotifyScheduledOrderJob cai na idempotência (order_id + event).
        $notification = $order->status === 'scheduled' ? 'scheduled' : 'new_order';

        if (! $this->whatsApp->shouldNotify($order, $notification)) {
            return;
        }

        SendWhatsAppOrderNotificationJob::dispatch($order->id, $notification);
    }
}
