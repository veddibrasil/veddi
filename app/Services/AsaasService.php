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
     * @return array{id: string, status: string, nextDueDate: string, value: float}
     */
    public function createSubscription(string $customerId, Plan $plan): array
    {
        $amount = $plan->monthlyPrice();

        Log::channel('payments')->info('Criando assinatura no Asaas', [
            'customer_id' => $customerId,
            'plan'        => $plan->value,
            'amount'      => $amount,
        ]);

        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->timeout(15)
            ->post("{$this->baseUrl}/subscriptions", [
                'customer'    => $customerId,
                'billingType' => 'PIX',
                'value'       => $amount,
                'nextDueDate' => now()->addDay()->toDateString(),
                'cycle'       => 'MONTHLY',
                'description' => $plan->asaasDescription(),
            ]);

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
    public function createCharge(string $customerId, float $amount, string $description): array
    {
        Log::channel('payments')->info('Criando cobrança avulsa no Asaas', [
            'customer_id' => $customerId,
            'amount'      => $amount,
            'description' => $description,
        ]);

        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->timeout(15)
            ->post("{$this->baseUrl}/payments", [
                'customer'    => $customerId,
                'billingType' => 'PIX',
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
