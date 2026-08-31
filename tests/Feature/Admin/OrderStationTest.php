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

// ─── Entrega só vê pedidos de entrega na listagem ─────────────────────────────

test('entrega só vê pedidos de entrega na listagem (lista e kanban), admin vê todos', function () {
    ['company' => $company, 'admin' => $admin, 'order' => $balcaoOrder] = stationOrderContext();
    $entrega = makeStationUser($company, 'entrega');

    $deliveryOrder = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $balcaoOrder->customer_id,
        'branch_id' => $balcaoOrder->branch_id,
        'subtotal' => 25.00,
        'delivery_fee' => 5,
        'discount' => 0,
        'total' => 30.00,
        'fee' => 0,
        'net_value' => 30.00,
        'status' => 'pending',
        'notes' => '',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
    ]);

    $this->actingAs($admin);
    Livewire::test(OrdersIndex::class)
        ->assertSee($balcaoOrder->order_number)
        ->assertSee($deliveryOrder->order_number);

    $this->actingAs($entrega);
    Livewire::test(OrdersIndex::class)
        ->assertDontSee($balcaoOrder->order_number)
        ->assertSee($deliveryOrder->order_number);

    Livewire::test(OrdersIndex::class)
        ->set('viewMode', 'kanban')
        ->assertDontSee($balcaoOrder->order_number)
        ->assertSee($deliveryOrder->order_number);
});

// ─── Notificações: entrega só é notificado de pedidos de entrega ─────────────

test('CreateOrderNotification marca is_delivery de acordo com o pedido', function () {
    ['company' => $company, 'order' => $balcaoOrder] = stationOrderContext();

    $deliveryOrder = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $balcaoOrder->customer_id,
        'branch_id' => $balcaoOrder->branch_id,
        'subtotal' => 25.00,
        'delivery_fee' => 5,
        'discount' => 0,
        'total' => 30.00,
        'fee' => 0,
        'net_value' => 30.00,
        'status' => 'pending',
        'notes' => '',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
    ]);

    app(\App\Listeners\CreateOrderNotification::class)->handle(new \App\Events\NewOrderPlaced($balcaoOrder));
    app(\App\Listeners\CreateOrderNotification::class)->handle(new \App\Events\NewOrderPlaced($deliveryOrder));

    expect(\App\Models\CompanyNotification::where('title', 'like', '%'.$balcaoOrder->order_number.'%')->first()->is_delivery)->toBeFalse();
    expect(\App\Models\CompanyNotification::where('title', 'like', '%'.$deliveryOrder->order_number.'%')->first()->is_delivery)->toBeTrue();
});

test('NotificationBell só mostra notificações de entrega pra estação entrega', function () {
    ['company' => $company, 'admin' => $admin] = stationOrderContext();
    $entrega = makeStationUser($company, 'entrega');

    \App\Models\CompanyNotification::create([
        'company_id' => $company->id,
        'type' => 'order',
        'is_delivery' => false,
        'title' => 'Novo pedido: balcão',
    ]);

    \App\Models\CompanyNotification::create([
        'company_id' => $company->id,
        'type' => 'order',
        'is_delivery' => true,
        'title' => 'Novo pedido: entrega',
    ]);

    $this->actingAs($admin);
    Livewire::test(\App\Livewire\Admin\NotificationBell::class)
        ->assertSee('Novo pedido: balcão')
        ->assertSee('Novo pedido: entrega');

    $this->actingAs($entrega);
    Livewire::test(\App\Livewire\Admin\NotificationBell::class)
        ->assertDontSee('Novo pedido: balcão')
        ->assertSee('Novo pedido: entrega');
});

test('NotificationBell escuta OrderStatusUpdated pra atualizar em tempo real (aviso de pedido agendado usa esse evento)', function () {
    ['company' => $company, 'admin' => $admin] = stationOrderContext();

    $this->actingAs($admin);
    $listeners = Livewire::test(\App\Livewire\Admin\NotificationBell::class)->instance()->getListeners();

    expect($listeners)->toHaveKey("echo:orders.{$company->id},OrderStatusUpdated");
});

