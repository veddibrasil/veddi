<?php

namespace App\Jobs;

use App\Contracts\AsaasServiceInterface;
use App\DTOs\AsaasCustomerDTO;
use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\Events\OrderStatusUpdated;
use App\Exceptions\AsaasCircuitOpenException;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\PaymentCalculatorService;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessOrder implements ShouldQueue
{
    use Queueable;

    public array $backoff = [30, 120, 600];

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    const PIX_FEE = 0.50;

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

    public function handle(AsaasServiceInterface $asaas, PaymentOrchestrator $orchestrator): void
    {
        Log::channel('orders')->info('Processando pedido', [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'customer_id' => $this->customer->id,
            'payment_method' => $this->paymentMethod,
            'total' => $this->order->total,
        ]);

        try {
            $apiKey = config('services.asaas.api_key');

            if ($this->paymentMethod === 'CREDIT_CARD') {
                $this->processCreditCard($asaas, $apiKey);
            } else {
                $this->processPix($orchestrator);
            }

            $this->order->update(['status' => 'awaiting_payment']);

            Log::channel('orders')->info('Pedido aguardando pagamento', [
                'order_id' => $this->order->id,
                'order_number' => $this->order->order_number,
            ]);

            // Notifica o componente Livewire via Reverb para carregar os dados de pagamento
            OrderStatusUpdated::dispatch($this->order->fresh());
        } catch (AsaasCircuitOpenException $e) {
            Log::channel('payments')->warning('Circuit aberto — ProcessOrder adiado 15 min', [
                'order_id' => $this->order->id,
            ]);
            $this->release(900);

            return;
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

    private function processCreditCard(AsaasServiceInterface $asaas, ?string $apiKey): void
    {
        $settings = $this->company?->paymentSettings;
        $calculator = app(PaymentCalculatorService::class);

        $anticipationDays = $settings?->default_anticipation_days ?? 15;
        $cardFeeAbsorbed = $this->company?->card_fee_absorbed_by_company ?? false;
        $breakdown = $calculator->calculate(
            (float) $this->order->total,
            $anticipationDays,
            $settings
        );

        // Quando a empresa absorve a taxa, cobra o valor original do pedido ao cliente.
        // A taxa é calculada diretamente (sem a fórmula de repasse), pois o Asaas
        // irá deduzir sua taxa do valor recebido.
        if ($cardFeeAbsorbed) {
            $chargeAmount = (float) $this->order->total;
            $feeAmount = round($chargeAmount * $breakdown['total_rate'], 2);
        } else {
            $chargeAmount = $breakdown['final_amount'];
            $feeAmount = $breakdown['fee_amount'];
        }

        Log::channel('payments')->info('Calculando taxa de cartão', [
            'order_id' => $this->order->id,
            'original_amount' => $breakdown['original_amount'],
            'charge_amount' => $chargeAmount,
            'fee_amount' => $feeAmount,
            'total_rate_pct' => round($breakdown['total_rate'] * 100, 2).'%',
            'installments' => $this->installments,
            'anticipation_days' => $anticipationDays,
            'card_fee_absorbed' => $cardFeeAbsorbed,
        ]);

        if ($apiKey) {
            $companyName = $this->company?->name ?? config('app.name');

            $asaasCustomerId = $asaas->findOrCreateCustomer(new AsaasCustomerDTO(
                name: $this->customer->name,
                email: $this->customer->email,
                cpfCnpj: $this->customer->tax_id ?? '',
                phone: $this->customer->phone ?? null,
            ));

            $charge = $asaas->createCreditCardCharge(
                customerId: $asaasCustomerId,
                amount: $chargeAmount,
                description: "Pedido #{$this->order->order_number} - {$companyName}",
                externalReference: (string) $this->order->id,
                creditCard: new CreditCardDTO(
                    holderName: $this->cardData['holderName'],
                    number: $this->cardData['number'],
                    expiryMonth: $this->cardData['expiryMonth'],
                    expiryYear: $this->cardData['expiryYear'],
                    ccv: $this->cardData['ccv'],
                ),
                holderInfo: new CreditCardHolderDTO(
                    name: $this->customer->name,
                    email: $this->customer->email,
                    cpfCnpj: $this->cardData['cpfCnpj'] ?? $this->customer->tax_id ?? '',
                    postalCode: $this->cardData['postalCode'] ?? '',
                    addressNumber: $this->cardData['addressNumber'] ?? 'S/N',
                    phone: $this->customer->phone ?? null,
                ),
                installments: $this->installments,
            );

            Payment::create([
                'order_id' => $this->order->id,
                'asaas_payment_id' => $charge['id'],
                'payment_gateway' => 'asaas',
                'amount' => $chargeAmount,
                'original_amount' => $breakdown['original_amount'],
                'card_fee' => $feeAmount,
                'card_fee_rate' => $breakdown['total_rate'],
                'installments' => $this->installments,
                'anticipation_days' => $anticipationDays,
                'status' => 'pending',
                'payment_token' => hash('sha256', $this->order->id.$this->customer->id.Str::random(32)),
            ]);

            Log::channel('payments')->info('Cobrança de cartão criada no Asaas', [
                'order_id' => $this->order->id,
                'payment_id' => $charge['id'],
                'charge_amount' => $chargeAmount,
                'installments' => $this->installments,
            ]);
        } else {
            // Modo simulação (sem chave Asaas configurada)
            Payment::create([
                'order_id' => $this->order->id,
                'asaas_payment_id' => 'sim_card_'.uniqid(),
                'payment_gateway' => 'asaas',
                'amount' => $chargeAmount,
                'original_amount' => $breakdown['original_amount'],
                'card_fee' => $feeAmount,
                'card_fee_rate' => $breakdown['total_rate'],
                'installments' => $this->installments,
                'anticipation_days' => $anticipationDays,
                'status' => 'pending',
                'payment_token' => hash('sha256', $this->order->id.$this->customer->id.Str::random(32)),
            ]);

            Log::channel('payments')->info('Cobrança de cartão simulada (sem chave Asaas)', [
                'order_id' => $this->order->id,
                'charge_amount' => $chargeAmount,
                'installments' => $this->installments,
            ]);
        }
    }

    private function processPix(PaymentOrchestrator $orchestrator): void
    {
        // PIX é processado via Stark Bank através do PaymentOrchestrator.
        // O orchestrator cria o Payment e retorna os dados para exibição.
        $orchestrator->processPix(
            order: $this->order,
            customer: $this->customer,
            company: $this->company,
        );
    }
}
