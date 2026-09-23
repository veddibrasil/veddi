<?php

namespace App\Listeners;

use App\Events\OrderStatusUpdated;
use App\Jobs\SendWhatsAppOrderNotificationJob;
use App\Models\WhatsAppSetting;
use App\Services\Messaging\WhatsAppService;

class SendWhatsAppStatusNotification
{
    public function __construct(private WhatsAppService $whatsApp) {}

    public function handle(OrderStatusUpdated $event): void
    {
        $order = $event->order;
        $status = $order->status;

        // O evento de notificação é o próprio status (pending e afins não notificam).
        if (! array_key_exists($status, WhatsAppSetting::EVENT_FIELDS)) {
            return;
        }

        if (! $this->whatsApp->shouldNotify($order, $status)) {
            return;
        }

        SendWhatsAppOrderNotificationJob::dispatch($order->id, $status);
    }
}
