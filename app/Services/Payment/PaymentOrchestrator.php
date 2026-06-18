<?php

namespace App\Services\Payment;

use App\Contracts\TransactionServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\Enums\CardBrand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
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

        $gatewayRate = (float) config('payments.vindi_pix_rate', 0.0085);
        $platformRate = (float) config('payments.vindi_pix_platform_rate', 0.0014);
        $planExtraRate = $company->feePercentageForOrder($order);


        // Same rule as card: platform commission (1%/3%) applies to the actual
        // net received after gateway fees — not the inflated charge — with
        // delivery fee carved out first.
        $netAfterGateway = round($chargeAmount * (1.0 - $gatewayRate - $platformRate), 3);
        $deliveryFee = (float) ($order->delivery_fee ?? 0);
        $platformFeeBase = round($netAfterGateway - $deliveryFee, 3);
        $commissionAmount = round($platformFeeBase * $planExtraRate, 3);
        $affiliateAmount = round($netAfterGateway - $commissionAmount, 3);
        $affiliatePercentual = $chargeAmount > 0
            ? round($affiliateAmount / $chargeAmount * 100, 4)
            : round((1.0 - $gatewayRate - $platformRate - $planExtraRate) * 100, 4);

        Log::channel('payments')->info('Orchestrator: criando cobrança PIX via Vindi', [
            'order_id' => $order->id,
            'subtotal' => $order->subtotal,
            'delivery_fee' => $order->delivery_fee,
            'charge_amount' => $chargeAmount,
            'commission_amount' => $commissionAmount,
            'platform_fee_base' => $platformFeeBase,
            'affiliate_percentual' => $affiliatePercentual,
            'has_affiliate' => (bool) $affiliateEmail,
            'gateway_rate_pct' => round($gatewayRate * 100, 3).'%',
            'platform_rate_pct' => round($platformRate * 100, 3).'%',
            'plan_extra_rate_pct' => round($planExtraRate * 100, 3).'%',
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
                deliveryFee: (float) ($order->delivery_fee ?? 0),
            );

            $payment = Payment::create([
                'order_id' => $order->id,
                'vindi_transaction_token' => $result['transaction_token'],
                'vindi_transaction_id' => $result['transaction_id'] ?? null,
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
     * Processa cartão de crédito via Vindi Intermediador, persiste Payment e, se aprovado,
     * credita carteira e registra transação.
     *
     * @return array{id: string|null, approved: bool, status: string, method: string, gateway: string}
     */
    public function processCreditCard(
        Order $order,
        Customer $customer,
        Company $company,
        array $cardData,
        int $installments = 1,
    ): array {
        $affiliateEmail = $company->email;

        if (! $affiliateEmail && config('app.env') === 'production') {
            Log::channel('discord')->critical('Empresa sem email em produção — pagamento cartão sem split de afiliado', [
                'type' => 'payments',
                'company_id' => $company->id,
                'order_id' => $order->id,
                'amount' => (float) $order->total,
            ]);
        }

        $brand = CardBrand::fromNumber($cardData['number'] ?? '');
        $cardRate = $brand->rate();
        $cardFeeAbsorbed = (bool) ($company->card_fee_absorbed_by_company ?? false);

        // Fee not absorbed: inflate charge so customer pays the card fee
        // Fee absorbed: company takes the hit from their net
        $chargeAmount = (! $cardFeeAbsorbed && $cardRate < 1.0)
            ? round((float) $order->total / (1.0 - $cardRate), 3)
            : (float) $order->total;

        $cardFee = round($chargeAmount * $cardRate, 3);
        $planFeeRate = $company->feePercentageForOrder($order);

        // Platform commission (1%/3%) is applied straight to the actual amount received
        // after the card fee — not inflated by it — with delivery fee carved out first.
        $netAfterCard = round($chargeAmount - $cardFee, 3);
        $deliveryFee = (float) ($order->delivery_fee ?? 0);
        $platformFeeBase = round($netAfterCard - $deliveryFee, 3);
        $platformFeeAmount = round($platformFeeBase * $planFeeRate, 3);
        $targetCompanyNet = round($netAfterCard - $platformFeeAmount, 3);
        $affiliatePercentual = $chargeAmount > 0
            ? round($targetCompanyNet / $chargeAmount * 100, 4)
            : round((1.0 - $planFeeRate) * 100, 4);

        Log::channel('payments')->info('Orchestrator: criando cobrança cartão via Vindi', [
            'order_id' => $order->id,
            'original_amount' => $order->total,
            'charge_amount' => $chargeAmount,
            'installments' => $installments,
            'card_brand' => $brand->value,
            'card_rate_pct' => round($cardRate * 100, 3).'%',
            'card_fee_absorbed' => $cardFeeAbsorbed,
            'plan_fee_pct' => round($planFeeRate * 100, 3).'%',
            'platform_fee_base' => $platformFeeBase,
            'affiliate_percentual' => $affiliatePercentual,
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
                    token: $cardData['token'] ?? null,
                ),
                holder: new CreditCardHolderDTO(
                    name: $customer->name,
                    email: $customer->email ?? '',
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
                company: $company,
                deliveryFee: (float) ($order->delivery_fee ?? 0),
            );

            $transactionToken = $result['transaction_token'];
            $approved = ($result['status_name'] ?? '') === 'Aprovada';
            $paymentToken = hash('sha256', $order->id.$customer->id.Str::random(32));

            $transactionId = $result['transaction_id'] ?? null;

            DB::transaction(function () use ($order, $transactionToken, $transactionId, $chargeAmount, $cardFee, $cardRate, $installments, $paymentToken, $approved) {
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'vindi_transaction_token' => $transactionToken,
                    'vindi_transaction_id' => $transactionId,
                    'payment_gateway' => 'vindi',
                    'amount' => $chargeAmount,
                    'original_amount' => (float) $order->total,
                    'card_fee' => $cardFee,
                    'card_fee_rate' => $cardRate,
                    'installments' => $installments,
                    'status' => $approved ? 'paid' : 'pending',
                    'paid_at' => $approved ? now() : null,
                    'payment_token' => $paymentToken,
                ]);

                if ($approved) {
                    $order->update(['status' => 'paid']);
                    $fresh = $order->fresh();
                    app(WalletServiceInterface::class)->creditForOrder($fresh, $payment);
                    app(TransactionServiceInterface::class)->createForPayment($fresh, $payment);
                }
            });

            Log::channel('payments')->info('Cartão Vindi criado', [
                'order_id' => $order->id,
                'vindi_transaction_token' => $transactionToken,
                'charge_amount' => $chargeAmount,
                'installments' => $installments,
                'approved' => $approved,
            ]);

            return [
                'id' => $transactionToken,
                'approved' => $approved,
                'status' => $approved ? 'paid' : 'pending',
                'method' => 'credit_card',
                'gateway' => 'vindi',
            ];
        }

        return [
            'id' => null,
            'approved' => false,
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

    // ─────────────────────────────────────────────────────────────
    // Cálculo de taxas
    // ─────────────────────────────────────────────────────────────

    /**
     * Calcula as taxas de gateway e plataforma por método de pagamento.
     *
     * @return array{gateway_fee: float, platform_fee: float, net_amount: float}
     */
    public function calculateFees(float $amount, string $method, Company $company, int $installments = 1, string $cardNumber = ''): array
    {
        $gatewayFee = match (strtolower($method)) {
            'pix' => round($amount * (float) config('payments.vindi_pix_rate', 0.0085), 2),
            'credit_card' => round($amount * $this->resolveCardRate($cardNumber), 2),
            default => 0.0,
        };

        $platformFee = round($amount * $company->feePercentageForOrder(), 2);

        return [
            'gateway_fee' => $gatewayFee,
            'platform_fee' => $platformFee,
            'net_amount' => round($amount - $gatewayFee - $platformFee, 2),
        ];
    }

    private function resolveCardRate(string $cardNumber): float
    {
        return CardBrand::fromNumber($cardNumber)->rate();
    }
}
