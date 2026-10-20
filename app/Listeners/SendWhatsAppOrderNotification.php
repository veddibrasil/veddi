<?php

namespace App\Listeners;

use App\Events\NewOrderPlaced;
use App\Jobs\SendWhatsAppOrderNotificationJob;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Support\Facades\Log;

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

        // Listener síncrono: uma falha do aviso não pode derrubar a criação do pedido nem os
        // demais listeners do evento.
        try {
            if (! $this->whatsApp->shouldNotify($order, $notification)) {
                return;
            }

            SendWhatsAppOrderNotificationJob::dispatch($order->id, $notification);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('Notificação WhatsApp: falha ao despachar aviso do pedido', [
                'order_id' => $order->id,
                'company_id' => $order->company_id,
                'event' => $notification,
                'exception' => $e::class,
            ]);

            report($e);
        }
    }
}
