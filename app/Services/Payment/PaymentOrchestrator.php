<?php

namespace App\Services\Payment;

use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentOrchestrator
{
    public function __construct(
        private readonly VindiService $vindi,
    ) {}

    // ─────────────────────────────────────────────────────────────
    // Roteamento principal
    // ─────────────────────────────────────────────────────────────

    /**
     * Decide qual gateway usar com base no método e cria a cobrança.
     *
     * Retorna resposta padronizada:
     * {
     *   "id":         "...",
     *   "status":     "pending",
     *   "method":     "pix" | "credit_card" | "cash",
     *   "gateway":    "vindi" | "cash" | "card_machine",
     *   "qr_code":    "...",   // só PIX
     *   "copy_paste": "...",   // só PIX
     * }
     */
    public function process(
        Order $order,
        Customer $customer,
        Company $company,
        string $method,
        array $cardData = [],
        int $installments = 1,
    ): array {
        return match (strtoupper($method)) {
            'CREDIT_CARD' => $this->processCreditCard($order, $customer, $company, $cardData, $installments),
            'CASH' => $this->processCash($order),
            'CARD_MACHINE' => $this->processCardMachine($order),
            default => $this->processPix($order, $customer, $company),
        };
    }

    /**
     * Processa PIX via Vindi Intermediador e persiste o Payment.
     */
    public function processPix(
        Order $order,
        Customer $customer,
        Company $company
    ): array {
        $chargeAmount = (float) $order->total;
        $affiliateEmail = $company->email;

        if (! $affiliateEmail && config('app.env') === 'production') {
            Log::channel('discord')->critical('Empresa sem email em produção — pagamento PIX sem split de afiliado', [
                'type' => 'payments',
                'company_id' => $company->id,
                'order_id' => $order->id,
                'amount' => $chargeAmount,
            ]);
        }

        // PIX split: Vindi keeps 0.85%, platform keeps 0.14% (total 0.99%), rest to company.
        // Free plan adds extra 1% platform fee on top.
        $pixTotalRate = (float) config('payments.vindi_pix_rate', 0.0085)
                      + (float) config('payments.vindi_pix_platform_rate', 0.0014);
        $planExtraRate = $company->plan?->feePercentage() ?? 0.0;
        $affiliatePercentual = round(100.0 - ($pixTotalRate * 100) - ($planExtraRate * 100), 4);

        Log::channel('payments')->info('Orchestrator: criando cobrança PIX via Vindi', [
            'order_id' => $order->id,
            'charge_amount' => $chargeAmount,
            'affiliate_percentual' => $affiliatePercentual,
            'pix_total_rate_pct' => round(($pixTotalRate + $planExtraRate) * 100, 4).'%',
            'has_affiliate' => (bool) $affiliateEmail,
        ]);

        $tokenAccount = config('payments.vindi_token_account');

        if ($tokenAccount) {
            $result = $this->vindi->createPixCharge(
                amount: $chargeAmount,
                externalRef: (string) $order->id,
                customer: $customer,
                affiliateEmail: $affiliateEmail,
                affiliatePercentual: $affiliatePercentual,
                address: $this->vindiAddressFromOrder($order, $customer),
            );

            $payment = Payment::create([
                'order_id' => $order->id,
                'vindi_transaction_token' => $result['transaction_token'],
                'payment_gateway' => 'vindi',
                'pix_qr_code' => $result['pix_qr_code'],
                'pix_copy_paste' => $result['pix_copy_paste'],
                'amount' => $chargeAmount,
                'pix_fee' => 0.0,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(30),
                'payment_token' => hash('sha256', $order->id.$customer->id.Str::random(32)),
            ]);

            Log::channel('payments')->info('PIX Vindi criado', [
                'order_id' => $order->id,
                'vindi_transaction_token' => $result['transaction_token'],
                'charge_amount' => $chargeAmount,
            ]);
        } else {
            // Modo simulação (sem credenciais Vindi configuradas)
            $companyName = $company->name ?? config('app.name');
            $payment = Payment::create([
                'order_id' => $order->id,
                'vindi_transaction_token' => 'sim_vindi_'.uniqid(),
                'payment_gateway' => 'vindi',
                'pix_qr_code' => null,
                'pix_copy_paste' => '00020126580014br.gov.bcb.pix0136SIMULACAO-VINDI52040000530398654'
                    .number_format($chargeAmount, 2, '', '')
                    .'5802BR5924'
                    .mb_substr(preg_replace('/[^A-Z0-9 ]/', '', strtoupper($companyName)), 0, 25)
                    .'6009SAO PAULO62070503***6304ABCD',
                'amount' => $chargeAmount,
                'pix_fee' => 0.0,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(30),
                'payment_token' => hash('sha256', $order->id.$customer->id.Str::random(32)),
            ]);

            Log::channel('payments')->info('PIX Vindi simulado (sem credenciais)', [
                'order_id' => $order->id,
            ]);
        }

        return [
            'id' => $payment->vindi_transaction_token,
            'status' => 'pending',
            'method' => 'pix',
            'gateway' => 'vindi',
            'qr_code' => $payment->pix_qr_code,
            'copy_paste' => $payment->pix_copy_paste,
        ];
    }

    /**
     * Processa cartão de crédito via Vindi Intermediador e persiste o Payment.
     */
    public function processCreditCard(
        Order $order,
        Customer $customer,
        Company $company,
        array $cardData,
        int $installments = 1,
    ): array {
        $chargeAmount = (float) $order->total;
        $affiliateEmail = $company->email;

        if (! $affiliateEmail && config('app.env') === 'production') {
            Log::channel('discord')->critical('Empresa sem email em produção — pagamento cartão sem split de afiliado', [
                'type' => 'payments',
                'company_id' => $company->id,
                'order_id' => $order->id,
                'amount' => $chargeAmount,
            ]);
        }

        // Card split: gateway rate + 0.14% platform + optional 1% free plan = deducted, rest to affiliate.
        $settings = $company->paymentSettings;
        $cardGatewayRate = $this->cardRateForInstallments($installments, $settings);
        $platformRate = (float) config('payments.vindi_pix_platform_rate', 0.0014);
        $planExtraRate = $company->plan?->feePercentage() ?? 0.0;
        $affiliatePercentual = round(100.0 - ($cardGatewayRate * 100) - ($platformRate * 100) - ($planExtraRate * 100), 4);

        Log::channel('payments')->info('Orchestrator: criando cobrança cartão via Vindi', [
            'order_id' => $order->id,
            'charge_amount' => $chargeAmount,
            'installments' => $installments,
            'affiliate_percentual' => $affiliatePercentual,
            'card_gateway_rate_pct' => round($cardGatewayRate * 100, 4).'%',
            'platform_rate_pct' => round(($platformRate + $planExtraRate) * 100, 4).'%',
            'has_affiliate' => (bool) $affiliateEmail,
        ]);

        $tokenAccount = config('payments.vindi_token_account');

        if ($tokenAccount) {
            $vindiAddress = $this->vindiAddressFromOrder($order, $customer);

            $result = $this->vindi->createCreditCardCharge(
                amount: $chargeAmount,
                externalRef: (string) $order->id,
                card: new CreditCardDTO(
                    holderName: $cardData['holderName'],
                    number: $cardData['number'],
                    expiryMonth: $cardData['expiryMonth'],
                    expiryYear: $cardData['expiryYear'],
                    ccv: $cardData['ccv'],
                ),
                holder: new CreditCardHolderDTO(
                    name: $customer->name,
                    email: $customer->email,
                    cpfCnpj: $cardData['cpfCnpj'] ?? $customer->tax_id ?? '',
                    postalCode: $cardData['postalCode'] ?? $vindiAddress['postal_code'] ?? '',
                    addressNumber: $cardData['addressNumber'] ?? $vindiAddress['number'] ?? 'S/N',
                    phone: $customer->phone ?? null,
                    street: $vindiAddress['street'],
                    complement: $vindiAddress['complement'],
                    neighborhood: $vindiAddress['neighborhood'],
                    city: $vindiAddress['city'],
                    state: $vindiAddress['state'],
                ),
                installments: $installments,
                affiliateEmail: $affiliateEmail,
                affiliatePercentual: $affiliatePercentual,
            );

            Payment::create([
                'order_id' => $order->id,
                'vindi_transaction_token' => $result['transaction_token'],
                'payment_gateway' => 'vindi',
                'amount' => $chargeAmount,
                'original_amount' => $chargeAmount,
                'card_fee' => 0.0,
                'card_fee_rate' => 0.0,
                'installments' => $installments,
                'status' => 'pending',
                'payment_token' => hash('sha256', $order->id.$customer->id.Str::random(32)),
            ]);

            Log::channel('payments')->info('Cartão Vindi criado', [
                'order_id' => $order->id,
                'vindi_transaction_token' => $result['transaction_token'],
                'charge_amount' => $chargeAmount,
                'installments' => $installments,
            ]);
        } else {
            // Modo simulação
            Payment::create([
                'order_id' => $order->id,
                'vindi_transaction_token' => 'sim_card_'.uniqid(),
                'payment_gateway' => 'vindi',
                'amount' => $chargeAmount,
                'original_amount' => $chargeAmount,
                'card_fee' => 0.0,
                'card_fee_rate' => 0.0,
                'installments' => $installments,
                'status' => 'pending',
                'payment_token' => hash('sha256', $order->id.$customer->id.Str::random(32)),
            ]);

            Log::channel('payments')->info('Cartão Vindi simulado (sem credenciais)', [
                'order_id' => $order->id,
                'charge_amount' => $chargeAmount,
                'installments' => $installments,
            ]);
        }

        return [
            'id' => null,
            'status' => 'pending',
            'method' => 'credit_card',
            'gateway' => 'vindi',
        ];
    }

    /**
     * Pagamento em dinheiro (PDV). Cria Payment marcado como pago imediatamente.
     * Troco calculado com base em cash_received salvo no pedido.
     */
    public function processCash(Order $order): array
    {
        $cashReceived = (float) ($order->cash_received ?? $order->total);
        $change = max(0.0, round($cashReceived - (float) $order->total, 2));

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'cash',
            'amount' => (float) $order->total,
            'pix_fee' => 0.0,
            'status' => 'paid',
            'paid_at' => now(),
            'payment_token' => hash('sha256', 'cash'.$order->id.now()->timestamp),
        ]);

        Log::channel('payments')->info('Pagamento em dinheiro registrado (PDV)', [
            'order_id' => $order->id,
            'amount' => $order->total,
            'cash_received' => $cashReceived,
            'change' => $change,
        ]);

        return [
            'id' => $payment->id,
            'status' => 'paid',
            'method' => 'cash',
            'gateway' => 'cash',
            'change' => $change,
        ];
    }

    private function vindiAddressFromOrder(Order $order, Customer $customer): array
    {
        $branch = $order->branch;

        return [
            'street' => $order->delivery_address ?? $customer->address ?? $branch?->address,
            'number' => $order->delivery_number ?? $customer->number ?? $branch?->number,
            'complement' => $order->delivery_complement ?? $customer->complement ?? $branch?->complement,
            'neighborhood' => $order->delivery_neighborhood ?? $customer->neighborhood ?? $branch?->neighborhood,
            'city' => $order->delivery_city ?? $customer->city ?? $branch?->city,
            'state' => $customer->state ?? $branch?->state,
            'postal_code' => $order->delivery_cep ?? $customer->cep ?? $branch?->cep,
        ];
    }

    /**
     * Maquininha física no PDV — operador cobrou no terminal externo, só registra.
     */
    public function processCardMachine(Order $order): array
    {
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'card_machine',
            'amount' => (float) $order->total,
            'pix_fee' => 0.0,
            'status' => 'paid',
            'paid_at' => now(),
            'payment_token' => hash('sha256', 'card'.$order->id.now()->timestamp),
        ]);

        Log::channel('payments')->info('Pagamento em cartão (maquininha PDV) registrado', [
            'order_id' => $order->id,
            'amount' => $order->total,
        ]);

        return [
            'id' => $payment->id,
            'status' => 'paid',
            'method' => 'credit_card',
            'gateway' => 'card_machine',
        ];
    }

    private function cardRateForInstallments(int $installments, ?PaymentSettings $settings): float
    {
        if ($installments <= 1) {
            return (float) ($settings?->card_rate_1x ?? config('payments.credit_card.rate_1x', 0.0310));
        }

        if ($installments <= 6) {
            return (float) config('payments.credit_card.rate_2_6x', 0.0371);
        }

        return (float) config('payments.credit_card.rate_7_12x', 0.0407);
    }

    // ─────────────────────────────────────────────────────────────
    // Cálculo de taxas
    // ─────────────────────────────────────────────────────────────

    /**
     * Calcula as taxas de gateway e plataforma por método de pagamento.
     *
     * @return array{gateway_fee: float, platform_fee: float, net_amount: float}
     */
    public function calculateFees(float $amount, string $method, Company $company): array
    {
        $gatewayFee = match (strtolower($method)) {
            'pix' => round($amount * (float) config('payments.vindi_pix_rate', 0.0085), 2),
            'credit_card' => round($amount * (float) config('payments.credit_card.rate_1x', 0.0310), 2),
            default => 0.0,
        };

        $platformFee = round($amount * ($company->plan?->feePercentage() ?? 0.0), 2);

        return [
            'gateway_fee' => $gatewayFee,
            'platform_fee' => $platformFee,
            'net_amount' => round($amount - $gatewayFee - $platformFee, 2),
        ];
    }
}
