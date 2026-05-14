<?php

namespace App\Listeners;

use App\Events\NewOrderPlaced;
use App\Mail\OrderConfirmation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderConfirmationEmail
{
    public function handle(NewOrderPlaced $event): void
    {
        $order = $event->order->loadMissing(['customer', 'company', 'items']);

        // Idempotência: evita reenvio caso o evento seja disparado mais de uma vez.
        if ($order->confirmation_email_sent_at) {
            return;
        }

        $customer = $order->customer;
        if (! $customer?->email) {
            return;
        }

        try {
            Mail::to($customer->email)->queue(new OrderConfirmation(
                $order,
                $customer,
                $order->company,
            ));

            $order->forceFill(['confirmation_email_sent_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::channel('orders')->warning('Falha ao enfileirar email de confirmação do pedido', [
                'order_id' => $order->id,
                'email' => $customer->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
