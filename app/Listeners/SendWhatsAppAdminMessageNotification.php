<?php

namespace App\Listeners;

use App\Events\AdminMessageSent;
use App\Jobs\SendWhatsAppMessage;
use App\Services\WhatsAppService;

class SendWhatsAppAdminMessageNotification
{
    public function __construct(private WhatsAppService $whatsApp) {}

    public function handle(AdminMessageSent $event): void
    {
        $order = $event->order;
        $settings = $order->company?->whatsappSetting;

        if (! $settings?->enabled || ! $settings->notify_on_admin_message) {
            return;
        }

        $phone = $order->customer?->phone;
        if (! $phone) {
            return;
        }

        $message = $this->whatsApp->buildAdminMessage($order, $event->message);

        SendWhatsAppMessage::dispatch($phone, $message, $order->company_id);
    }
}
