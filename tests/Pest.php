<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| PDV helpers
|--------------------------------------------------------------------------
|
| Compartilhados entre TerminalTest (venda direta) e TabTerminalTest
| (mesa/comanda) — cada arquivo precisa rodar isoladamente, então os
| helpers ficam aqui em vez de redeclarados em cada um.
|
*/

function pdvContext(): array
{
    $company = \App\Models\Company::create([
        'name' => 'PDV Teste',
        'slug' => 'pdv-teste-'.uniqid(),
        'order_prefix' => 'PDV',
        'active' => true,
        'plan' => 'pro',
        'pdv_module_enabled' => true,
        'waiter_module_enabled' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = \App\Models\Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Balcão',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $category = \App\Models\ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Salgados',
        'active' => true,
        'sort_order' => 1,
    ]);

    $product = \App\Models\Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Coxinha',
        'price' => 8.00,
        'active' => true,
        'sort_order' => 1,
    ]);

    \Illuminate\Support\Facades\DB::table('branch_product')->insert([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available' => 1,
    ]);

    $admin = \App\Models\User::factory()->create(['is_super_admin' => true]);

    \App\Models\PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'opening_amount' => 0,
    ]);

    return compact('company', 'branch', 'category', 'product', 'admin');
}

/** Usuário com papel garçom: só `pdv.waiter_operate`, sem `pdv.operate`, restrito à filial informada. */
function makeWaiter(\App\Models\Company $company, \App\Models\Branch $branch): \App\Models\User
{
    $permission = \App\Models\Permission::firstOrCreate(
        ['name' => 'pdv.waiter_operate'],
        ['group' => 'pdv', 'label' => 'Operar PDV (garçom — mesas e comandas)']
    );

    $waiter = \App\Models\User::factory()->create();

    $waiter->companies()->attach($company->id, [
        'role' => 'garcom',
        'branch_id' => $branch->id,
    ]);

    \App\Models\UserPermission::create([
        'user_id' => $waiter->id,
        'company_id' => $company->id,
        'permission_id' => $permission->id,
        'granted' => true,
    ]);

    return $waiter;
}

/** Mesa registrada de antemão — abrir comanda no PDV agora exige mesa cadastrada. */
function openTable(\App\Models\Company $company, \App\Models\Branch $branch, int $number = 5): \App\Models\RestaurantTable
{
    return \App\Models\RestaurantTable::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'number' => $number,
        'active' => true,
    ]);
}

/*
|--------------------------------------------------------------------------
| iFood helpers
|--------------------------------------------------------------------------
|
| Compartilhados entre os testes de webhook/polling/mapper/job da integração
| iFood — empresa + filial + produto já mapeado (branch_product.ifood_item_id)
| + IfoodIntegration prontos para uso.
|
*/

function ifoodContext(string $suffix = 'A'): array
{
    $company = \App\Models\Company::create([
        'name' => "Empresa iFood {$suffix}",
        'slug' => "empresa-ifood-ctx-{$suffix}-".uniqid(),
        'order_prefix' => 'IFC'.strtoupper(substr($suffix, 0, 1)),
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = \App\Models\Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => "Filial {$suffix}",
        'address' => 'Rua X, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $category = \App\Models\ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Salgados',
        'active' => true,
        'sort_order' => 1,
    ]);

    $product = \App\Models\Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Coxinha',
        'price' => 8.00,
        'active' => true,
        'available_in_ifood' => true,
        'sort_order' => 1,
    ]);

    \Illuminate\Support\Facades\DB::table('branch_product')->insert([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available' => 1,
        'ifood_item_id' => "ifood-item-coxinha-{$suffix}",
    ]);

    $integration = \App\Models\IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'merchant_id' => "merchant-ctx-{$suffix}",
        'access_token' => "access-ctx-{$suffix}",
        'refresh_token' => "refresh-ctx-{$suffix}",
        'token_expires_at' => now()->addHours(6),
        'status' => 'active',
    ]);

    return compact('company', 'branch', 'category', 'product', 'integration');
}

/** Pedido iFood mínimo pra testar ações de admin (accept/reject/cancel) sobre um ifoodContext(). */
function ifoodKanbanOrder(array $ctx, string $status = 'paid', string $externalOrderId = 'ifood-kanban-1'): \App\Models\Order
{
    return \App\Models\Order::create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'customer_id' => \App\Models\Customer::withoutGlobalScopes()->create([
            'company_id' => $ctx['company']->id,
            'name' => 'Cliente iFood',
            'phone' => '11999990000',
        ])->id,
        'subtotal' => 20.00,
        'delivery_fee' => 5.00,
        'total' => 25.00,
        'discount' => 0,
        'fee' => 0,
        'net_value' => 25.00,
        'status' => $status,
        'payment_method' => 'ifood',
        'order_type' => 'delivery',
        'channel' => 'ifood',
        'external_order_id' => $externalOrderId,
    ]);
}

/** Payload de detalhes de pedido (GET /order/v1.0/orders/{id}) — 1 item, sem complemento. */
function ifoodOrderDetailsPayload(string $ifoodOrderId, string $merchantId, string $ifoodItemId, int $quantity = 2): array
{
    return [
        'id' => $ifoodOrderId,
        'displayId' => '1234',
        'merchant' => ['id' => $merchantId],
        'orderType' => 'DELIVERY',
        'createdAt' => now()->toIso8601String(),
        'customer' => [
            'name' => 'Cliente iFood',
            'phone' => ['number' => '11988887777'],
        ],
        'delivery' => [
            'deliveryAddress' => [
                'streetName' => 'Rua das Coxinhas',
                'streetNumber' => '100',
                'neighborhood' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
                'postalCode' => '01000-000',
                'coordinates' => ['latitude' => -23.55, 'longitude' => -46.63],
            ],
        ],
        'items' => [
            [
                'id' => $ifoodItemId,
                'name' => 'Coxinha',
                'quantity' => $quantity,
                'unitPrice' => 8.00,
                'options' => [],
            ],
        ],
        'payments' => ['methods' => [['type' => 'PREPAID']]],
        'total' => [
            'subTotal' => 8.00 * $quantity,
            'deliveryFee' => 5.00,
            'discount' => 0.00,
            'orderAmount' => (8.00 * $quantity) + 5.00,
        ],
    ];
}
