<?php

namespace App\Services;

use App\Contracts\AsaasServiceInterface;
use App\DTOs\AsaasCustomerDTO;
use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\Enums\Plan;
use App\Exceptions\AsaasCircuitOpenException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AsaasService implements AsaasServiceInterface
{
    private string $apiKey;

    private string $baseUrl;

    public function __construct(private AsaasCircuitBreaker $circuitBreaker)
    {
        $this->apiKey = config('services.asaas.api_key', '');
        $this->baseUrl = config('services.asaas.base_url', 'https://sandbox.asaas.com/api/v3');
    }

    /**
     * Wrapper centralizado para chamadas HTTP ao Asaas.
     * Verifica o circuit breaker antes de cada chamada e registra falhas/sucessos.
     * Apenas respostas 5xx e ConnectionException abrem o circuit.
     *
     * @throws AsaasCircuitOpenException se o circuit estiver aberto
     * @throws RuntimeException em falha de conexão
     */
    private function request(string $method, string $endpoint, array $data = [], int $timeout = 15): Response
    {
        if ($this->circuitBreaker->isOpen()) {
            throw new AsaasCircuitOpenException;
        }

        try {
            $response = Http::withHeaders(['access_token' => $this->apiKey])
                ->timeout($timeout)
                ->{$method}("{$this->baseUrl}/{$endpoint}", $data);

            // Apenas 5xx indica indisponibilidade do serviço; 4xx são erros de negócio
            if ($response->serverError()) {
                $this->circuitBreaker->recordFailure();
            } else {
                $this->circuitBreaker->recordSuccess();
            }

            return $response;
        } catch (ConnectionException $e) {
            $this->circuitBreaker->recordFailure();
            throw new RuntimeException('Asaas connection error: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Probe leve para verificar se o Asaas está respondendo.
     * Usado pelo scheduler de recovery automático.
     * Retorna true se o Asaas está acessível (qualquer status < 500).
     */
    public function probeHealth(): bool
    {
        try {
            $response = $this->request('get', 'payments', ['limit' => 1], 10);

            return ! $response->serverError();
        } catch (AsaasCircuitOpenException) {
            return false;
        }
    }

    /**
     * Create a customer in Asaas.
     *
     * @param  array{name: string, email: string, cpfCnpj: string, phone?: string}  $data
     * @return string Asaas customer ID
     */
    public function createCustomer(AsaasCustomerDTO $customer): string
    {
        Log::channel('payments')->info('Criando cliente no Asaas', ['email' => $customer->email]);

        $response = $this->request('post', 'customers', array_filter([
            'name' => $customer->name,
            'email' => $customer->email,
            'cpfCnpj' => preg_replace('/\D/', '', $customer->cpfCnpj),
            'mobilePhone' => $customer->phone,
        ], fn ($v) => $v !== null));

        if ($response->failed()) {
            Log::channel('discord')->error('Erro ao criar cliente no Asaas', [
                'type' => 'payments',
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Asaas API error: '.$response->body(), $response->status());
        }

        $customerId = $response->json('id');

        Log::channel('payments')->info('Cliente criado no Asaas', ['customer_id' => $customerId]);

        return $customerId;
    }

    /**
     * Create a monthly subscription in Asaas for the given plan.
     *
     * @param  array|null  $creditCard  Card data (holderName, number, expiryMonth, expiryYear, ccv)
     * @param  array|null  $holderInfo  Holder info (name, email, cpfCnpj, postalCode, addressNumber, phone)
     * @param  string|null  $nextDueDate  ISO date string; defaults to tomorrow
     * @return array{id: string, status: string, nextDueDate: string, value: float}
     */
    public function createSubscription(
        string $customerId,
        Plan $plan,
        string $billingType = 'PIX',
        ?CreditCardDTO $creditCard = null,
        ?CreditCardHolderDTO $holderInfo = null,
        ?string $nextDueDate = null,
    ): array {
        $amount = $plan->monthlyPrice();

        Log::channel('payments')->info('Criando assinatura no Asaas', [
            'customer_id' => $customerId,
            'plan' => $plan->value,
            'amount' => $amount,
            'billing_type' => $billingType,
        ]);

        $payload = [
            'customer' => $customerId,
            'billingType' => $billingType,
            'value' => $amount,
            'nextDueDate' => $nextDueDate ?? now()->addDay()->toDateString(),
            'cycle' => 'MONTHLY',
            'description' => $plan->asaasDescription(),
        ];

        if ($creditCard !== null) {
            $payload['creditCard'] = [
                'holderName' => strtoupper($creditCard->holderName),
                'number' => preg_replace('/\D/', '', $creditCard->number),
                'expiryMonth' => $creditCard->expiryMonth,
                'expiryYear' => $creditCard->expiryYear,
                'ccv' => $creditCard->ccv,
            ];
        }

        if ($holderInfo !== null) {
            $payload['creditCardHolderInfo'] = array_filter([
                'name' => $holderInfo->name,
                'email' => $holderInfo->email,
                'cpfCnpj' => preg_replace('/\D/', '', $holderInfo->cpfCnpj),
                'postalCode' => preg_replace('/\D/', '', $holderInfo->postalCode),
                'addressNumber' => $holderInfo->addressNumber,
                'mobilePhone' => $holderInfo->mobilePhone ?? $holderInfo->phone,
                'phone' => $holderInfo->phone ?? $holderInfo->mobilePhone,
            ], fn ($v) => $v !== null && $v !== '');
        }

        $response = $this->request('post', 'subscriptions', $payload);

        if ($response->failed()) {
            Log::channel('discord')->error('Erro ao criar assinatura no Asaas', [
                'type' => 'payments',
                'customer_id' => $customerId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Asaas subscription error: '.$response->body(), $response->status());
        }

        $data = $response->json();

        Log::channel('payments')->info('Assinatura criada no Asaas', ['subscription_id' => $data['id']]);

        return [
            'id' => $data['id'],
            'status' => $data['status'],
            'nextDueDate' => $data['nextDueDate'] ?? now()->addDay()->toDateString(),
            'value' => $data['value'] ?? $amount,
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
            $response = $this->request('get', "subscriptions/{$subscriptionId}/payments", ['limit' => 12], 10);

            if ($response->failed()) {
                Log::channel('payments')->warning('Falha ao buscar cobranças Asaas', [
                    'subscription_id' => $subscriptionId,
                    'status' => $response->status(),
                ]);

                return [];
            }

            return $response->json('data', []);
        } catch (AsaasCircuitOpenException) {
            return [];
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('Exceção ao buscar cobranças Asaas', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
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

        $response = $this->request('delete', "subscriptions/{$subscriptionId}");

        if ($response->failed()) {
            Log::channel('discord')->error('Erro ao cancelar assinatura no Asaas', [
                'type' => 'payments',
                'subscription_id' => $subscriptionId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Asaas cancel error: '.$response->body(), $response->status());
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
            'customer_id' => $customerId,
            'amount' => $amount,
            'description' => $description,
            'billing_type' => $billingType,
        ]);

        $response = $this->request('post', 'payments', [
            'customer' => $customerId,
            'billingType' => $billingType,
            'value' => $amount,
            'dueDate' => now()->addDays(3)->toDateString(),
            'description' => $description,
        ]);

        if ($response->failed()) {
            Log::channel('discord')->error('Erro ao criar cobrança avulsa no Asaas', [
                'type' => 'payments',
                'customer_id' => $customerId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Asaas charge error: '.$response->body(), $response->status());
        }

        $data = $response->json();

        Log::channel('payments')->info('Cobrança avulsa criada no Asaas', [
            'customer_id' => $customerId,
            'payment_id' => $data['id'] ?? null,
            'amount' => $amount,
        ]);

        return $data;
    }

    /**
     * Create a credit card charge (à vista) for an order.
     *
     * @param  array{holderName: string, number: string, expiryMonth: string, expiryYear: string, ccv: string}  $creditCard
     * @param  array{name: string, email: string, cpfCnpj: string, postalCode: string, addressNumber: string, phone?: string}  $holderInfo
     * @return array Asaas payment object — check ['status'] for CONFIRMED or DECLINED, ['declineReason'] on failure
     */
    public function createCreditCardCharge(
        string $customerId,
        float $amount,
        string $description,
        string $externalReference,
        CreditCardDTO $creditCard,
        CreditCardHolderDTO $holderInfo,
        int $installments = 1
    ): array {
        Log::channel('payments')->info('Criando cobrança de cartão de crédito no Asaas', [
            'customer_id' => $customerId,
            'amount' => $amount,
            'installments' => $installments,
            'external_reference' => $externalReference,
        ]);

        $payload = [
            'customer' => $customerId,
            'billingType' => 'CREDIT_CARD',
            'value' => $amount,
            'dueDate' => now()->toDateString(),
            'description' => $description,
            'externalReference' => $externalReference,
            'creditCard' => [
                'holderName' => strtoupper($creditCard->holderName),
                'number' => preg_replace('/\D/', '', $creditCard->number),
                'expiryMonth' => $creditCard->expiryMonth,
                'expiryYear' => $creditCard->expiryYear,
                'ccv' => $creditCard->ccv,
            ],
            'creditCardHolderInfo' => array_filter([
                'name' => $holderInfo->name,
                'email' => $holderInfo->email,
                'cpfCnpj' => preg_replace('/\D/', '', $holderInfo->cpfCnpj),
                'postalCode' => preg_replace('/\D/', '', $holderInfo->postalCode),
                'addressNumber' => $holderInfo->addressNumber,
                'mobilePhone' => $holderInfo->mobilePhone ?? $holderInfo->phone,
                'phone' => $holderInfo->phone ?? $holderInfo->mobilePhone,
            ], fn ($v) => $v !== null && $v !== ''),
        ];

        if ($installments > 1) {
            $payload['installmentCount'] = $installments;
            $payload['totalValue'] = $amount;
        }

        $response = $this->request('post', 'payments', $payload, 30);

        $data = $response->json();

        if ($response->failed()) {
            $errors = collect($data['errors'] ?? [])->pluck('description')->implode('; ');
            Log::channel('discord')->error('Erro ao criar cobrança de cartão no Asaas', [
                'type' => 'payments',
                'customer_id' => $customerId,
                'status' => $response->status(),
                'errors' => $errors,
            ]);
            throw new RuntimeException('Asaas credit card error: '.($errors ?: $response->body()), $response->status());
        }

        Log::channel('payments')->info('Cobrança de cartão processada no Asaas', [
            'payment_id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
            'decline_reason' => $data['creditCard']['declineReason'] ?? null,
            'external_reference' => $externalReference,
        ]);

        return $data;
    }

    /**
     * Find a customer in Asaas by CPF/CNPJ; create if not found.
     *
     * @param  array{name: string, email: string, cpfCnpj: string, phone?: string}  $data
     * @return string Asaas customer ID
     */
    public function findOrCreateCustomer(AsaasCustomerDTO $customer): string
    {
        $cpfCnpj = preg_replace('/\D/', '', $customer->cpfCnpj);

        if ($cpfCnpj) {
            try {
                $response = $this->request('get', 'customers', ['cpfCnpj' => $cpfCnpj, 'limit' => 1], 10);

                if ($response->successful()) {
                    $existing = $response->json('data.0.id');
                    if ($existing) {
                        return $existing;
                    }
                }
            } catch (AsaasCircuitOpenException $e) {
                throw $e;
            } catch (\Throwable) {
                // fall through to create
            }
        }

        return $this->createCustomer($customer);
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
            'customer_id' => $customerId,
            'amount' => $amount,
            'external_reference' => $externalReference,
            'fee_percentage' => $feePercentage,
        ]);

        $payload = [
            'customer' => $customerId,
            'billingType' => 'PIX',
            'value' => $amount,
            'dueDate' => now()->addMinutes(30)->toDateString(),
            'description' => $description,
            'externalReference' => $externalReference,
        ];

        $veddiWalletId = config('services.asaas.veddi_wallet_id');
        $isSandbox = config('services.asaas.sandbox', true);

        if ($feePercentage > 0 && $veddiWalletId && ! $isSandbox) {
            $payload['split'] = [
                [
                    'walletId' => $veddiWalletId,
                    'percentualValue' => round($feePercentage * 100, 4),
                ],
            ];
        }

        $response = $this->request('post', 'payments', $payload);

        if ($response->failed()) {
            $body = $response->json();
            $isOwnWalletError = collect($body['errors'] ?? [])->contains('code', 'invalid_action');

            if ($isOwnWalletError && isset($payload['split'])) {
                Log::channel('payments')->warning('Split rejeitado (carteira própria), criando cobrança sem split', [
                    'customer_id' => $customerId,
                ]);
                unset($payload['split']);
                $response = $this->request('post', 'payments', $payload);
            }
        }

        if ($response->failed()) {
            Log::channel('discord')->error('Erro ao criar cobrança de pedido no Asaas', [
                'type' => 'payments',
                'customer_id' => $customerId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Asaas order charge error: '.$response->body(), $response->status());
        }

        $data = $response->json();

        Log::channel('payments')->info('Cobrança de pedido criada no Asaas', [
            'payment_id' => $data['id'] ?? null,
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
            $response = $this->request('get', "payments/{$paymentId}/pixQrCode", [], 10);

            if ($response->failed()) {
                Log::channel('payments')->warning('Falha ao buscar QR Code PIX do Asaas', [
                    'payment_id' => $paymentId,
                    'status' => $response->status(),
                ]);

                return ['encodedImage' => null, 'payload' => null];
            }

            return [
                'encodedImage' => $response->json('encodedImage'),
                'payload' => $response->json('payload'),
            ];
        } catch (AsaasCircuitOpenException) {
            return ['encodedImage' => null, 'payload' => null];
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('Exceção ao buscar QR Code PIX do Asaas', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
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
            'value' => $data['value'] ?? null,
            'operation' => $data['operationType'] ?? null,
        ]);

        $response = $this->request('post', 'transfers', $data);

        if ($response->failed()) {
            Log::channel('discord')->error('Erro ao criar transferência no Asaas', [
                'type' => 'payments',
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Asaas transfer error: '.$response->body(), $response->status());
        }

        $transfer = $response->json();

        Log::channel('payments')->info('Transferência criada no Asaas', [
            'transfer_id' => $transfer['id'] ?? null,
            'value' => $data['value'] ?? null,
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
            'value' => $value,
        ]);

        $body = $value !== null ? ['value' => $value] : [];

        $response = $this->request('post', "payments/{$asaasPaymentId}/refund", $body);

        if ($response->failed()) {
            Log::channel('discord')->error('Erro ao solicitar reembolso no Asaas', [
                'type' => 'payments',
                'asaas_payment_id' => $asaasPaymentId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Asaas refund error: '.$response->body(), $response->status());
        }

        $data = $response->json();

        Log::channel('payments')->info('Reembolso solicitado no Asaas', [
            'asaas_payment_id' => $asaasPaymentId,
            'result_status' => $data['status'] ?? null,
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
