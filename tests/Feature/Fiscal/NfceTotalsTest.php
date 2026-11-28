<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Fiscal\FiscalNoteService;
use App\Services\Fiscal\NfePayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Total da NFC-e (vNF) igual ao pagamento (vPag): sem desconto e taxas nos itens a Focus fechava a
 * nota pela soma dos produtos e a SEFAZ recusava pedido com desconto ("Total dos pagamentos menor
 * que o total da nota. vPag:195.32 - vNF:200.80").
 */
function nfceTotalsContext(array $order = []): Order
{
    $company = Company::create([
        'name' => 'Coxinharia', 'slug' => 'coxinharia-'.uniqid(), 'order_prefix' => 'CXA',
        'active' => true, 'status' => 'ACTIVE', 'owner_cpf_cnpj' => '12345678000100', 'fiscal_notes_enabled' => true,
    ]);
    config(['fiscal.focus_nfe.token' => 'token_test']);
    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Maringá', 'address' => 'Rua A', 'number' => '100',
        'neighborhood' => 'Centro', 'city' => 'Maringa', 'state' => 'PR', 'cep' => '87010000',
        'active' => true, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59',
    ]);
    CompanyFiscalConfig::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'is_default' => true, 'enabled' => true]);
    $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Cliente', 'phone' => '44999990002']);

    $model = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
        'subtotal' => 200.80, 'delivery_fee' => 0, 'discount' => 0, 'manual_discount' => 0, 'total' => 200.80,
        'fee' => 0, 'net_value' => 200.80, 'status' => 'paid', 'payment_method' => 'pix',
        'channel' => 'chat', 'order_type' => 'pickup',
        ...$order,
    ]);

    $category = ProductCategory::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Salgados']);
    foreach ([['Coxinha', 10.40, 12], ['Refrigerante', 7.60, 10], ['Brinde', 0, 1]] as [$name, $price, $quantity]) {
        $product = Product::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'product_category_id' => $category->id, 'name' => $name, 'price' => $price,
            'active' => true, 'fiscal_data' => ['ncm' => '21069090', 'cfop' => '5102', 'icms_situation' => '400'],
        ]);
        OrderItem::create([
            'order_id' => $model->id, 'product_id' => $product->id, 'product_name' => $name,
            'unit_price' => $price, 'quantity' => $quantity, 'subtotal' => round($price * $quantity, 2),
        ]);
    }

    return $model;
}

/** Corpo enviado à Focus na emissão da NFC-e. */
function issuedNfcePayload(Order $order): array
{
    Http::preventStrayRequests();
    Http::fake(['*focusnfe*' => Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('1', 44)], 200)]);

    app(FiscalNoteService::class)->issue($order);

    $sent = Http::recorded(fn (Request $request) => str_contains($request->url(), '/v2/nfce?'));
    expect($sent)->toHaveCount(1);

    return $sent[0][0]->data();
}

function nfceItemsTotal(array $payload, string $field): float
{
    return round(array_sum(array_map(fn ($item) => $item[$field] ?? 0, $payload['items'])), 2);
}

test('desconto do pedido vai rateado nos itens da NFC-e e o total da nota fecha com o pagamento', function () {
    $payload = issuedNfcePayload(nfceTotalsContext(['discount' => 5.00, 'manual_discount' => 0.48, 'total' => 195.32]));

    $products = nfceItemsTotal($payload, 'valor_bruto');
    $discount = nfceItemsTotal($payload, 'valor_desconto');
    $paid = round(array_sum(array_column($payload['formas_pagamento'], 'valor_pagamento')), 2);

    expect($products)->toBe(200.8)
        ->and($discount)->toBe(5.48)
        ->and(round($products - $discount, 2))->toBe($paid)
        ->and($paid)->toBe(195.32)
        ->and(nfceItemsTotal($payload, 'valor_outras_despesas'))->toBe(0.0)
        ->and(collect($payload['items'])->firstWhere('descricao', 'Brinde'))->not->toHaveKey('valor_desconto')
        ->and($payload['modalidade_frete'])->toBe('9');
    foreach ($payload['items'] as $item) {
        expect($item['valor_desconto'] ?? 0)->toBeLessThanOrEqual($item['valor_bruto']);
    }
});

test('taxa de entrega, serviço e couvert entram como outras despesas, nunca como frete', function () {
    $payload = issuedNfcePayload(nfceTotalsContext([
        'delivery_fee' => 7.00, 'service_fee' => 2.50, 'couvert_fee' => 1.00, 'discount' => 3.00, 'total' => 208.30,
    ]));

    expect(nfceItemsTotal($payload, 'valor_outras_despesas'))->toBe(10.5)
        ->and(nfceItemsTotal($payload, 'valor_desconto'))->toBe(3.0)
        ->and(nfceItemsTotal($payload, 'valor_frete'))->toBe(0.0)
        ->and($payload['modalidade_frete'])->toBe('9')
        ->and(round(nfceItemsTotal($payload, 'valor_bruto') - 3.0 + 10.5, 2))->toBe($payload['formas_pagamento'][0]['valor_pagamento']);
});

test('pedido sem desconto nem taxa não leva campos de ajuste', function () {
    $payload = issuedNfcePayload(nfceTotalsContext());

    foreach ($payload['items'] as $item) {
        expect($item)->not->toHaveKey('valor_desconto')
            ->and($item)->not->toHaveKey('valor_outras_despesas');
    }
    expect($payload['formas_pagamento'][0]['valor_pagamento'])->toBe(200.8);
});

test('campos do pedido que não fecham com o total: NFC-e sai pelo total cobrado', function () {
    $payload = issuedNfcePayload(nfceTotalsContext(['discount' => 2.00, 'total' => 190.00]));

    expect(nfceItemsTotal($payload, 'valor_desconto'))->toBe(10.8)
        ->and($payload['formas_pagamento'][0]['valor_pagamento'])->toBe(190.0);
});

test('rateio soma o valor exato e deixa os centavos no maior item, nunca no de valor zero', function () {
    $items = [['subtotal' => 10.0], ['subtotal' => 10.0], ['subtotal' => 10.0], ['subtotal' => 0.0]];

    $shares = array_map(fn ($i) => NfePayloadBuilder::allocation($items, $i, 1.00), array_keys($items));

    expect(round(array_sum($shares), 2))->toBe(1.0)
        ->and($shares[3])->toBe(0.0)
        ->and($shares[0])->toBe(0.34);
});
