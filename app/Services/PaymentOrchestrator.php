<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentOrchestrator
{
    public function __construct(
        private readonly StarkService $stark,
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
     *   "method":     "pix" | "credit_card" | "subscription",
     *   "gateway":    "stark" | "asaas",
     *   "qr_code":    "...",   // só PIX
     *   "copy_paste": "...",   // só PIX
     * }
     */
    public function process(
        Order $order,
        Customer $customer,
        Company $company,
        string $method
    ): array {
        return match (strtoupper($method)) {
            'CREDIT_CARD' => $this->processCreditCard($order, $customer, $company),
            default => $this->processPix($order, $customer, $company),
        };
    }

    /**
     * Processa PIX via Stark Bank e persiste o Payment.
     */
    public function processPix(
        Order $order,
        Customer $customer,
        Company $company
    ): array {
        $pixFee = (float) config('payments.stark_pix_fee', 0.50);
        $pixFeeAbsorbed = $company->pix_fee_absorbed_by_company ?? false;
        $chargeAmount = (float) $order->total + ($pixFeeAbsorbed ? 0 : $pixFee);
        $companyName = $company->name ?? config('app.name');

        Log::channel('payments')->info('Orchestrator: criando cobrança PIX via Stark Bank', [
            'order_id' => $order->id,
            'charge_amount' => $chargeAmount,
            'pix_fee' => $pixFee,
            'fee_absorbed' => $pixFeeAbsorbed,
        ]);

        $apiKey = config('services.stark.project_id');

        if ($apiKey) {
            $result = $this->stark->createPixCharge(
                amount: $chargeAmount,
                description: "Pedido #{$order->order_number} - {$companyName}",
                externalRef: (string) $order->id,
                customer: $customer,
            );

            $payment = Payment::create([
                'order_id' => $order->id,
                'stark_payment_id' => $result['id'],
                'payment_gateway' => 'stark',
                'pix_qr_code' => $result['qr_code_url'],
                'pix_copy_paste' => $result['brcode'],
                'amount' => $chargeAmount,
                'pix_fee' => $pixFee,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(30),
                'payment_token' => hash('sha256', $order->id.$customer->id.Str::random(32)),
            ]);

            Log::channel('payments')->info('PIX Stark Bank criado', [
                'order_id' => $order->id,
                'stark_payment_id' => $result['id'],
                'charge_amount' => $chargeAmount,
            ]);
        } else {
            // Modo simulação (sem credenciais Stark configuradas)
            $payment = Payment::create([
                'order_id' => $order->id,
                'stark_payment_id' => 'sim_stark_'.uniqid(),
                'payment_gateway' => 'stark',
                'pix_qr_code' => null,
                'pix_copy_paste' => '00020126580014br.gov.bcb.pix0136SIMULACAO-STARK-BANK52040000530398654'
                    .number_format($chargeAmount, 2, '', '')
                    .'5802BR5924'
                    .mb_substr(preg_replace('/[^A-Z0-9 ]/', '', strtoupper($companyName)), 0, 25)
                    .'6009SAO PAULO62070503***6304ABCD',
                'amount' => $chargeAmount,
                'pix_fee' => $pixFee,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(30),
                'payment_token' => hash('sha256', $order->id.$customer->id.Str::random(32)),
            ]);

            Log::channel('payments')->info('PIX Stark simulado (sem credenciais)', [
                'order_id' => $order->id,
            ]);
        }

        return [
            'id' => $payment->stark_payment_id,
            'status' => 'pending',
            'method' => 'pix',
            'gateway' => 'stark',
            'qr_code' => $payment->pix_qr_code,
            'copy_paste' => $payment->pix_copy_paste,
        ];
    }

    /**
     * Cartão de crédito permanece no Asaas.
     * Este método não cria o Payment — apenas retorna o gateway correto.
     * O ProcessOrder mantém a lógica completa de cartão internamente.
     */
    private function processCreditCard(Order $order, Customer $customer, Company $company): array
    {
        return [
            'id' => null,
            'status' => 'pending',
            'method' => 'credit_card',
            'gateway' => 'asaas',
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
    public function calculateFees(float $amount, string $method, Company $company): array
    {
        $gatewayFee = match (strtolower($method)) {
            'pix' => (float) config('payments.stark_pix_fee', 0.50),
            'credit_card' => round($amount * 0.04, 2),
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
