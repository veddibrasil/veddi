<?php

namespace App\Services\Payment;

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

    private const PIX_KEY_TYPE_ALIASES = [
        'CPF' => 'CPF',
        'CNPJ' => 'CNPJ',
        'EMAIL' => 'EMAIL',
        'PHONE' => 'PHONE',
        'TELEFONE' => 'PHONE',
        'CELULAR' => 'PHONE',
        'EVP' => 'EVP',
        'RANDOM' => 'EVP',
        'CHAVE_ALEATORIA' => 'EVP',
        'CHAVE ALEATORIA' => 'EVP',
    ];

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

    private function normalizePixKeyType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $normalized = strtoupper(trim($type));

        return self::PIX_KEY_TYPE_ALIASES[$normalized] ?? $normalized;
    }

    private function inferPixKeyType(string $pixKey): ?string
    {
        $key = trim($pixKey);

        if ($key === '') {
            return null;
        }

        if (filter_var($key, FILTER_VALIDATE_EMAIL)) {
            return 'EMAIL';
        }

        // EVP is a UUID (random key)
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key)) {
            return 'EVP';
        }

        $digits = preg_replace('/\D/', '', $key);

        if (strlen($digits) === 11) {
            return 'CPF';
        }

        if (strlen($digits) === 14) {
            return 'CNPJ';
        }

        // PHONE varies; treat as phone when it looks like a phone number (10-13 digits including country code)
        if (strlen($digits) >= 10 && strlen($digits) <= 13) {
            return 'PHONE';
        }

        return null;
    }

    private function sanitizePixKey(string $pixKey, ?string $pixKeyType): string
    {
        $key = trim($pixKey);
        $type = $this->normalizePixKeyType($pixKeyType);

        if ($type === 'CPF' || $type === 'CNPJ' || $type === 'PHONE') {
            return preg_replace('/\D/', '', $key);
        }

        return $key;
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
            'notificationDisabled' => true,
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
     * Ensures notificationDisabled=true on found customers to suppress Asaas emails/SMS.
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
                    $existing = $response->json('data.0');
                    if ($existing && isset($existing['id'])) {
                        if (! ($existing['notificationDisabled'] ?? false)) {
                            $this->disableCustomerNotifications($existing['id']);
                        }

                        return $existing['id'];
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

    private function disableCustomerNotifications(string $customerId): void
    {
        try {
            $this->request('put', "customers/{$customerId}", ['notificationDisabled' => true], 10);
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('Falha ao desabilitar notificações do cliente Asaas', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
        }
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

        $operationType = strtoupper((string) ($data['operationType'] ?? ''));
        if ($operationType === '') {
            throw new RuntimeException('Asaas transfer error: operationType ausente.');
        }

        if ($operationType === 'PIX') {
            $pixKey = (string) ($data['pixAddressKey'] ?? '');
            $providedType = $this->normalizePixKeyType($data['pixAddressKeyType'] ?? null);
            $inferredType = $this->inferPixKeyType($pixKey);

            if ($pixKey === '') {
                throw new RuntimeException('Asaas transfer error: pixAddressKey ausente para operação PIX.');
            }

            if ($providedType === null || $providedType === '') {
                if ($inferredType === null) {
                    throw new RuntimeException('Asaas transfer error: pixAddressKeyType ausente e não foi possível inferir pelo valor.');
                }
                $providedType = $inferredType;
            } elseif ($inferredType !== null && $providedType !== $inferredType) {
                throw new RuntimeException(sprintf(
                    'Asaas transfer error: pixAddressKeyType (%s) não corresponde ao formato da chave (%s).',
                    $providedType,
                    $inferredType
                ));
            }

            if (! in_array($providedType, ['CPF', 'CNPJ', 'EMAIL', 'PHONE', 'EVP'], true)) {
                throw new RuntimeException('Asaas transfer error: pixAddressKeyType inválido: '.$providedType);
            }

            $data['operationType'] = 'PIX';
            $data['pixAddressKeyType'] = $providedType;
            $data['pixAddressKey'] = $this->sanitizePixKey($pixKey, $providedType);
        }

        $response = $this->request('post', 'transfers', $data);

        if ($response->failed()) {
            Log::channel('discord')->error('Erro ao criar transferência no Asaas', [
                'type' => 'payments',
                'status' => $response->status(),
                'body' => $response->body(),
                'operation' => $data['operationType'] ?? null,
                'value' => $data['value'] ?? null,
                'pix_key_type' => $data['pixAddressKeyType'] ?? null,
                'pix_key_length' => isset($data['pixAddressKey']) ? strlen((string) $data['pixAddressKey']) : null,
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
     * Retorna o saldo disponível na conta Asaas da plataforma.
     *
     * @return array{balance: float, totalInvoicedBalance: float}
     */
    public function getBalance(): array
    {
        try {
            $response = $this->request('get', 'finance/balance', [], 10);

            if ($response->failed()) {
                Log::channel('payments')->warning('Falha ao buscar saldo Asaas', [
                    'status' => $response->status(),
                ]);

                return ['balance' => 0.0, 'totalInvoicedBalance' => 0.0];
            }

            return [
                'balance' => (float) ($response->json('balance') ?? 0.0),
                'totalInvoicedBalance' => (float) ($response->json('totalInvoicedBalance') ?? 0.0),
            ];
        } catch (AsaasCircuitOpenException) {
            return ['balance' => 0.0, 'totalInvoicedBalance' => 0.0];
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('Exceção ao buscar saldo Asaas', ['error' => $e->getMessage()]);

            return ['balance' => 0.0, 'totalInvoicedBalance' => 0.0];
        }
    }

    /**
     * Simula antecipação de um recebível no Asaas.
     * Retorna netValue (após taxa) e fee, ou null se não elegível.
     *
     * @return array{netValue: float, fee: float, eligible: bool}
     */
    public function simulateAnticipation(string $asaasPaymentId): array
    {
        try {
            $response = $this->request('post', 'anticipations/simulate', [
                'payment' => $asaasPaymentId,
            ], 15);

            if ($response->failed()) {
                Log::channel('payments')->warning('Simulação de antecipação Asaas falhou', [
                    'asaas_payment_id' => $asaasPaymentId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return ['netValue' => 0.0, 'fee' => 0.0, 'eligible' => false];
            }

            $data = $response->json();

            return [
                'netValue' => (float) ($data['netValue'] ?? 0.0),
                'fee' => (float) ($data['anticipationFee'] ?? 0.0),
                'eligible' => true,
            ];
        } catch (AsaasCircuitOpenException) {
            return ['netValue' => 0.0, 'fee' => 0.0, 'eligible' => false];
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('Exceção na simulação de antecipação Asaas', [
                'asaas_payment_id' => $asaasPaymentId,
                'error' => $e->getMessage(),
            ]);

            return ['netValue' => 0.0, 'fee' => 0.0, 'eligible' => false];
        }
    }

    /**
     * Solicita antecipação de um recebível no Asaas.
     * O saldo fica disponível após RECEIVABLE_ANTICIPATION_CREDITED webhook.
     *
     * @return array{id: string, status: string}
     */
    public function createAnticipation(string $asaasPaymentId): array
    {
        Log::channel('payments')->info('Solicitando antecipação no Asaas', [
            'asaas_payment_id' => $asaasPaymentId,
        ]);

        $response = $this->request('post', 'anticipations', [
            'payment' => $asaasPaymentId,
        ]);

        if ($response->failed()) {
            Log::channel('discord')->error('Erro ao solicitar antecipação no Asaas', [
                'type' => 'payments',
                'asaas_payment_id' => $asaasPaymentId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Asaas anticipation error: '.$response->body(), $response->status());
        }

        $data = $response->json();

        Log::channel('payments')->info('Antecipação solicitada no Asaas', [
            'anticipation_id' => $data['id'] ?? null,
            'asaas_payment_id' => $asaasPaymentId,
            'status' => $data['status'] ?? null,
        ]);

        return [
            'id' => $data['id'] ?? '',
            'status' => $data['status'] ?? '',
        ];
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