test('NotifyScheduledOrderJob cria notificação que aparece no sino do restaurante', function () {
    ['company' => $company, 'admin' => $admin, 'branch' => $branch] = stationOrderContext();

    $customer = \App\Models\Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Agendado',
        'phone' => '11999990000',
    ]);

    $order = \App\Models\Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 30.00,
        'total' => 30.00,
        'net_value' => 30.00,
        'status' => 'scheduled',
        'scheduled_at' => now()->addMinutes(15),
        'notes' => '',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
    ]);

    app(\App\Jobs\NotifyScheduledOrderJob::class, ['orderId' => $order->id])->handle();

    expect(\App\Models\CompanyNotification::where('company_id', $company->id)->where('type', 'scheduled_order')->exists())->toBeTrue();

    $this->actingAs($admin);
    Livewire::test(\App\Livewire\Admin\NotificationBell::class)
        ->assertSee('Pedido agendado: hora de preparar!')
        ->assertSee($order->order_number);
});

// ─── Modal "Confirmar pagamento" (recebido na entrega) ─────────────────────

function pdvAwaitingPaymentOrder(Company $company, Branch $branch): Order
{
    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente PDV',
        'phone' => '11999990003',
    ]);

    return Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 25.00,
        'total' => 25.00,
        'net_value' => 25.00,
        'status' => 'awaiting_payment',
        'notes' => '',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
    ]);
}

test('kanban: abrir e fechar o modal de confirmar pagamento não confirma nada', function () {
    ['company' => $company, 'admin' => $admin, 'branch' => $branch] = stationOrderContext();
    $order = pdvAwaitingPaymentOrder($company, $branch);

    $this->actingAs($admin);

    Livewire::test(OrdersIndex::class)
        ->call('openConfirmPaymentModal', $order->id)
        ->assertSet('confirmingPaymentOrderId', $order->id)
        ->call('closeConfirmPaymentModal')
        ->assertSet('confirmingPaymentOrderId', null);

    expect($order->fresh()->status)->toBe('awaiting_payment');
    expect(\App\Models\Payment::where('order_id', $order->id)->exists())->toBeFalse();
});

test('kanban: confirmar no modal marca pagamento como recebido e move pro caixa', function () {
    ['company' => $company, 'admin' => $admin, 'branch' => $branch] = stationOrderContext();
    $order = pdvAwaitingPaymentOrder($company, $branch);

    $this->actingAs($admin);

    Livewire::test(OrdersIndex::class)
        ->call('openConfirmPaymentModal', $order->id)
        ->call('confirmPayment', $order->id)
        ->assertSet('confirmingPaymentOrderId', null);

    $order->refresh();
    expect($order->status)->toBe('paid');
    $payment = \App\Models\Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe('paid');
});

test('show: abrir modal, cancelar não confirma; confirmar no modal marca pagamento', function () {
    ['company' => $company, 'admin' => $admin, 'branch' => $branch] = stationOrderContext();
    $order = pdvAwaitingPaymentOrder($company, $branch);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['order' => $order])
        ->call('openConfirmPaymentModal')
        ->assertSet('showConfirmPaymentModal', true)
        ->call('closeConfirmPaymentModal')
        ->assertSet('showConfirmPaymentModal', false);

    expect($order->fresh()->status)->toBe('awaiting_payment');

    Livewire::test(Show::class, ['order' => $order])
        ->call('openConfirmPaymentModal')
        ->call('confirmPayment')
        ->assertSet('showConfirmPaymentModal', false);

    $order->refresh();
    expect($order->status)->toBe('paid');
    expect(\App\Models\Payment::where('order_id', $order->id)->where('status', 'paid')->exists())->toBeTrue();
});

test('Notifications (toast) ignora pedido não-entrega pra estação entrega', function () {
    ['company' => $company] = stationOrderContext();
    $entrega = makeStationUser($company, 'entrega');

    $this->actingAs($entrega);

    Livewire::test(\App\Livewire\Admin\Notifications::class)
        ->call('onNewOrder', ['order_id' => 1, 'order_number' => 'X-1', 'customer_name' => 'Cliente', 'total' => 10, 'is_delivery' => false])
        ->assertSet('notifications', []);

    Livewire::test(\App\Livewire\Admin\Notifications::class)
        ->call('onNewOrder', ['order_id' => 2, 'order_number' => 'X-2', 'customer_name' => 'Cliente', 'total' => 10, 'is_delivery' => true])
        ->assertCount('notifications', 1);
});

// ─── UI mobile do entrega: fila em cards + barra de status fixa ───────────────

