<?php

namespace App\Jobs;

use App\Events\OrderStatusUpdated;
use App\Mail\OrderConfirmation;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\AsaasService;
use App\Services\PaymentCalculatorService;
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

    const PIX_FEE = 1.99;

    public function __construct(
        public Order $order,
        public Customer $customer,
        public ?Company $company,
        public string $paymentMethod = 'pix',
        public int $installments = 1,
        public array $cardData = [],
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

            if ($this->paymentMethod === 'CREDIT_CARD') {
                $this->processCreditCard($asaas, $apiKey);
            } else {
                $this->processPix($asaas, $apiKey);
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
            Log::channel('discord')->error('Falha ao processar pedido — cancelado', [
                'channel'  => 'orders',
                'order_id' => $this->order->id,
                'error'    => $e->getMessage(),
            ]);
            Log::channel('discord')->error('Falha ao criar cobrança', [
                'channel'  => 'payments',
                'order_id' => $this->order->id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            $this->order->update(['status' => 'cancelled']);
            OrderStatusUpdated::dispatch($this->order->fresh());
            throw $e;
        }
    }

    private function processCreditCard(AsaasService $asaas, ?string $apiKey): void
    {
        $settings   = $this->company?->paymentSettings;
        $calculator = app(PaymentCalculatorService::class);

        $anticipationDays  = $settings?->default_anticipation_days ?? 15;
        $cardFeeAbsorbed   = $this->company?->card_fee_absorbed_by_company ?? false;
        $breakdown         = $calculator->calculate(
            (float) $this->order->total,
            $anticipationDays,
            $settings
        );

        // Quando a empresa absorve a taxa, cobra o valor original do pedido ao cliente.
        // A taxa é calculada diretamente (sem a fórmula de repasse), pois o Asaas
        // irá deduzir sua taxa do valor recebido.
        if ($cardFeeAbsorbed) {
            $chargeAmount = (float) $this->order->total;
            $feeAmount    = round($chargeAmount * $breakdown['total_rate'], 2);
        } else {
            $chargeAmount = $breakdown['final_amount'];
            $feeAmount    = $breakdown['fee_amount'];
        }

        Log::channel('payments')->info('Calculando taxa de cartão', [
            'order_id'          => $this->order->id,
            'original_amount'   => $breakdown['original_amount'],
            'charge_amount'     => $chargeAmount,
            'fee_amount'        => $feeAmount,
            'total_rate_pct'    => round($breakdown['total_rate'] * 100, 2) . '%',
            'installments'      => $this->installments,
            'anticipation_days' => $anticipationDays,
            'card_fee_absorbed' => $cardFeeAbsorbed,
        ]);

        if ($apiKey) {
            $companyName = $this->company?->name ?? config('app.name');

            $asaasCustomerId = $asaas->findOrCreateCustomer([
                'name'    => $this->customer->name,
                'email'   => $this->customer->email,
                'cpfCnpj' => $this->customer->tax_id ?? '',
                'phone'   => $this->customer->phone ?? null,
            ]);

            $charge = $asaas->createCreditCardCharge(
                customerId:        $asaasCustomerId,
                amount:            $chargeAmount,
                description:       "Pedido #{$this->order->order_number} - {$companyName}",
                externalReference: (string) $this->order->id,
                creditCard: [
                    'holderName'  => $this->cardData['holderName'],
                    'number'      => $this->cardData['number'],
                    'expiryMonth' => $this->cardData['expiryMonth'],
                    'expiryYear'  => $this->cardData['expiryYear'],
                    'ccv'         => $this->cardData['ccv'],
                ],
                holderInfo: [
                    'name'          => $this->customer->name,
                    'email'         => $this->customer->email,
                    'cpfCnpj'       => $this->cardData['cpfCnpj'] ?? $this->customer->tax_id ?? '',
                    'postalCode'    => $this->cardData['postalCode'] ?? '',
                    'addressNumber' => $this->cardData['addressNumber'] ?? 'S/N',
                    'phone'         => $this->customer->phone ?? null,
                ],
                installments: $this->installments,
            );

            Payment::create([
                'order_id'          => $this->order->id,
                'asaas_payment_id'  => $charge['id'],
                'payment_gateway'   => 'asaas',
                'amount'            => $chargeAmount,
                'original_amount'   => $breakdown['original_amount'],
                'card_fee'          => $feeAmount,
                'card_fee_rate'     => $breakdown['total_rate'],
                'installments'      => $this->installments,
                'anticipation_days' => $anticipationDays,
                'status'            => 'pending',
                'payment_token'     => hash('sha256', $this->order->id . $this->customer->id . Str::random(32)),
            ]);

            Log::channel('payments')->info('Cobrança de cartão criada no Asaas', [
                'order_id'         => $this->order->id,
                'payment_id'       => $charge['id'],
                'charge_amount'    => $chargeAmount,
                'installments'     => $this->installments,
            ]);
        } else {
            // Modo simulação (sem chave Asaas configurada)
            Payment::create([
                'order_id'          => $this->order->id,
                'asaas_payment_id'  => 'sim_card_' . uniqid(),
                'payment_gateway'   => 'asaas',
                'amount'            => $chargeAmount,
                'original_amount'   => $breakdown['original_amount'],
                'card_fee'          => $feeAmount,
                'card_fee_rate'     => $breakdown['total_rate'],
                'installments'      => $this->installments,
                'anticipation_days' => $anticipationDays,
                'status'            => 'pending',
                'payment_token'     => hash('sha256', $this->order->id . $this->customer->id . Str::random(32)),
            ]);

            Log::channel('payments')->info('Cobrança de cartão simulada (sem chave Asaas)', [
                'order_id'        => $this->order->id,
                'charge_amount'   => $chargeAmount,
                'installments'    => $this->installments,
            ]);
        }
    }

    private function processPix(AsaasService $asaas, ?string $apiKey): void
    {
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

            $companyName    = $this->company?->name ?? config('app.name');
            $feePercentage  = $this->company?->plan?->feePercentage() ?? 0.0;
            $pixFeeAbsorbed = $this->company?->pix_fee_absorbed_by_company ?? false;
            $pixFee         = config('payments.pix_payment_fee', self::PIX_FEE);
            $chargeAmount   = (float) $this->order->total + ($pixFeeAbsorbed ? 0 : $pixFee);

            $charge = $asaas->createOrderCharge(
                $asaasCustomerId,
                $chargeAmount,
                "Pedido #{$this->order->order_number} - {$companyName}",
                (string) $this->order->id,
                $feePercentage,
            );

            $pixQrCode = $asaas->getPaymentPixQrCode($charge['id']);

            Payment::create([
                'order_id'         => $this->order->id,
                'asaas_payment_id' => $charge['id'],
                'payment_gateway'  => 'asaas',
                'pix_qr_code'      => $pixQrCode['encodedImage'],
                'pix_copy_paste'   => $pixQrCode['payload'],
                'amount'           => $this->order->total,
                'pix_fee'          => $pixFee,
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

            $simPixFee = config('payments.pix_payment_fee', self::PIX_FEE);

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
                'pix_fee'         => $simPixFee,
                'status'          => 'pending',
                'expires_at'      => now()->addMinutes(30),
                'payment_token'   => hash('sha256', $this->order->id . $this->customer->id . Str::random(32)),
            ]);
        }
    }
}
