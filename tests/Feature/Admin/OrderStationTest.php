<?php

use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Orders\Show;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function stationOrderContext(): array
{
    $company = Company::create([
        'name' => 'Lancheria Estação',
        'slug' => 'lancheria-estacao',
        'order_prefix' => 'LE',
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Central',
        'address' => 'Rua B, 10',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Maria',
        'phone' => '11999990002',
        'email' => 'maria@teste.com',
        'address' => 'Rua das Flores',
        'number' => '123',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'cep' => '01310-100',
    ]);

    $admin = User::factory()->create(['is_super_admin' => true]);

    $kitchenCategory = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Lanches',
        'station' => 'cozinha',
        'active' => true,
        'sort_order' => 1,
    ]);

    $barCategory = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Bebidas',
        'station' => 'bar',
        'active' => true,
        'sort_order' => 2,
    ]);

    $snack = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $kitchenCategory->id,
        'name' => 'X-Burguer',
        'price' => 25.00,
        'active' => true,
        'sort_order' => 1,
    ]);

    $drink = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $barCategory->id,
        'name' => 'Refrigerante Lata',
        'price' => 6.00,
        'active' => true,
        'sort_order' => 1,
    ]);

    foreach ([$snack, $drink] as $product) {
        DB::table('branch_product')->insert([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'available' => 1,
        ]);
    }

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 31.00,
        'delivery_fee' => 0,
        'discount' => 0,
        'total' => 31.00,
        'fee' => 0,
        'net_value' => 31.00,
        'status' => 'pending',
        'notes' => '',
        'payment_method' => 'pix',
        'order_type' => 'pdv',
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $snack->id,
        'product_name' => 'X-Burguer',
        'unit_price' => 25.00,
        'quantity' => 1,
        'subtotal' => 25.00,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $drink->id,
        'product_name' => 'Refrigerante Lata',
        'unit_price' => 6.00,
        'quantity' => 1,
        'subtotal' => 6.00,
    ]);

    return compact('company', 'branch', 'customer', 'admin', 'snack', 'drink', 'order');
}

function makeStationUser(Company $company, string $role): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => $role]);

    return $user;
}

// ─── Admin/Orders/Show: filtro de itens por estação ───────────────────────────

test('cozinha só vê itens de cozinha no pedido', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $cozinha = makeStationUser($company, 'cozinha');

    $this->actingAs($cozinha);

    Livewire::test(Show::class, ['order' => $order])
        ->assertSee('X-Burguer')
        ->assertDontSee('Refrigerante Lata');
});

test('bar só vê itens de bar no pedido', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $bar = makeStationUser($company, 'bar');

    $this->actingAs($bar);

    Livewire::test(Show::class, ['order' => $order])
        ->assertSee('Refrigerante Lata')
        ->assertDontSee('X-Burguer');
});

test('company_admin continua vendo todos os itens do pedido', function () {
    ['admin' => $admin, 'order' => $order] = stationOrderContext();

    $this->actingAs($admin);

    Livewire::test(Show::class, ['order' => $order])
        ->assertSee('X-Burguer')
        ->assertSee('Refrigerante Lata');
});

test('cozinha não pode editar itens do pedido', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $cozinha = makeStationUser($company, 'cozinha');

    $this->actingAs($cozinha);

    Livewire::test(Show::class, ['order' => $order])
        ->call('startEditItems')
        ->assertForbidden();
});

// ─── Card de pagamento e botões de cupom por perfil ───────────────────────────

test('cozinha e bar não veem o card de pagamento, admin vê', function () {
    ['company' => $company, 'admin' => $admin, 'order' => $order] = stationOrderContext();
    $cozinha = makeStationUser($company, 'cozinha');
    $bar = makeStationUser($company, 'bar');

    $this->actingAs($admin);
    Livewire::test(Show::class, ['order' => $order])->assertSee('Método');

    $this->actingAs($cozinha);
    Livewire::test(Show::class, ['order' => $order])->assertDontSee('Método');

    $this->actingAs($bar);
    Livewire::test(Show::class, ['order' => $order])->assertDontSee('Método');
});

