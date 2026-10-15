<?php

namespace App\Listeners;

use App\Events\OrderStatusUpdated;
use App\Jobs\SendWhatsAppOrderNotificationJob;
use App\Models\WhatsAppSetting;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Support\Facades\Log;

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

        // Listener síncrono, muitas vezes dentro da transação de webhook/estorno: uma falha do
        // aviso não pode reverter o pagamento nem impedir os demais listeners do evento.
        try {
            if (! $this->whatsApp->shouldNotify($order, $status)) {
                return;
            }

            SendWhatsAppOrderNotificationJob::dispatch($order->id, $status);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('Notificação WhatsApp: falha ao despachar aviso de status', [
                'order_id' => $order->id,
                'company_id' => $order->company_id,
                'event' => $status,
                'exception' => $e::class,
            ]);

            report($e);
        }
    }
}
