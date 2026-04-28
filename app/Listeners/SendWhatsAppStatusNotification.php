<?php

namespace App\Listeners;

use App\Events\OrderStatusUpdated;
use App\Jobs\SendWhatsAppMessage;
use App\Services\Messaging\WhatsAppService;

class SendWhatsAppStatusNotification
{
    private const STATUS_FIELD_MAP = [
        'awaiting_payment' => 'notify_on_awaiting_payment',
        'paid' => 'notify_on_paid',
        'preparing' => 'notify_on_preparing',
        'ready' => 'notify_on_ready',
        'delivered' => 'notify_on_delivered',
        'cancelled' => 'notify_on_cancelled',
        'refunded' => 'notify_on_cancelled',
    ];

    public function __construct(private WhatsAppService $whatsApp) {}

    public function handle(OrderStatusUpdated $event): void
    {
        $order = $event->order;
        $settings = $order->company?->whatsappSetting;

        if (! $settings?->enabled) {
            return;
        }

        $status = $order->status;
        $field = self::STATUS_FIELD_MAP[$status] ?? null;

        if (! $field || ! $settings->$field) {
            return;
        }

        $phone = $order->customer?->phone;
        if (! $phone) {
            return;
        }

        if ($status === 'awaiting_payment') {
            $order->loadMissing('payment');
        }

        $message = $this->whatsApp->buildMessage($order, $status);

        SendWhatsAppMessage::dispatch($phone, $message, $order->company_id);
    }
}
