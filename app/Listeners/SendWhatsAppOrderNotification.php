<?php

namespace App\Listeners;

use App\Events\NewOrderPlaced;
use App\Jobs\SendWhatsAppMessage;
use App\Services\Messaging\WhatsAppService;

class SendWhatsAppOrderNotification
{
    public function __construct(private WhatsAppService $whatsApp) {}

    public function handle(NewOrderPlaced $event): void
    {
        $order = $event->order;
        $settings = $order->company?->whatsappSetting;

        if (! $settings?->enabled || ! $settings->notify_on_new_order) {
            return;
        }

        $phone = $order->customer?->phone;
        if (! $phone) {
            return;
        }

        $message = $this->whatsApp->buildMessage($order, 'new_order');

        SendWhatsAppMessage::dispatch($phone, $message, $order->company_id);
    }
}
