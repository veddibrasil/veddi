<?php

namespace App\Jobs;

use App\Events\OrderStatusUpdated;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessOrder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Status em que o pedido ainda espera a cobrança (criação ou renovação do PIX). */
    private const PAYABLE_STATUSES = ['pending', 'awaiting_payment'];

    public array $backoff = [15, 60, 180];

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return (string) $this->order->id;
    }

    /**
     * O cliente está no checkout esperando o QR/aprovação: depois de alguns minutos de falha da
     * gateway a cobrança não serve mais. O failed() cancela e o chat mostra a falha.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function __construct(
        public Order $order,
        public ?Customer $customer,
        public ?Company $company,
        public string $paymentMethod = 'pix',
        public int $installments = 1,
        public array $cardData = [],
    ) {
        // Guest contacts are reconstructed from the persisted order, never serialized as models.
        if ($order->buyer_data !== null) {
            $this->customer = null;
        }
        $this->onQueue('critical');
    }

    public function handle(PaymentOrchestrator $orchestrator): void
    {
        $this->customer = $this->order->buyer_data !== null ? $this->order->customer : $this->customer;

        // Retentativa de um pedido que já saiu do fluxo de cobrança (cliente trocou a forma de
        // pagamento, PIX expirou, admin cancelou, já foi pago): não gera cobrança nem reabre o pedido.
        if (! in_array($this->order->status, self::PAYABLE_STATUSES, true)) {
            Log::channel('orders')->info('ProcessOrder: pedido fora do fluxo de cobrança, ignorado', [
                'order_id' => $this->order->id,
                'status' => $this->order->status,
                'attempt' => $this->attempts(),
            ]);

            return;
        }

        Log::channel('orders')->info('Processando pedido', [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'customer_id' => $this->customer->id,
            'payment_method' => $this->paymentMethod,
            'total' => $this->order->total,
            'attempt' => $this->attempts(),
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

            // A cobrança leva segundos na Vindi: o pedido pode ter sido cancelado nesse meio-tempo
            // (cliente trocou a forma de pagamento, admin cancelou). Relê com lock e só avança se
            // ainda espera cobrança — senão reabriria um pedido cancelado com estoque já devolvido.
            // A cobrança criada fica 'pending': se for paga mesmo assim, o webhook/polling estorna.
            $stillPayable = DB::transaction(function () {
                $order = Order::withoutGlobalScopes()->whereKey($this->order->id)->lockForUpdate()->first();

                if (! $order || ! in_array($order->status, self::PAYABLE_STATUSES, true)) {
                    return false;
                }

                $order->update(['status' => 'awaiting_payment']);

                return true;
            });

            if (! $stillPayable) {
                Log::channel('orders')->warning('ProcessOrder: pedido saiu do fluxo de cobrança enquanto a cobrança era criada', [
                    'order_id' => $this->order->id,
                    'attempt' => $this->attempts(),
                ]);

                return;
            }

            Log::channel('orders')->info('Pedido aguardando pagamento', [
                'order_id' => $this->order->id,
                'order_number' => $this->order->order_number,
            ]);

            OrderStatusUpdated::dispatch($this->order->fresh());
        } catch (\Throwable $e) {
            // Não cancela aqui: a fila retenta, e cancelar a cada falha fazia o cliente receber
            // "cancelado" e depois "aguardando pagamento" no mesmo pedido. Quem cancela é o failed().
            Log::channel('discord')->error('Falha ao processar pedido — nova tentativa agendada', [
                'channel' => 'orders',
                'order_id' => $this->order->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);
            Log::channel('discord')->error('Falha ao criar cobrança', [
                'channel' => 'payments',
                'order_id' => $this->order->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('discord')->critical('ProcessOrder: todas as tentativas esgotadas', [
            'channel' => 'orders',
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'payment_method' => $this->paymentMethod,
            'error' => $e->getMessage(),
        ]);

        // cancelBySystem só cancela se o pedido ainda espera cobrança (o cliente pode ter trocado a
        // forma de pagamento, ou o pedido ter sido pago/cancelado, enquanto as tentativas corriam)
        // e devolve o estoque. O chat (poll/Echo) leva o cliente para ORDER_FAILED ao ver o 'cancelled'.
        app(OrderService::class)->cancelBySystem($this->order, 'Não foi possível gerar a cobrança', 'charge_failed');
    }
}
