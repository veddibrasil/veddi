<?php

namespace App\Jobs;

use App\Events\OrderStatusUpdated;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessOrder implements ShouldQueue
{
    use Queueable;

    public array $backoff = [30, 120, 600];

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function __construct(
        public Order $order,
        public Customer $customer,
        public ?Company $company,
        public string $paymentMethod = 'pix',
        public int $installments = 1,
        public array $cardData = [],
    ) {
        $this->onQueue('critical');
    }

    public function handle(PaymentOrchestrator $orchestrator): void
    {
        Log::channel('orders')->info('Processando pedido', [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'customer_id' => $this->customer->id,
            'payment_method' => $this->paymentMethod,
            'total' => $this->order->total,
        ]);

        try {
            $orchestrator->process(
                order: $this->order,
                customer: $this->customer,
                company: $this->company,
                method: $this->paymentMethod,
                cardData: $this->cardData,
                installments: $this->installments,
            );

            $this->order->update(['status' => 'awaiting_payment']);

            Log::channel('orders')->info('Pedido aguardando pagamento', [
                'order_id' => $this->order->id,
                'order_number' => $this->order->order_number,
            ]);

            OrderStatusUpdated::dispatch($this->order->fresh());
        } catch (\Throwable $e) {
            Log::channel('discord')->error('Falha ao processar pedido — cancelado', [
                'channel' => 'orders',
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
            ]);
            Log::channel('discord')->error('Falha ao criar cobrança', [
                'channel' => 'payments',
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->order->update(['status' => 'cancelled']);
            OrderStatusUpdated::dispatch($this->order->fresh());
            throw $e;
        }
    }
}