test('entrega vê fila em cards com ação de avançar status, sem kanban', function () {
    ['company' => $company, 'order' => $balcaoOrder] = stationOrderContext();
    $entrega = makeStationUser($company, 'entrega');

    $deliveryOrder = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $balcaoOrder->customer_id,
        'branch_id' => $balcaoOrder->branch_id,
        'subtotal' => 25.00,
        'delivery_fee' => 5,
        'discount' => 0,
        'total' => 30.00,
        'fee' => 0,
        'net_value' => 30.00,
        'status' => 'ready',
        'notes' => '',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
    ]);

    $this->actingAs($entrega);

    $test = Livewire::test(OrdersIndex::class)
        ->assertSet('viewMode', 'list')
        ->assertSee('Minhas entregas')
        ->assertSee($deliveryOrder->order_number)
        ->assertSee('Saiu para entrega')
        ->assertDontSee('data-kanban-col');

    $test->call('updateOrderStatus', $deliveryOrder->id, 'out_for_delivery');

    expect($deliveryOrder->fresh()->status)->toBe('out_for_delivery');
});

test('entrega vê barra de status fixa embaixo no detalhe do pedido', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $order->update(['order_type' => 'delivery']);
    $entrega = makeStationUser($company, 'entrega');

    $this->actingAs($entrega);

    Livewire::test(Show::class, ['order' => $order])
        ->assertSeeHtml('fixed inset-x-0 bottom-0');
});

test('entrega vê a taxa de frete no detalhe do pedido e na fila de entregas', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $order->update(['order_type' => 'delivery', 'delivery_fee' => 7.50]);
    $entrega = makeStationUser($company, 'entrega');

    $this->actingAs($entrega);

    Livewire::test(Show::class, ['order' => $order])
        ->assertSee('Taxa de frete')
        ->assertSee('7,50');

    Livewire::test(OrdersIndex::class)
        ->assertSee('Frete')
        ->assertSee('7,50');
});

test('entrega vê "Grátis" quando a taxa de frete é zero', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $order->update(['order_type' => 'delivery', 'delivery_fee' => 0]);
    $entrega = makeStationUser($company, 'entrega');

    $this->actingAs($entrega);

    Livewire::test(Show::class, ['order' => $order])->assertSee('Grátis');
    Livewire::test(OrdersIndex::class)->assertSee('Grátis');
});

// ─── Fila própria de cozinha/bar (scope por item) ─────────────────────────────

function makeSingleItemOrder(Company $company, Branch $branch, Customer $customer, Product $product, float $price): Order
{
    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => $price,
        'delivery_fee' => 0,
        'discount' => 0,
        'total' => $price,
        'fee' => 0,
        'net_value' => $price,
        'status' => 'pending',
        'notes' => '',
        'payment_method' => 'pix',
        'order_type' => 'pdv',
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'unit_price' => $price,
        'quantity' => 1,
        'subtotal' => $price,
    ]);

    return $order;
}

test('cozinha só vê pedidos com item de cozinha na fila; bar só vê pedidos com item de bar', function () {
    ['company' => $company, 'branch' => $branch, 'customer' => $customer, 'snack' => $snack, 'drink' => $drink] = stationOrderContext();

    $kitchenOnlyOrder = makeSingleItemOrder($company, $branch, $customer, $snack, 25.00);
    $barOnlyOrder = makeSingleItemOrder($company, $branch, $customer, $drink, 6.00);

    $cozinha = makeStationUser($company, 'cozinha');
    $bar = makeStationUser($company, 'bar');

    $this->actingAs($cozinha);
    Livewire::test(OrdersIndex::class)
        ->assertSee($kitchenOnlyOrder->order_number)
        ->assertDontSee($barOnlyOrder->order_number);

    $this->actingAs($bar);
    Livewire::test(OrdersIndex::class)
        ->assertSee($barOnlyOrder->order_number)
        ->assertDontSee($kitchenOnlyOrder->order_number);
});

test('pedido misto (item de cozinha + item de bar) aparece na fila das duas estações, cada uma só com seu item no ticket', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $cozinha = makeStationUser($company, 'cozinha');
    $bar = makeStationUser($company, 'bar');

    $this->actingAs($cozinha);
    Livewire::test(OrdersIndex::class)
        ->assertSee($order->order_number)
        ->assertSee('X-Burguer')
        ->assertDontSee('Refrigerante Lata');

    $this->actingAs($bar);
    Livewire::test(OrdersIndex::class)
        ->assertSee($order->order_number)
        ->assertSee('Refrigerante Lata')
        ->assertDontSee('X-Burguer');
});

test('Order::hasItemsForStation reflete os itens do pedido', function () {
    ['order' => $order] = stationOrderContext();

    expect($order->hasItemsForStation('cozinha'))->toBeTrue();
    expect($order->hasItemsForStation('bar'))->toBeTrue();
});

// ─── Fila em cards + botão de avançar status (cozinha/bar) ────────────────────