test('cozinha só vê botão de cupom cozinha, bar só vê botão de cupom bar, admin vê os três', function () {
    ['company' => $company, 'admin' => $admin, 'order' => $order] = stationOrderContext();
    $cozinha = makeStationUser($company, 'cozinha');
    $bar = makeStationUser($company, 'bar');

    $this->actingAs($admin);
    Livewire::test(Show::class, ['order' => $order])
        ->assertSee('Imprimir cupom')
        ->assertSee('Cupom cozinha')
        ->assertSee('Cupom bar');

    $this->actingAs($cozinha);
    Livewire::test(Show::class, ['order' => $order])
        ->assertDontSee('Imprimir cupom')
        ->assertSee('Cupom cozinha')
        ->assertDontSee('Cupom bar');

    $this->actingAs($bar);
    Livewire::test(Show::class, ['order' => $order])
        ->assertDontSee('Imprimir cupom')
        ->assertDontSee('Cupom cozinha')
        ->assertSee('Cupom bar');
});

// ─── Listagem de pedidos: link de cupom por estação ───────────────────────────

test('link de cupom na listagem (lista e kanban) aponta pra estação do usuário', function () {
    ['company' => $company, 'admin' => $admin, 'order' => $order] = stationOrderContext();
    $cozinha = makeStationUser($company, 'cozinha');
    $bar = makeStationUser($company, 'bar');

    $normalPath = route('admin.orders.receipt', $order);
    $cozinhaPath = route('admin.orders.receipt', ['order' => $order->id, 'station' => 'cozinha']);
    $barPath = route('admin.orders.receipt', ['order' => $order->id, 'station' => 'bar']);

    $this->actingAs($admin);
    Livewire::test(OrdersIndex::class)->assertSee($normalPath, false)->assertDontSee($cozinhaPath, false);

    $this->actingAs($cozinha);
    Livewire::test(OrdersIndex::class)->assertSee($cozinhaPath, false)->assertDontSee($barPath, false);

    $this->actingAs($bar);
    Livewire::test(OrdersIndex::class)->assertSee($barPath, false)->assertDontSee($cozinhaPath, false);

    // kanban view usa o mesmo $userStation
    $this->actingAs($cozinha);
    Livewire::test(OrdersIndex::class)
        ->set('viewMode', 'kanban')
        ->assertSee($cozinhaPath, false);
});

// ─── OrderItem::matchesStation (predicado usado pelo cupom e pela tela) ───────

test('item de categoria cozinha só combina com estação cozinha', function () {
    ['order' => $order] = stationOrderContext();
    $snack = $order->items->firstWhere('product_name', 'X-Burguer');

    expect($snack->matchesStation('cozinha'))->toBeTrue();
    expect($snack->matchesStation('bar'))->toBeFalse();
    expect($snack->matchesStation(null))->toBeTrue();
});

test('item de categoria bar só combina com estação bar', function () {
    ['order' => $order] = stationOrderContext();
    $drink = $order->items->firstWhere('product_name', 'Refrigerante Lata');

    expect($drink->matchesStation('bar'))->toBeTrue();
    expect($drink->matchesStation('cozinha'))->toBeFalse();
});

test('item de categoria "ambos" (sem estação definida) combina com qualquer estação', function () {
    ['order' => $order, 'company' => $company, 'branch' => $branch] = stationOrderContext();

    $bothCategory = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Sobremesas',
        'station' => null,
        'active' => true,
        'sort_order' => 3,
    ]);

    $dessert = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $bothCategory->id,
        'name' => 'Pudim',
        'price' => 8.00,
        'active' => true,
        'sort_order' => 1,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $dessert->id,
        'product_name' => 'Pudim',
        'unit_price' => 8.00,
        'quantity' => 1,
        'subtotal' => 8.00,
    ]);

    expect($item->matchesStation('cozinha'))->toBeTrue();
    expect($item->matchesStation('bar'))->toBeTrue();
});

