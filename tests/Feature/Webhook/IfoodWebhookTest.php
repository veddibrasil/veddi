<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Portal;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPortalMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function ifoodWebhookContext(): array
{
    $company = Company::create([
        'name' => 'Empresa iFood',
        'slug' => 'empresa-ifood',
        'order_prefix' => 'IFD',
        'active' => true,
        'portals_module_enabled' => true,
    ]);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Central',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Lanches',
        'active' => true,
        'sort_order' => 1,
    ]);

    $product = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'X-Burguer',
        'price' => 25.00,
        'active' => true,
        'sort_order' => 1,
    ]);

    DB::table('branch_product')->insert([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available' => 1,
    ]);

    $portal = Portal::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'channel' => 'ifood',
        'external_merchant_id' => 'merchant-123',
        'credentials' => [
            'access_token' => 'token-abc',
            'refresh_token' => 'refresh-abc',
            'expires_at' => now()->addHours(5)->toIso8601String(),
        ],
        'status' => 'connected',
    ]);

    ProductPortalMapping::create([
        'product_id' => $product->id,
        'portal_id' => $portal->id,
        'external_item_id' => 'item-abc',
    ]);

    return compact('company', 'branch', 'category', 'product', 'portal');
}

function ifoodOrderPayload(): array
{
    return [
        'id' => 'ext-order-1',
        'merchant' => ['id' => 'merchant-123'],
        'orderType' => 'DELIVERY',
        'total' => ['subTotal' => 5000, 'deliveryFee' => 0, 'orderAmount' => 5000],
        'payments' => ['prepaid' => true],
        'customer' => ['name' => 'Cliente iFood', 'phone' => ['number' => '11999998888']],
        'items' => [
            ['id' => 'item-abc', 'name' => 'X-Burguer', 'quantity' => 2, 'unitPrice' => 2500],
        ],
    ];
}

function ifoodSignedHeaders(array $body, string $secret = 'test-secret'): array
{
    return ['X-IFood-Signature' => hash_hmac('sha256', json_encode($body), $secret)];
}

// ─── Testes ───────────────────────────────────────────────────────────────────

test('webhook com assinatura inválida retorna 401 e não enfileira job', function () {
    config(['services.ifood.client_secret' => 'test-secret']);

    $response = $this->postJson('/webhooks/ifood', [['id' => 'evt-1', 'fullCode' => 'PLACED']], [
        'X-IFood-Signature' => 'assinatura-invalida',
    ]);

    $response->assertStatus(401);
});

test('evento PLACED cria pedido local com produto mapeado, canal ifood e sem taxa de plataforma', function () {
    config(['services.ifood.client_secret' => 'test-secret']);
    ['portal' => $portal] = ifoodWebhookContext();

    Http::fake([
        'merchant-api.ifood.com.br/order/v1.0/orders/ext-order-1' => Http::response(ifoodOrderPayload(), 200),
        'merchant-api.ifood.com.br/order/v1.0/orders/ext-order-1/confirm' => Http::response('', 202),
    ]);

    $body = [['id' => 'evt-1', 'fullCode' => 'PLACED', 'orderId' => 'ext-order-1', 'merchantId' => 'merchant-123']];

    $response = $this->postJson('/webhooks/ifood', $body, ifoodSignedHeaders($body, 'test-secret'));

    $response->assertStatus(202);

    $order = Order::withoutGlobalScopes()->where('external_order_id', 'ext-order-1')->first();

    expect($order)->not->toBeNull();
    expect($order->channel)->toBe('ifood');
    expect($order->portal_id)->toBe($portal->id);
    expect((float) $order->fee)->toBe(0.0);
    expect((float) $order->net_value)->toBe((float) $order->total);
    expect($order->items)->toHaveCount(1);
    expect((int) $order->items->first()->quantity)->toBe(2);
    expect($order->payment->status)->toBe('paid');
    expect($order->payment->payment_gateway)->toBe('ifood');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/orders/ext-order-1/confirm'));
});

test('evento PLACED com item sem mapeamento não cria pedido', function () {
    config(['services.ifood.client_secret' => 'test-secret']);
    ifoodWebhookContext();

    $payload = ifoodOrderPayload();
    $payload['items'][0]['id'] = 'item-nao-mapeado';

    Http::fake([
        'merchant-api.ifood.com.br/order/v1.0/orders/ext-order-1' => Http::response($payload, 200),
    ]);

    $body = [['id' => 'evt-2', 'fullCode' => 'PLACED', 'orderId' => 'ext-order-1', 'merchantId' => 'merchant-123']];

    $this->postJson('/webhooks/ifood', $body, ifoodSignedHeaders($body, 'test-secret'));

    expect(Order::withoutGlobalScopes()->where('external_order_id', 'ext-order-1')->exists())->toBeFalse();
});

test('evento PLACED sem módulo Portais contratado não cria pedido', function () {
    config(['services.ifood.client_secret' => 'test-secret']);
    ['company' => $company] = ifoodWebhookContext();
    $company->update(['portals_module_enabled' => false]);

    Http::fake([
        'merchant-api.ifood.com.br/order/v1.0/orders/ext-order-1' => Http::response(ifoodOrderPayload(), 200),
    ]);

    $body = [['id' => 'evt-4', 'fullCode' => 'PLACED', 'orderId' => 'ext-order-1', 'merchantId' => 'merchant-123']];

    $this->postJson('/webhooks/ifood', $body, ifoodSignedHeaders($body, 'test-secret'));

    expect(Order::withoutGlobalScopes()->where('external_order_id', 'ext-order-1')->exists())->toBeFalse();
});

test('evento CANCELLED atualiza status do pedido existente', function () {
    config(['services.ifood.client_secret' => 'test-secret']);
    ['company' => $company, 'branch' => $branch, 'portal' => $portal] = ifoodWebhookContext();

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente iFood',
        'phone' => '11999998888',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'portal_id' => $portal->id,
        'channel' => 'ifood',
        'external_order_id' => 'ext-order-2',
        'subtotal' => 50.0,
        'delivery_fee' => 0,
        'total' => 50.0,
        'fee' => 0,
        'net_value' => 50.0,
        'status' => 'preparing',
        'payment_method' => 'external_portal',
        'order_type' => 'delivery',
    ]);

    $body = [['id' => 'evt-3', 'fullCode' => 'CANCELLED', 'orderId' => 'ext-order-2', 'merchantId' => 'merchant-123']];

    $this->postJson('/webhooks/ifood', $body, ifoodSignedHeaders($body, 'test-secret'));

    expect($order->fresh()->status)->toBe('cancelled');
    expect($order->fresh()->external_status)->toBe('CANCELLED');
});
