<?php

namespace App\Services;

use App\Enums\Plan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AsaasService
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = config('services.asaas.api_key', '');
        $this->baseUrl = config('services.asaas.base_url', 'https://sandbox.asaas.com/api/v3');
    }

    /**
     * Create a customer in Asaas.
     *
     * @param  array{name: string, email: string, cpfCnpj: string, phone?: string} $data
     * @return string Asaas customer ID
     */
    public function createCustomer(array $data): string
    {
        Log::channel('payments')->info('Criando cliente no Asaas', ['email' => $data['email']]);

        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->timeout(15)
            ->post("{$this->baseUrl}/customers", array_filter([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'cpfCnpj'  => preg_replace('/\D/', '', $data['cpfCnpj']),
                'mobilePhone' => $data['phone'] ?? null,
            ], fn ($v) => $v !== null));

        if ($response->failed()) {
            Log::channel('payments')->error('Erro ao criar cliente no Asaas', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new RuntimeException('Asaas API error: ' . $response->body(), $response->status());
        }

        $customerId = $response->json('id');

        Log::channel('payments')->info('Cliente criado no Asaas', ['customer_id' => $customerId]);

        return $customerId;
    }

    /**
     * Create a monthly subscription in Asaas for the given plan.
     *
     * @param  array|null $creditCard       Card data (holderName, number, expiryMonth, expiryYear, ccv)
     * @param  array|null $holderInfo       Holder info (name, email, cpfCnpj, postalCode, addressNumber, phone)
     * @param  string|null $nextDueDate     ISO date string; defaults to tomorrow
     * @return array{id: string, status: string, nextDueDate: string, value: float}
     */
    public function createSubscription(
        string $customerId,
        Plan $plan,
        string $billingType = 'PIX',
        ?array $creditCard = null,
        ?array $holderInfo = null,
        ?string $nextDueDate = null,
    ): array {
        $amount = $plan->monthlyPrice();

        Log::channel('payments')->info('Criando assinatura no Asaas', [
            'customer_id'  => $customerId,
            'plan'         => $plan->value,
            'amount'       => $amount,
            'billing_type' => $billingType,
        ]);

        $payload = [
            'customer'    => $customerId,
            'billingType' => $billingType,
            'value'       => $amount,
            'nextDueDate' => $nextDueDate ?? now()->addDay()->toDateString(),
            'cycle'       => 'MONTHLY',
            'description' => $plan->asaasDescription(),
        ];

        if ($creditCard !== null) {
            $payload['creditCard'] = [
                'holderName'  => strtoupper($creditCard['holderName']),
                'number'      => preg_replace('/\D/', '', $creditCard['number']),
                'expiryMonth' => $creditCard['expiryMonth'],
                'expiryYear'  => $creditCard['expiryYear'],
                'ccv'         => $creditCard['ccv'],
            ];
        }

        if ($holderInfo !== null) {
            $payload['creditCardHolderInfo'] = array_filter([
                'name'          => $holderInfo['name'],
                'email'         => $holderInfo['email'],
                'cpfCnpj'       => preg_replace('/\D/', '', $holderInfo['cpfCnpj'] ?? ''),
                'postalCode'    => preg_replace('/\D/', '', $holderInfo['postalCode'] ?? ''),
                'addressNumber' => $holderInfo['addressNumber'] ?? 'S/N',
                'mobilePhone'   => $holderInfo['mobilePhone'] ?? $holderInfo['phone'] ?? null,
                'phone'         => $holderInfo['phone'] ?? $holderInfo['mobilePhone'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');
        }

        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->timeout(15)
            ->post("{$this->baseUrl}/subscriptions", $payload);

        if ($response->failed()) {
            Log::channel('payments')->error('Erro ao criar assinatura no Asaas', [
                'customer_id' => $customerId,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new RuntimeException('Asaas subscription error: ' . $response->body(), $response->status());
        }

        $data = $response->json();

        Log::channel('payments')->info('Assinatura criada no Asaas', ['subscription_id' => $data['id']]);

        return [
            'id'          => $data['id'],
            'status'      => $data['status'],
            'nextDueDate' => $data['nextDueDate'] ?? now()->addDay()->toDateString(),
            'value'       => $data['value'] ?? $amount,
        ];
    }

    /**
     * Fetch payments (cobranças) for a given subscription from Asaas.
     *
     * @return array<int, array{id: string, status: string, value: float, dueDate: string, invoiceUrl: string|null}>
     */
    public function getSubscriptionPayments(string $subscriptionId): array
    {
        try {
            $response = Http::withHeaders(['access_token' => $this->apiKey])
                ->timeout(10)
                ->get("{$this->baseUrl}/subscriptions/{$subscriptionId}/payments", [
                    'limit' => 12,
                ]);

            if ($response->failed()) {
                Log::channel('payments')->warning('Falha ao buscar cobranças Asaas', [
                    'subscription_id' => $subscriptionId,
                    'status'          => $response->status(),
                ]);

                return [];
            }

            return $response->json('data', []);
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('Exceção ao buscar cobranças Asaas', [
                'subscription_id' => $subscriptionId,
                'error'           => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Cancel (delete) a subscription in Asaas.
     */
    public function cancelSubscription(string $subscriptionId): void
    {
        Log::channel('payments')->info('Cancelando assinatura no Asaas', ['subscription_id' => $subscriptionId]);

        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->timeout(15)
            ->delete("{$this->baseUrl}/subscriptions/{$subscriptionId}");

        if ($response->failed()) {
            Log::channel('payments')->error('Erro ao cancelar assinatura no Asaas', [
                'subscription_id' => $subscriptionId,
                'status'          => $response->status(),
                'body'            => $response->body(),
            ]);
            throw new RuntimeException('Asaas cancel error: ' . $response->body(), $response->status());
        }

        Log::channel('payments')->info('Assinatura cancelada no Asaas', ['subscription_id' => $subscriptionId]);
    }

    /**
     * Create a one-time charge (cobrança avulsa) for a customer in Asaas.
     *
     * @return array Asaas payment object
     */
    public function createCharge(string $customerId, float $amount, string $description, string $billingType = 'PIX'): array
    {
        Log::channel('payments')->info('Criando cobrança avulsa no Asaas', [
            'customer_id'  => $customerId,
            'amount'       => $amount,
            'description'  => $description,
            'billing_type' => $billingType,
        ]);

        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->timeout(15)
            ->post("{$this->baseUrl}/payments", [
                'customer'    => $customerId,
                'billingType' => $billingType,
                'value'       => $amount,
                'dueDate'     => now()->addDays(3)->toDateString(),
                'description' => $description,
            ]);

        if ($response->failed()) {
            Log::channel('payments')->error('Erro ao criar cobrança avulsa no Asaas', [
                'customer_id' => $customerId,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new RuntimeException('Asaas charge error: ' . $response->body(), $response->status());
        }

        $data = $response->json();

        Log::channel('payments')->info('Cobrança avulsa criada no Asaas', [
            'customer_id' => $customerId,
            'payment_id'  => $data['id'] ?? null,
            'amount'      => $amount,
        ]);

        return $data;
    }

    /**
     * Create a credit card charge (à vista) for an order.
     *
     * @param  array{holderName: string, number: string, expiryMonth: string, expiryYear: string, ccv: string} $creditCard
     * @param  array{name: string, email: string, cpfCnpj: string, postalCode: string, addressNumber: string, phone?: string} $holderInfo
     * @return array Asaas payment object — check ['status'] for CONFIRMED or DECLINED, ['declineReason'] on failure
     */
    public function createCreditCardCharge(
        string $customerId,
        float $amount,
        string $description,
        string $externalReference,
        array $creditCard,
        array $holderInfo
    ): array {
        Log::channel('payments')->info('Criando cobrança de cartão de crédito no Asaas', [
            'customer_id'        => $customerId,
            'amount'             => $amount,
            'external_reference' => $externalReference,
        ]);

        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->timeout(30)
            ->post("{$this->baseUrl}/payments", [
                'customer'          => $customerId,
                'billingType'       => 'CREDIT_CARD',
                'value'             => $amount,
                'dueDate'           => now()->toDateString(),
                'description'       => $description,
                'externalReference' => $externalReference,
                'creditCard'        => [
                    'holderName'  => strtoupper($creditCard['holderName']),
                    'number'      => preg_replace('/\D/', '', $creditCard['number']),
                    'expiryMonth' => $creditCard['expiryMonth'],
                    'expiryYear'  => $creditCard['expiryYear'],
                    'ccv'         => $creditCard['ccv'],
                ],
                'creditCardHolderInfo' => array_filter([
                    'name'          => $holderInfo['name'],
                    'email'         => $holderInfo['email'],
                    'cpfCnpj'       => preg_replace('/\D/', '', $holderInfo['cpfCnpj'] ?? ''),
                    'postalCode'    => preg_replace('/\D/', '', $holderInfo['postalCode'] ?? ''),
                    'addressNumber' => $holderInfo['addressNumber'] ?? 'S/N',
                    'mobilePhone'   => $holderInfo['mobilePhone'] ?? $holderInfo['phone'] ?? null,
                    'phone'         => $holderInfo['phone'] ?? $holderInfo['mobilePhone'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''),
            ]);

        $data = $response->json();

        if ($response->failed()) {
            $errors = collect($data['errors'] ?? [])->pluck('description')->implode('; ');
            Log::channel('payments')->error('Erro ao criar cobrança de cartão no Asaas', [
                'customer_id' => $customerId,
                'status'      => $response->status(),
                'errors'      => $errors,
            ]);
            throw new RuntimeException('Asaas credit card error: ' . ($errors ?: $response->body()), $response->status());
        }

        Log::channel('payments')->info('Cobrança de cartão processada no Asaas', [
            'payment_id'         => $data['id'] ?? null,
            'status'             => $data['status'] ?? null,
            'decline_reason'     => $data['creditCard']['declineReason'] ?? null,
            'external_reference' => $externalReference,
        ]);

        return $data;
    }

    /**
     * Find a customer in Asaas by CPF/CNPJ; create if not found.
     *
     * @param  array{name: string, email: string, cpfCnpj: string, phone?: string} $data
     * @return string Asaas customer ID
     */
    public function findOrCreateCustomer(array $data): string
    {
        $cpfCnpj = preg_replace('/\D/', '', $data['cpfCnpj'] ?? '');

        if ($cpfCnpj) {
            try {
                $response = Http::withHeaders(['access_token' => $this->apiKey])
                    ->timeout(10)
                    ->get("{$this->baseUrl}/customers", ['cpfCnpj' => $cpfCnpj, 'limit' => 1]);

                if ($response->successful()) {
                    $existing = $response->json('data.0.id');
                    if ($existing) {
                        return $existing;
                    }
                }
            } catch (\Throwable) {
                // fall through to create
            }
        }

        return $this->createCustomer($data);
    }

    /**
     * Create a one-time PIX charge for an order (end customer payment).
     *
     * @return array Asaas payment object with pixTransaction data
     */
    public function createOrderCharge(
        string $customerId,
        float $amount,
        string $description,
        string $externalReference,
        float $feePercentage = 0.0
    ): array {
        Log::channel('payments')->info('Criando cobrança de pedido no Asaas', [
            'customer_id'        => $customerId,
            'amount'             => $amount,
            'external_reference' => $externalReference,
            'fee_percentage'     => $feePercentage,
        ]);

        $payload = [
            'customer'          => $customerId,
            'billingType'       => 'PIX',
            'value'             => $amount,
            'dueDate'           => now()->addMinutes(30)->toDateString(),
            'description'       => $description,
            'externalReference' => $externalReference,
        ];

        $veddiWalletId = config('services.asaas.veddi_wallet_id');
        if ($feePercentage > 0 && $veddiWalletId) {
            $payload['split'] = [
                [
                    'walletId'        => $veddiWalletId,
                    'percentualValue' => round($feePercentage * 100, 4),
                ],
            ];
        }

        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->timeout(15)
            ->post("{$this->baseUrl}/payments", $payload);

        if ($response->failed()) {
            Log::channel('payments')->error('Erro ao criar cobrança de pedido no Asaas', [
                'customer_id' => $customerId,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new RuntimeException('Asaas order charge error: ' . $response->body(), $response->status());
        }

        $data = $response->json();

        Log::channel('payments')->info('Cobrança de pedido criada no Asaas', [
            'payment_id'         => $data['id'] ?? null,
            'external_reference' => $externalReference,
        ]);

        return $data;
    }

    /**
     * Fetch the PIX QR Code for a payment.
     * Asaas does not include pixTransaction in the charge creation response;
     * this endpoint returns encodedImage (base64) and payload (copy-paste code).
     *
     * @return array{encodedImage: string|null, payload: string|null}
     */
    public function getPaymentPixQrCode(string $paymentId): array
    {
        try {
            $response = Http::withHeaders(['access_token' => $this->apiKey])
                ->timeout(10)
                ->get("{$this->baseUrl}/payments/{$paymentId}/pixQrCode");

            if ($response->failed()) {
                Log::channel('payments')->warning('Falha ao buscar QR Code PIX do Asaas', [
                    'payment_id' => $paymentId,
                    'status'     => $response->status(),
                ]);
                return ['encodedImage' => null, 'payload' => null];
            }

            return [
                'encodedImage' => $response->json('encodedImage'),
                'payload'      => $response->json('payload'),
            ];
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('Exceção ao buscar QR Code PIX do Asaas', [
                'payment_id' => $paymentId,
                'error'      => $e->getMessage(),
            ]);
            return ['encodedImage' => null, 'payload' => null];
        }
    }

    /**
     * Transfer funds from the platform Asaas account to a company (PIX or TED).
     *
     * For PIX: $data = ['value', 'operationType' => 'PIX', 'pixAddressKey', 'pixAddressKeyType', 'description']
     * For TED: $data = ['value', 'operationType' => 'TED', 'bankAccount' => [...], 'description']
     *
     * @return array Asaas transfer object
     */
    public function createTransfer(array $data): array
    {
        Log::channel('payments')->info('Criando transferência no Asaas', [
            'value'         => $data['value'] ?? null,
            'operation'     => $data['operationType'] ?? null,
        ]);

        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->timeout(15)
            ->post("{$this->baseUrl}/transfers", $data);

        if ($response->failed()) {
            Log::channel('payments')->error('Erro ao criar transferência no Asaas', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new RuntimeException('Asaas transfer error: ' . $response->body(), $response->status());
        }

        $transfer = $response->json();

        Log::channel('payments')->info('Transferência criada no Asaas', [
            'transfer_id' => $transfer['id'] ?? null,
            'value'       => $data['value'] ?? null,
        ]);

        return $transfer;
    }

    /**
     * Request a refund for a payment in Asaas.
     * For PIX: funds are returned to the customer's PIX key.
     * For credit card: the charge is reversed.
     *
     * @return array Asaas payment object with updated status
     */
    public function refundPayment(string $asaasPaymentId, ?float $value = null): array
    {
        Log::channel('payments')->info('Solicitando reembolso no Asaas', [
            'asaas_payment_id' => $asaasPaymentId,
            'value'            => $value,
        ]);

        $body = $value !== null ? ['value' => $value] : [];

        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->timeout(15)
            ->post("{$this->baseUrl}/payments/{$asaasPaymentId}/refund", $body);

        if ($response->failed()) {
            Log::channel('payments')->error('Erro ao solicitar reembolso no Asaas', [
                'asaas_payment_id' => $asaasPaymentId,
                'status'           => $response->status(),
                'body'             => $response->body(),
            ]);
            throw new RuntimeException('Asaas refund error: ' . $response->body(), $response->status());
        }

        $data = $response->json();

        Log::channel('payments')->info('Reembolso solicitado no Asaas', [
            'asaas_payment_id' => $asaasPaymentId,
            'result_status'    => $data['status'] ?? null,
        ]);

        return $data;
    }

    /**
     * Validate the webhook access token sent by Asaas in the request header.
     */
    public function validateWebhookToken(string $token): bool
    {
        $expected = config('services.asaas.webhook_token');

        if (! $expected) {
            return false;
        }

        return hash_equals($expected, $token);
    }
}