test('cozinha vê fila em cards, sem kanban, com ticket próprio e botão de avançar status', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $cozinha = makeStationUser($company, 'cozinha');

    $this->actingAs($cozinha);

    $test = Livewire::test(OrdersIndex::class)
        ->assertSet('viewMode', 'list')
        ->assertSee('Minha fila')
        ->assertSee($order->order_number)
        ->assertSee('X-Burguer')
        ->assertDontSee('Refrigerante Lata')
        ->assertSee('Iniciar preparo')
        ->assertDontSee('data-kanban-col');

    $test->call('updateOrderStatus', $order->id, 'preparing');

    expect($order->fresh()->status)->toBe('preparing');

    Livewire::test(OrdersIndex::class)->assertSee('Marcar como pronto');
});

test('bar vê fila em cards com ticket dos próprios itens, sem kanban', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $bar = makeStationUser($company, 'bar');

    $this->actingAs($bar);

    Livewire::test(OrdersIndex::class)
        ->assertSet('viewMode', 'list')
        ->assertSee($order->order_number)
        ->assertSee('Refrigerante Lata')
        ->assertDontSee('X-Burguer')
        ->assertSee('Iniciar preparo')
        ->assertDontSee('data-kanban-col');
});

test('urgência: pedido de cozinha parado há muito tempo mostra badge crítico', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $cozinha = makeStationUser($company, 'cozinha');

    $order->forceFill(['created_at' => now()->subMinutes(30)])->save();

    $this->actingAs($cozinha);

    Livewire::test(OrdersIndex::class)->assertSeeHtml('animate-pulse');
});

test('fila de cozinha/bar mostra a mesa quando o pedido é do PDV', function () {
    ['company' => $company, 'order' => $order] = stationOrderContext();
    $order->update(['table_label' => 'Mesa 5', 'is_open_tab' => true]);
    $cozinha = makeStationUser($company, 'cozinha');

    $this->actingAs($cozinha);

    Livewire::test(OrdersIndex::class)->assertSee('Mesa 5');
});

test('formatElapsed formata minutos decorridos de forma legível', function () {
    $component = new OrdersIndex;

    expect($component->formatElapsed(0))->toBe('agora');
    expect($component->formatElapsed(45))->toBe('45 min');
    expect($component->formatElapsed(60))->toBe('1h');
    expect($component->formatElapsed(75))->toBe('1h15');
});

// ─── Notificações filtradas por estação (cozinha/bar) ─────────────────────────

test('CreateOrderNotification grava is_kitchen/is_bar de acordo com os itens do pedido', function () {
    ['company' => $company, 'branch' => $branch, 'customer' => $customer, 'snack' => $snack, 'order' => $mixedOrder] = stationOrderContext();

    $kitchenOnlyOrder = makeSingleItemOrder($company, $branch, $customer, $snack, 25.00);

    app(\App\Listeners\CreateOrderNotification::class)->handle(new \App\Events\NewOrderPlaced($kitchenOnlyOrder));
    app(\App\Listeners\CreateOrderNotification::class)->handle(new \App\Events\NewOrderPlaced($mixedOrder));

    $kitchenNotif = \App\Models\CompanyNotification::where('title', 'like', '%'.$kitchenOnlyOrder->order_number.'%')->first();
    expect($kitchenNotif->is_kitchen)->toBeTrue();
    expect($kitchenNotif->is_bar)->toBeFalse();

    $mixedNotif = \App\Models\CompanyNotification::where('title', 'like', '%'.$mixedOrder->order_number.'%')->first();
    expect($mixedNotif->is_kitchen)->toBeTrue();
    expect($mixedNotif->is_bar)->toBeTrue();
});

test('NotificationBell só mostra notificações da própria estação pra cozinha e bar', function () {
    ['company' => $company] = stationOrderContext();
    $cozinha = makeStationUser($company, 'cozinha');
    $bar = makeStationUser($company, 'bar');

    \App\Models\CompanyNotification::create([
        'company_id' => $company->id,
        'type' => 'order',
        'is_kitchen' => true,
        'is_bar' => false,
        'title' => 'Novo pedido: cozinha',
    ]);

    \App\Models\CompanyNotification::create([
        'company_id' => $company->id,
        'type' => 'order',
        'is_kitchen' => false,
        'is_bar' => true,
        'title' => 'Novo pedido: bar',
    ]);

    $this->actingAs($cozinha);
    Livewire::test(\App\Livewire\Admin\NotificationBell::class)
        ->assertSee('Novo pedido: cozinha')
        ->assertDontSee('Novo pedido: bar');

    $this->actingAs($bar);
    Livewire::test(\App\Livewire\Admin\NotificationBell::class)
        ->assertSee('Novo pedido: bar')
        ->assertDontSee('Novo pedido: cozinha');
});