// ─── Cupom por estação (rota) ──────────────────────────────────────────────────

test('cupom por estação (cozinha e bar) responde ok e cupom completo continua igual', function (?string $station) {
    ['admin' => $admin, 'order' => $order] = stationOrderContext();

    $this->actingAs($admin);

    $response = $this->get(route('admin.orders.receipt', array_filter(['order' => $order, 'station' => $station])));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
})->with([null, 'cozinha', 'bar']);

test('estação inválida na rota do cupom retorna 404', function () {
    ['admin' => $admin, 'order' => $order] = stationOrderContext();

    $this->actingAs($admin);

    $this->get(route('admin.orders.receipt', ['order' => $order, 'station' => 'churrasqueira']))
        ->assertNotFound();
});

// ─── Status: cozinha/bar só preparing/ready; "a caminho" só entrega ───────────

test('cozinha e bar só veem botões de status preparando e pronto', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $cozinha = makeStationUser($company, 'cozinha');

    $this->actingAs($cozinha);

    Livewire::test(Show::class, ['order' => $order])
        ->assertSee('Preparando')
        ->assertSee('Pronto')
        ->assertDontSee('Cancelado')
        ->assertDontSee('Entregue')
        ->assertDontSee('Ag. Pagamento');
});

test('admin em pedido de entrega vê o status "a caminho"', function () {
    ['admin' => $admin, 'order' => $order] = stationOrderContext();
    $order->update(['order_type' => 'delivery']);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['order' => $order])->assertSee('A caminho');
});

test('admin em pedido de balcão não vê o status "a caminho"', function () {
    ['admin' => $admin, 'order' => $order] = stationOrderContext();

    $this->actingAs($admin);

    Livewire::test(Show::class, ['order' => $order])->assertDontSee('A caminho');
});

test('admin em pedido de entrega lançado no PDV (order_type=pdv com endereço) vê "a caminho"', function () {
    ['admin' => $admin, 'order' => $order] = stationOrderContext();

    $address = \App\Models\Address::create([
        'line1' => 'Rua das Flores',
        'number' => '123',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'cep' => '01310-100',
    ]);
    $order->update(['order_type' => 'pdv', 'delivery_address_id' => $address->id]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['order' => $order])->assertSee('A caminho');

    expect($order->fresh()->isDeliveryOrder())->toBeTrue();
});

test('cozinha não consegue forjar updateStatus pra um status fora de preparing/ready', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $cozinha = makeStationUser($company, 'cozinha');

    $this->actingAs($cozinha);

    Livewire::test(Show::class, ['order' => $order])
        ->call('updateStatus', 'delivered')
        ->assertHasErrors(['status']);

    expect($order->fresh()->status)->toBe('pending');
});

test('updateStatus rejeita "out_for_delivery" pra pedido que não é de entrega', function () {
    ['admin' => $admin, 'order' => $order] = stationOrderContext();

    $this->actingAs($admin);

    Livewire::test(Show::class, ['order' => $order])
        ->call('updateStatus', 'out_for_delivery')
        ->assertHasErrors(['status']);

    expect($order->fresh()->status)->toBe('pending');
});

test('updateStatus aceita "out_for_delivery" pra pedido de entrega', function () {
    ['admin' => $admin, 'order' => $order] = stationOrderContext();
    $order->update(['order_type' => 'delivery']);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['order' => $order])
        ->call('updateStatus', 'out_for_delivery')
        ->assertHasNoErrors();

    expect($order->fresh()->status)->toBe('out_for_delivery');
});

