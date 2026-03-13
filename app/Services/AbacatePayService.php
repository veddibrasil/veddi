<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AbacatePayService
{
    private string $baseUrl = 'https://api.abacatepay.com/v1';
    private string $token;
    private ?string $webhookSecret;

    public function __construct(?Company $company = null)
    {
        $this->token         = $company?->abacatepay_token ?? config('services.abacatepay.token');
        $this->webhookSecret = $company?->abacatepay_webhook_secret ?? config('services.abacatepay.webhook_secret');
    }

    public function createBilling(Order $order, Customer $customer, string $method = 'pix'): array
    {
        Log::channel('payments')->info('Iniciando criação de billing AbacatePay', [
            'order_id'    => $order->id,
            'customer_id' => $customer->id,
            'method'      => $method,
        ]);
        $order->loadMissing('items');

        $response = Http::withToken($this->token)
            ->timeout(15)
            ->post("{$this->baseUrl}/billing/create", [
                'frequency'     => 'ONE_TIME',
                'methods'       => [strtoupper($method)],
                'returnUrl'     => $this->company ? route('chat.company', ['company' => $this->company->slug]) : '/',
                'completionUrl' => route('payment.complete'),
                'customer'      => array_filter([
                    'name'      => $customer->name,
                    'email'     => $customer->email,
                    'cellphone' => $this->formatPhone($customer->phone) ?? preg_replace('/\D/', '', $customer->phone ?? ''),
                    'taxId'     => $this->formatTaxId($customer->tax_id),
                ], fn ($v) => $v !== null),
                'products' => $order->items->map(fn ($item) => [
                    'externalId'  => (string) $item->product_id,
                    'name'        => $item->product_name,
                    'description' => $item->product_name,
                    'quantity'    => $item->quantity,
                    'price'       => (int) ($item->unit_price * 100),
                ])->toArray(),
            ]);

        if ($response->failed()) {
            Log::channel('payments')->error('Erro na API AbacatePay ao criar billing', [
                'order_id' => $order->id,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
            throw new RuntimeException(
                'AbacatePay API error: '.$response->body(),
                $response->status()
            );
        }

        $data = $response->json('data');

        Log::channel('payments')->info('Billing AbacatePay criado com sucesso', [
            'order_id'   => $order->id,
            'billing_id' => $data['id'] ?? null,
        ]);

        Log::channel('payments')->debug('AbacatePay billing response', ['data' => $data]);

        return [
            'id'           => $data['id'],
            'url'          => $data['url'] ?? null,
            'pixQrCode'    => $data['pix']['brCodeBase64'] ?? null,
            'pixCopyPaste' => $data['pix']['brCode'] ?? null,
        ];
    }

    private function formatPhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        // Remove country code if present
        if (str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        // Format as (XX) XXXXX-XXXX
        if (strlen($digits) === 11) {
            return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 5) . '-' . substr($digits, 7, 4);
        }

        return null;
    }

    private function formatTaxId(?string $taxId): ?string
    {
        if (! $taxId) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $taxId);

        // Formata como CPF: XXX.XXX.XXX-XX
        if (strlen($digits) === 11) {
            return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
        }

        return $digits ?: null;
    }

    public function refundBilling(string $billingId): array
    {
        Log::channel('payments')->info('Solicitando reembolso AbacatePay', [
            'billing_id' => $billingId,
        ]);

        $response = Http::withToken($this->token)
            ->timeout(15)
            ->post("{$this->baseUrl}/payment/refund", [
                'id' => $billingId,
            ]);

        if ($response->failed()) {
            Log::channel('payments')->error('Erro ao solicitar reembolso AbacatePay', [
                'billing_id' => $billingId,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            throw new RuntimeException(
                'AbacatePay refund error: ' . $response->body(),
                $response->status()
            );
        }

        Log::channel('payments')->info('Reembolso AbacatePay solicitado com sucesso', [
            'billing_id' => $billingId,
        ]);

        return $response->json('data') ?? [];
    }

    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        if (! $this->webhookSecret) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $this->webhookSecret);

        return hash_equals($expected, $signature);
    }
}
