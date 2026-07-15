<?php

namespace App\Listeners;

use App\Events\OrderStatusUpdated;
use App\Jobs\IssueFiscalNote;
use Illuminate\Support\Facades\Log;

class IssueFiscalNoteOnPaid
{
    public function handle(OrderStatusUpdated $event): void
    {
        $order = $event->order;

        if ($order->status !== 'paid') {
            return;
        }

        $company = $order->company;

        if (! $company || ! $company->canUseFiscalNotes()) {
            return;
        }

        if (! $company->fiscalConfig?->enabled) {
            return;
        }

        if ($order->fiscalNotes()->exists()) {
            Log::channel('fiscal')->debug('IssueFiscalNoteOnPaid: nota já existe, emissão automática ignorada', [
                'order_id' => $order->id,
                'company_id' => $order->company_id,
            ]);

            return;
        }

        Log::channel('fiscal')->info('IssueFiscalNoteOnPaid: emissão automática enfileirada', [
            'order_id' => $order->id,
            'company_id' => $order->company_id,
        ]);

        IssueFiscalNote::dispatch($order->id);
    }
}
