<?php

namespace App\Jobs;

use App\Events\OrderStatusUpdated;
use App\Mail\OrderConfirmation;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\AbacatePayService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ProcessOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public Order $order,
        public Customer $customer,
        public ?Company $company,
        public string $paymentMethod = 'pix',
    ) {}

    public function handle(): void
    {
        Log::channel('orders')->info('Processando pedido', [
            'order_id'       => $this->order->id,
            'order_number'   => $this->order->order_number,
            'customer_id'    => $this->customer->id,
            'payment_method' => $this->paymentMethod,
            'total'          => $this->order->total,
        ]);

        try {
            $hasToken = $this->company?->abacatepay_token || config('services.abacatepay.token');

            if ($hasToken) {
                Log::channel('payments')->info('Criando cobrança AbacatePay', [
                    'order_id'       => $this->order->id,
                    'payment_method' => $this->paymentMethod,
                    'amount'         => $this->order->total,
                ]);

                $billing = (new AbacatePayService($this->company))
                    ->createBilling($this->order, $this->customer, $this->paymentMethod);

                Payment::create([
                    'order_id'              => $this->order->id,
                    'abacatepay_billing_id' => $billing['id'],
                    'abacatepay_url'        => $billing['url'],
                    'pix_qr_code'           => $billing['pixQrCode'],
                    'pix_copy_paste'        => $billing['pixCopyPaste'],
                    'amount'                => $this->order->total,
                    'status'                => 'pending',
                    'expires_at'            => now()->addMinutes(30),
                    'payment_token'         => hash('sha256', $this->order->id . $this->customer->id . Str::random(32)),
                ]);

                Log::channel('payments')->info('Cobrança AbacatePay criada', [
                    'order_id'   => $this->order->id,
                    'billing_id' => $billing['id'],
                ]);
            } else {
                $companyName = $this->company?->name ?? config('app.name');

                Log::channel('payments')->info('Criando cobrança simulada (sem token AbacatePay)', [
                    'order_id' => $this->order->id,
                    'amount'   => $this->order->total,
                ]);

                Payment::create([
                    'order_id'              => $this->order->id,
                    'abacatepay_billing_id' => 'sim_' . uniqid(),
                    'abacatepay_url'        => '#',
                    'pix_qr_code'           => null,
                    'pix_copy_paste'        => '00020126580014br.gov.bcb.pix0136SIMULACAO-PAGAMENTO-DESENVOLVIMENTO52040000530398654'
                        . number_format($this->order->total, 2, '', '')
                        . '5802BR5924'
                        . mb_substr(preg_replace('/[^A-Z0-9 ]/', '', strtoupper($companyName)), 0, 25)
                        . '6009SAO PAULO62070503***6304ABCD',
                    'amount'                => $this->order->total,
                    'status'                => 'pending',
                    'expires_at'            => now()->addMinutes(30),
                    'payment_token'         => hash('sha256', $this->order->id . $this->customer->id . Str::random(32)),
                ]);
            }

            $this->order->update(['status' => 'awaiting_payment']);

            Log::channel('orders')->info('Pedido aguardando pagamento', [
                'order_id'     => $this->order->id,
                'order_number' => $this->order->order_number,
            ]);

            if ($this->customer->email) {
                try {
                    Mail::to($this->customer->email)
                        ->queue(new OrderConfirmation(
                            $this->order->load('items'),
                            $this->customer,
                            $this->company,
                        ));
                } catch (\Throwable $e) {
                    Log::channel('orders')->warning('Falha ao enfileirar email de confirmação', [
                        'order_id' => $this->order->id,
                        'email'    => $this->customer->email,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            // Notifica o componente Livewire via Reverb para carregar os dados de pagamento
            OrderStatusUpdated::dispatch($this->order->fresh());
        } catch (\Throwable $e) {
            Log::channel('orders')->error('Falha ao processar pedido — cancelado', [
                'order_id' => $this->order->id,
                'error'    => $e->getMessage(),
            ]);
            Log::channel('payments')->error('Falha ao criar cobrança', [
                'order_id' => $this->order->id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            $this->order->update(['status' => 'cancelled']);
            OrderStatusUpdated::dispatch($this->order->fresh());
            throw $e;
        }
    }
}
