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
        Log::info('Creating AbacatePay billing', [
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'method' => $method,
        ]);
        $order->loadMissing('items');

        $response = Http::withToken($this->token)
            ->timeout(15)
            ->post("{$this->baseUrl}/billing/create", [
                'frequency'     => 'ONE_TIME',
                'methods'       => [strtoupper($method)],
                'returnUrl'     => route('chat.index'),
                'completionUrl' => env('COMPLETETION_URL', route('webhook.abacatepay')),
                'customer'      => array_filter([
                    'name'      => $customer->name,
                    'email'     => $customer->email,
                    'cellphone' => $this->formatPhone($customer->phone),
                    'taxId'     => $this->formatTaxId($customer->tax_id) ?? null,
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
            throw new RuntimeException(
                'AbacatePay API error: '.$response->body(),
                $response->status()
            );
        }

        $data = $response->json('data');

        Log::info('Created AbacatePay billing', $data);

        return [
            'id'           => $data['id'],
            'url'          => $data['url'] ?? null,
            'pixQrCode'    => $data['pixQrCode'] ?? null,
            'pixCopyPaste' => $data['pixCopyPaste'] ?? null,
        ];
    }

    private function formatPhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        // Garante código de país 55 (Brasil)
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55' . $digits;
        }

        return $digits ?: null;
    }

    private function formatTaxId(?string $taxId): ?string
    {
        if (! $taxId) {
            return null;
        }

        return preg_replace('/\D/', '', $taxId) ?: null;
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
