<?php

namespace App\Jobs;

use App\Events\OrderStatusUpdated;
use App\Mail\OrderConfirmation;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\AsaasService;
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

    public function handle(AsaasService $asaas): void
    {
        Log::channel('orders')->info('Processando pedido', [
            'order_id'       => $this->order->id,
            'order_number'   => $this->order->order_number,
            'customer_id'    => $this->customer->id,
            'payment_method' => $this->paymentMethod,
            'total'          => $this->order->total,
        ]);

        try {
            $apiKey = config('services.asaas.api_key');

            if ($apiKey) {
                Log::channel('payments')->info('Criando cobrança Asaas para pedido', [
                    'order_id' => $this->order->id,
                    'amount'   => $this->order->total,
                ]);

                $asaasCustomerId = $asaas->findOrCreateCustomer([
                    'name'    => $this->customer->name,
                    'email'   => $this->customer->email,
                    'cpfCnpj' => $this->customer->tax_id ?? '',
                    'phone'   => $this->customer->phone ?? null,
                ]);

                $companyName = $this->company?->name ?? config('app.name');

                $charge = $asaas->createOrderCharge(
                    $asaasCustomerId,
                    (float) $this->order->total,
                    "Pedido #{$this->order->order_number} - {$companyName}",
                    (string) $this->order->id,
                );

                // Asaas does not return pixTransaction in the charge creation response;
                // fetch QR code via dedicated endpoint.
                $pixQrCode = $asaas->getPaymentPixQrCode($charge['id']);

                Payment::create([
                    'order_id'         => $this->order->id,
                    'asaas_payment_id' => $charge['id'],
                    'payment_gateway'  => 'asaas',
                    'pix_qr_code'      => $pixQrCode['encodedImage'],
                    'pix_copy_paste'   => $pixQrCode['payload'],
                    'amount'           => $this->order->total,
                    'status'           => 'pending',
                    'expires_at'       => now()->addMinutes(30),
                    'payment_token'    => hash('sha256', $this->order->id . $this->customer->id . Str::random(32)),
                ]);

                Log::channel('payments')->info('Cobrança Asaas criada para pedido', [
                    'order_id'   => $this->order->id,
                    'payment_id' => $charge['id'],
                ]);
            } else {
                $companyName = $this->company?->name ?? config('app.name');

                Log::channel('payments')->info('Criando cobrança simulada (sem configuração Asaas)', [
                    'order_id' => $this->order->id,
                    'amount'   => $this->order->total,
                ]);

                Payment::create([
                    'order_id'        => $this->order->id,
                    'asaas_payment_id' => 'sim_' . uniqid(),
                    'payment_gateway' => 'asaas',
                    'pix_qr_code'     => null,
                    'pix_copy_paste'  => '00020126580014br.gov.bcb.pix0136SIMULACAO-PAGAMENTO-DESENVOLVIMENTO52040000530398654'
                        . number_format((float) $this->order->total, 2, '', '')
                        . '5802BR5924'
                        . mb_substr(preg_replace('/[^A-Z0-9 ]/', '', strtoupper($companyName)), 0, 25)
                        . '6009SAO PAULO62070503***6304ABCD',
                    'amount'          => $this->order->total,
                    'status'          => 'pending',
                    'expires_at'      => now()->addMinutes(30),
                    'payment_token'   => hash('sha256', $this->order->id . $this->customer->id . Str::random(32)),
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