test('Notifications (toast) ignora pedido de outra estação pra cozinha', function () {
    ['company' => $company] = stationOrderContext();
    $cozinha = makeStationUser($company, 'cozinha');

    $this->actingAs($cozinha);

    Livewire::test(\App\Livewire\Admin\Notifications::class)
        ->call('onNewOrder', ['order_id' => 1, 'order_number' => 'X-1', 'customer_name' => 'Cliente', 'total' => 10, 'is_kitchen' => false, 'is_bar' => true])
        ->assertSet('notifications', []);

    Livewire::test(\App\Livewire\Admin\Notifications::class)
        ->call('onNewOrder', ['order_id' => 2, 'order_number' => 'X-2', 'customer_name' => 'Cliente', 'total' => 10, 'is_kitchen' => true, 'is_bar' => false])
        ->assertCount('notifications', 1);
});

// ─── "Minha fila" (Orders/Index) imprime quando garçom finaliza a comanda ─────
// Cozinha/bar não têm pdv.operate nem pdv.waiter_operate — não acessam o
// Terminal/Mesas do PDV, então "Minha fila" precisa do próprio listener de
// TabOrderSentToProduction pra imprimir (ver HasAutoPrint pro equivalente no PDV).

test('getListeners inclui TabOrderSentToProduction pra cozinha/bar, mas não pra entrega nem admin', function () {
    ['company' => $company] = stationOrderContext();
    $cozinha = makeStationUser($company, 'cozinha');
    $bar = makeStationUser($company, 'bar');
    $entrega = makeStationUser($company, 'entrega');

    $this->actingAs($cozinha);
    $listeners = Livewire::test(OrdersIndex::class)->instance()->getListeners();
    expect($listeners)->toHaveKey("echo:orders.{$company->id},TabOrderSentToProduction");

    $this->actingAs($bar);
    $listeners = Livewire::test(OrdersIndex::class)->instance()->getListeners();
    expect($listeners)->toHaveKey("echo:orders.{$company->id},TabOrderSentToProduction");

    $this->actingAs($entrega);
    $listeners = Livewire::test(OrdersIndex::class)->instance()->getListeners();
    expect($listeners)->not->toHaveKey("echo:orders.{$company->id},TabOrderSentToProduction");
});

test('cozinha recebe broadcast de comanda finalizada e dispara tab-order-finalized só com a estação dela', function () {
    ['company' => $company] = stationOrderContext();
    $cozinha = makeStationUser($company, 'cozinha');

    $this->actingAs($cozinha);

    Livewire::test(OrdersIndex::class)
        ->call('onTabOrderSentToProductionBroadcast', [
            'order_id' => 777,
            'branch_id' => 1,
            'stations' => ['geral', 'cozinha', 'bar'],
        ])
        ->assertDispatched('tab-order-finalized', fn ($name, $params) => $params['orderId'] === 777
            && collect($params['stations'])->values()->all() === ['cozinha']);
});

test('bar ignora broadcast sem estação bar', function () {
    ['company' => $company] = stationOrderContext();
    $bar = makeStationUser($company, 'bar');

    $this->actingAs($bar);

    Livewire::test(OrdersIndex::class)
        ->call('onTabOrderSentToProductionBroadcast', [
            'order_id' => 778,
            'branch_id' => 1,
            'stations' => ['geral', 'cozinha'],
        ])
        ->assertNotDispatched('tab-order-finalized');
});

test('cozinha com filial associada ignora broadcast de outra filial', function () {
    ['company' => $company, 'branch' => $branch] = stationOrderContext();
    $cozinha = User::factory()->create();
    $cozinha->companies()->attach($company->id, ['role' => 'cozinha', 'branch_id' => $branch->id]);

    $this->actingAs($cozinha);

    Livewire::test(OrdersIndex::class)
        ->call('onTabOrderSentToProductionBroadcast', [
            'order_id' => 779,
            'branch_id' => $branch->id + 999,
            'stations' => ['cozinha'],
        ])
        ->assertNotDispatched('tab-order-finalized');

    Livewire::test(OrdersIndex::class)
        ->call('onTabOrderSentToProductionBroadcast', [
            'order_id' => 780,
            'branch_id' => $branch->id,
            'stations' => ['cozinha'],
        ])
        ->assertDispatched('tab-order-finalized', orderId: 780);
});
