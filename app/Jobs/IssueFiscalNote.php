<?php

namespace App\Jobs;

use App\Models\FiscalNote;
use App\Models\Order;
use App\Services\Fiscal\FiscalNoteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class IssueFiscalNote implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $orderId,
        public readonly ?string $customerDocument = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(FiscalNoteService $service): void
    {
        $order = Order::withoutGlobalScopes()->with(['company', 'items.product', 'branch'])->find($this->orderId);

        if (! $order) {
            Log::channel('webhook')->warning('IssueFiscalNote: pedido não encontrado', [
                'order_id' => $this->orderId,
            ]);

            return;
        }

        $service->issue($order, $this->customerDocument);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('webhook')->error('IssueFiscalNote: falha definitiva', [
            'order_id' => $this->orderId,
            'error' => $exception->getMessage(),
        ]);

        FiscalNote::where('order_id', $this->orderId)
            ->where('status', 'pending')
            ->update(['status' => 'error', 'data->error_message' => $exception->getMessage()]);
    }
}
