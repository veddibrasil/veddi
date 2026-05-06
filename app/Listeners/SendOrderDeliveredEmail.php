<?php

namespace App\Listeners;

use App\Events\OrderStatusUpdated;
use App\Mail\OrderDelivered;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderDeliveredEmail
{
    public function handle(OrderStatusUpdated $event): void
    {
        $order = $event->order->loadMissing(['customer', 'company', 'items']);

        if ($order->status !== 'delivered') {
            return;
        }

        // Idempotência: evita reenvio caso o status seja alternado no admin.
        if ($order->delivered_email_sent_at) {
            return;
        }

        $customer = $order->customer;
        if (! $customer?->email) {
            return;
        }

        try {
            Mail::to($customer->email)->queue(new OrderDelivered(
                $order,
                $customer,
                $order->company,
            ));

            $order->forceFill(['delivered_email_sent_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::channel('orders')->warning('Falha ao enfileirar email de pedido finalizado', [
                'order_id' => $order->id,
                'email' => $customer->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

