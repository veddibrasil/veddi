<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
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

    public function createBilling(Order $order, Customer $customer): array
    {
        $order->loadMissing('items');

        $response = Http::withToken($this->token)
            ->timeout(15)
            ->post("{$this->baseUrl}/billing/create", [
                'frequency'     => 'ONE_TIME',
                'methods'       => ['PIX'],
                'returnUrl'     => route('chat.index'),
                'completionUrl' => route('webhook.abacatepay'),
                'customer'      => [
                    'name'      => $customer->name,
                    'email'     => $customer->email,
                    'cellphone' => $customer->phone,
                    'taxId'     => null,
                ],
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

        return [
            'id'           => $data['id'],
            'url'          => $data['url'] ?? null,
            'pixQrCode'    => $data['pixQrCode'] ?? null,
            'pixCopyPaste' => $data['pixCopyPaste'] ?? null,
        ];
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