test('kanban rejeita mover pedido não-entrega pra "out_for_delivery"', function () {
    ['admin' => $admin, 'order' => $order] = stationOrderContext();

    $this->actingAs($admin);

    Livewire::test(OrdersIndex::class)
        ->call('updateOrderStatus', $order->id, 'out_for_delivery')
        ->assertHasNoErrors();

    expect($order->fresh()->status)->toBe('pending');
});

test('kanban impede cozinha/bar de mover pedido pra status fora de preparing/ready', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $bar = makeStationUser($company, 'bar');

    $this->actingAs($bar);

    Livewire::test(OrdersIndex::class)
        ->call('updateOrderStatus', $order->id, 'delivered')
        ->assertHasNoErrors();

    expect($order->fresh()->status)->toBe('pending');
});

// ─── Role "entrega" ────────────────────────────────────────────────────────────

test('entrega só vê botões de status "a caminho" e "entregue"', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $order->update(['order_type' => 'delivery']);
    $entrega = makeStationUser($company, 'entrega');

    $this->actingAs($entrega);

    Livewire::test(Show::class, ['order' => $order])
        ->assertSee('A caminho')
        ->assertSee('Entregue')
        ->assertDontSee('Preparando')
        ->assertDontSee('Pronto')
        ->assertDontSee('Cancelado');
});

test('entrega vê todos os itens do pedido (não filtrado por categoria)', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $entrega = makeStationUser($company, 'entrega');

    $this->actingAs($entrega);

    Livewire::test(Show::class, ['order' => $order])
        ->assertSee('X-Burguer')
        ->assertSee('Refrigerante Lata');
});

test('entrega não vê o card de pagamento', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $entrega = makeStationUser($company, 'entrega');

    $this->actingAs($entrega);

    Livewire::test(Show::class, ['order' => $order])->assertDontSee('Método');
});

test('entrega não consegue forjar updateStatus fora de out_for_delivery/delivered', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $order->update(['order_type' => 'delivery']);
    $entrega = makeStationUser($company, 'entrega');

    $this->actingAs($entrega);

    Livewire::test(Show::class, ['order' => $order])
        ->call('updateStatus', 'preparing')
        ->assertHasErrors(['status']);

    expect($order->fresh()->status)->toBe('pending');
});

test('cupom entrega só aparece pra pedido de entrega, e só quem é entrega ou sem estação vê o botão', function () {
    ['company' => $company, 'admin' => $admin, 'order' => $order] = stationOrderContext();
    $order->update(['order_type' => 'delivery']);
    $entrega = makeStationUser($company, 'entrega');
    $cozinha = makeStationUser($company, 'cozinha');

    $this->actingAs($admin);
    Livewire::test(Show::class, ['order' => $order])->assertSee('Cupom entrega');

    $this->actingAs($entrega);
    Livewire::test(Show::class, ['order' => $order])->assertSee('Cupom entrega');

    $this->actingAs($cozinha);
    Livewire::test(Show::class, ['order' => $order])->assertDontSee('Cupom entrega');
});

test('cupom entrega não aparece pra pedido que não é de entrega', function () {
    ['admin' => $admin, 'order' => $order] = stationOrderContext();

    $this->actingAs($admin);

    Livewire::test(Show::class, ['order' => $order])->assertDontSee('Cupom entrega');
});

test('rota do cupom entrega lista todos os itens, sem filtrar por categoria', function () {
    ['admin' => $admin, 'order' => $order] = stationOrderContext();
    $order->update(['order_type' => 'delivery']);

    $this->actingAs($admin);

    $response = $this->get(route('admin.orders.receipt', ['order' => $order, 'station' => 'entrega']));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
});

test('kanban impede entrega de mover pedido pra status fora de out_for_delivery/delivered', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $entrega = makeStationUser($company, 'entrega');

    $this->actingAs($entrega);

    Livewire::test(OrdersIndex::class)
        ->call('updateOrderStatus', $order->id, 'preparing')
        ->assertHasNoErrors();

    expect($order->fresh()->status)->toBe('pending');
});
