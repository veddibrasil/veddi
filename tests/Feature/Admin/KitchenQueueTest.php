<?php

use App\Events\NewOrderPlaced;
use App\Events\OrderAcceptedForProduction;
use App\Jobs\NotifyScheduledOrderJob;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Orders\Show;
use App\Models\Address;
use App\Models\Branch;
use App\Models\BranchPrinter;
use App\Models\Company;
use App\Models\CompanyNotification;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Order\ChatOrderAcceptance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Empresa com uma filial, um produto de cozinha e um de bar, e usuários de estação.
 *
 * @return array{company: Company, branch: Branch, customer: Customer, kitchen: Product, drink: Product, cozinha: User, bar: User}
 */
function kitchenQueueContext(): array
{
    $company = Company::create(['name' => 'Coxinharia Fila', 'slug' => 'coxinharia-fila', 'order_prefix' => 'CF', 'active' => true]);
    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Centro', 'address' => 'Rua A, 1', 'city' => 'SP',
        'active' => true, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Joana Lima', 'phone' => '11987654321']);

    $kitchenCategory = ProductCategory::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Salgados', 'station' => 'cozinha']);
    $barCategory = ProductCategory::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Bebidas', 'station' => 'bar']);

    $users = [];
    foreach (['cozinha', 'bar'] as $role) {
        $users[$role] = User::factory()->create();
        $users[$role]->companies()->attach($company->id, ['role' => $role]);
    }

    return [
        'company' => $company,
        'branch' => $branch,
        'customer' => $customer,
        'kitchen' => Product::withoutGlobalScopes()->create(['company_id' => $company->id, 'product_category_id' => $kitchenCategory->id, 'name' => 'Coxinha', 'price' => 10, 'active' => true]),
        'drink' => Product::withoutGlobalScopes()->create(['company_id' => $company->id, 'product_category_id' => $barCategory->id, 'name' => 'Suco', 'price' => 8, 'active' => true]),
        ...$users,
    ];
}

/** Pedido com um item (cozinha por padrão). Entrega ganha endereço; chat aceito com $accepted. */
function kitchenQueueOrder(array $ctx, array $attributes = [], string $product = 'kitchen', bool $accepted = false): Order
{
    $attributes += ['order_type' => 'delivery', 'status' => 'preparing', 'payment_method' => 'pix'];

    if ($attributes['order_type'] === 'delivery' && ! array_key_exists('delivery_address_id', $attributes)) {
        $attributes['delivery_address_id'] = Address::create(['line1' => 'Rua das Flores', 'number' => '10', 'neighborhood' => 'Vila Nova', 'city' => 'SP'])->id;
    }

    $order = Order::withoutGlobalScopes()->create($attributes + [
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'customer_id' => $ctx['customer']->id,
        'subtotal' => 30, 'delivery_fee' => 5, 'total' => 35, 'fee' => 0, 'net_value' => 35,
        'notes' => '',
    ]);

    if ($accepted) {
        $order->forceFill(['chat_accepted_at' => now()])->saveQuietly();
    }

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $ctx[$product]->id,
        'product_name' => $ctx[$product]->name,
        'unit_price' => 10, 'quantity' => 1, 'subtotal' => 10,
    ]);

    return $order->fresh();
}

function backdate(Order $order, string $created): Order
{
    Order::withoutGlobalScopes()->whereKey($order->id)->update(['created_at' => $created, 'updated_at' => $created]);

    return $order->fresh();
}

// ─── Fila: entrega no topo, janela de horas, abas ─────────────────────────────

test('entrega fica na lista própria no topo, mesmo com muita venda de balcão do PDV', function () {
    $ctx = kitchenQueueContext();

    foreach (range(1, 25) as $i) {
        kitchenQueueOrder($ctx, ['order_type' => 'pdv', 'status' => 'paid']);
    }
    $delivery = backdate(kitchenQueueOrder($ctx, ['status' => 'paid', 'payment_on_delivery' => true, 'payment_method' => 'cash'], accepted: true), now()->subMinutes(40));

    $this->actingAs($ctx['cozinha']);

    Livewire::test(OrdersIndex::class)
        ->assertViewHas('stationCounts', fn ($counts) => $counts['delivery'] === 1 && $counts['counter'] === 25)
        ->assertSeeInOrder(['Entregas e retiradas', $delivery->order_number, 'Balcão e mesas'])
        ->assertSee('🛵 ENTREGA')
        ->assertSee('Vila Nova');
});

test('aba Entregas mostra só entrega e retirada, em ordem de chegada', function () {
    $ctx = kitchenQueueContext();

    $newer = kitchenQueueOrder($ctx);
    $older = backdate(kitchenQueueOrder($ctx, ['order_type' => 'pickup', 'delivery_address_id' => null]), now()->subMinutes(30));
    $counter = kitchenQueueOrder($ctx, ['order_type' => 'pdv', 'status' => 'paid']);

    $this->actingAs($ctx['cozinha']);

    Livewire::test(OrdersIndex::class)
        ->set('statusFilter', 'delivery')
        ->assertSeeInOrder([$older->order_number, $newer->order_number])
        ->assertSee('🥡 RETIRADA')
        ->assertDontSee($counter->order_number);
});

test('entrega aguardando aceite fica antes das já aceitas, mesmo sendo a mais nova', function () {
    $ctx = kitchenQueueContext();

    $accepted = backdate(kitchenQueueOrder($ctx, ['status' => 'preparing'], accepted: true), now()->subMinutes(40));
    $awaiting = kitchenQueueOrder($ctx, ['status' => 'paid', 'payment_method' => 'pix']);

    $this->actingAs($ctx['cozinha']);

    Livewire::test(OrdersIndex::class)
        ->assertSeeInOrder([$awaiting->order_number, $accepted->order_number]);
});

test('fila deixa de fora pedido antigo, finalizado e PIX ainda não pago; a busca acha o antigo', function () {
    $ctx = kitchenQueueContext();

    $old = backdate(kitchenQueueOrder($ctx, ['order_type' => 'pdv', 'status' => 'paid']), now()->subHours(13));
    $delivered = kitchenQueueOrder($ctx, ['status' => 'delivered'], accepted: true);
    $unpaid = kitchenQueueOrder($ctx, ['status' => 'awaiting_payment', 'payment_method' => 'pix']);
    $active = kitchenQueueOrder($ctx, ['order_type' => 'pdv', 'status' => 'paid']);

    $this->actingAs($ctx['cozinha']);

    Livewire::test(OrdersIndex::class)
        ->assertSee($active->order_number)
        ->assertDontSee($old->order_number)
        ->assertDontSee($delivered->order_number)
        ->assertDontSee($unpaid->order_number)
        ->set('search', $old->order_number)
        ->assertSee($old->order_number)
        ->assertSee('Resultado da busca');
});

test('agendado longe da hora vai para Agendados; perto da hora entra em Agora com o horário', function () {
    $ctx = kitchenQueueContext();

    $later = kitchenQueueOrder($ctx, ['status' => 'scheduled', 'scheduled_at' => now()->addHours(3)], accepted: true);
    $soon = kitchenQueueOrder($ctx, ['status' => 'scheduled', 'scheduled_at' => now()->addMinutes(20)], accepted: true);

    $this->actingAs($ctx['cozinha']);

    Livewire::test(OrdersIndex::class)
        ->assertSee($soon->order_number)
        ->assertSee('⏰ '.$soon->scheduled_at->format('H:i'))
        ->assertDontSee($later->order_number)
        ->assertSee('Iniciar preparo')
        ->set('statusFilter', 'scheduled')
        ->assertSee($later->order_number)
        ->assertDontSee($soon->order_number);
});

test('card de entrega mostra o que cobrar e o troco', function () {
    $ctx = kitchenQueueContext();

    kitchenQueueOrder($ctx, [
        'status' => 'paid', 'payment_method' => 'cash', 'payment_on_delivery' => true, 'notes' => 'Troco para R$ 50,00',
    ], accepted: true);

    $this->actingAs($ctx['cozinha']);

    Livewire::test(OrdersIndex::class)
        ->assertSee('Cobrar R$ 35,00 · Dinheiro')
        ->assertSee('Troco para R$ 50,00 (levar R$ 15,00)');
});

test('busca acha pelo telefone do cliente e pelo número curto do marketplace', function () {
    $ctx = kitchenQueueContext();

    $byPhone = backdate(kitchenQueueOrder($ctx, ['status' => 'delivered']), now()->subDays(2));
    $ifood = kitchenQueueOrder($ctx, [
        'channel' => 'ifood', 'payment_method' => 'ifood', 'status' => 'delivered',
        'external_metadata' => ['display_id' => '7781', 'ifood_status' => 'CONCLUDED'],
    ]);

    $this->actingAs($ctx['cozinha']);

    Livewire::test(OrdersIndex::class)
        ->set('search', '98765-4321')
        ->assertSee($byPhone->order_number)
        ->set('search', '7781')
        ->assertSee('iFood #7781');
});

// ─── Cozinha solta a entrega ──────────────────────────────────────────────────

test('cozinha marca saiu para entrega no pedido de entrega pronto', function () {
    $ctx = kitchenQueueContext();
    $order = kitchenQueueOrder($ctx, ['status' => 'ready'], accepted: true);

    $this->actingAs($ctx['cozinha']);

    Livewire::test(OrdersIndex::class)
        ->assertSee('Saiu para entrega')
        ->call('updateOrderStatus', $order->id, 'out_for_delivery');

    expect($order->fresh()->status)->toBe('out_for_delivery');
});

test('retirada pronta não oferece saiu para entrega e bar não despacha', function () {
    $ctx = kitchenQueueContext();
    $pickup = kitchenQueueOrder($ctx, ['order_type' => 'pickup', 'delivery_address_id' => null, 'status' => 'ready'], accepted: true);
    $drinks = kitchenQueueOrder($ctx, ['status' => 'ready'], 'drink', accepted: true);

    $this->actingAs($ctx['cozinha']);
    Livewire::test(OrdersIndex::class)
        ->set('statusFilter', 'ready')
        ->assertSee($pickup->order_number)
        ->assertDontSee('Saiu para entrega');

    $this->actingAs($ctx['bar']);
    Livewire::test(OrdersIndex::class)
        ->call('updateOrderStatus', $drinks->id, 'out_for_delivery');

    expect($drinks->fresh()->status)->toBe('ready');
});

test('cozinha não volta etapa: pedido entregue não vira preparando (fila e detalhe)', function () {
    $ctx = kitchenQueueContext();
    $order = kitchenQueueOrder($ctx, ['status' => 'delivered'], accepted: true);

    $this->actingAs($ctx['cozinha']);

    Livewire::test(OrdersIndex::class)->call('updateOrderStatus', $order->id, 'preparing');
    expect($order->fresh()->status)->toBe('delivered');

    Livewire::test(Show::class, ['order' => $order])
        ->call('updateStatus', 'preparing')
        ->assertHasErrors('status');
    expect($order->fresh()->status)->toBe('delivered');
});

test('detalhe do pedido: cozinha vê e usa "A caminho" só em pedido de entrega', function () {
    $ctx = kitchenQueueContext();
    $delivery = kitchenQueueOrder($ctx, ['status' => 'ready'], accepted: true);
    $counter = kitchenQueueOrder($ctx, ['order_type' => 'pdv', 'status' => 'ready']);

    $this->actingAs($ctx['cozinha']);

    expect(Livewire::test(Show::class, ['order' => $delivery])->instance()->visibleStatusMap())->toHaveKey('out_for_delivery');
    expect(Livewire::test(Show::class, ['order' => $counter])->instance()->visibleStatusMap())->not->toHaveKey('out_for_delivery');

    Livewire::test(Show::class, ['order' => $delivery])->call('updateStatus', 'out_for_delivery');
    expect($delivery->fresh()->status)->toBe('out_for_delivery');
});

test('cozinha não age em pedido que só tem item do bar', function () {
    $ctx = kitchenQueueContext();
    $drinks = kitchenQueueOrder($ctx, ['order_type' => 'pdv', 'status' => 'paid'], 'drink');

    $this->actingAs($ctx['cozinha']);

    expect(fn () => Livewire::test(OrdersIndex::class)->call('updateOrderStatus', $drinks->id, 'preparing'))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($drinks->fresh()->status)->toBe('paid');
});

// ─── Impressão no aceite ──────────────────────────────────────────────────────

test('aceite do chat dispara a via de produção uma vez; pedido do chat não imprime na chegada', function () {
    $ctx = kitchenQueueContext();
    $order = kitchenQueueOrder($ctx, ['status' => 'paid', 'payment_method' => 'cash', 'payment_on_delivery' => true]);

    expect((new NewOrderPlaced($order))->printOnArrival)->toBeFalse();

    Event::fake([OrderAcceptedForProduction::class]);

    $admin = User::factory()->create();
    $admin->companies()->attach($ctx['company']->id, ['role' => 'company_admin']);
    app(ChatOrderAcceptance::class)->accept($order, $admin);
    app(ChatOrderAcceptance::class)->accept($order->fresh(), $admin);

    Event::assertDispatchedTimes(OrderAcceptedForProduction::class, 1);
    Event::assertDispatched(OrderAcceptedForProduction::class, fn ($event) => $event->order->is($order) && $event->isKitchen && ! $event->isBar);
});

test('marketplace confirmado fora do painel também dispara a via; cancelado não', function () {
    $ctx = kitchenQueueContext();
    $confirmed = kitchenQueueOrder($ctx, ['channel' => 'ifood', 'payment_method' => 'ifood', 'status' => 'pending', 'external_metadata' => ['ifood_status' => 'PLACED']]);
    $cancelled = kitchenQueueOrder($ctx, ['channel' => 'ifood', 'payment_method' => 'ifood', 'status' => 'pending', 'external_metadata' => ['ifood_status' => 'PLACED']]);

    Event::fake([OrderAcceptedForProduction::class]);

    $confirmed->update(['status' => 'preparing', 'external_metadata' => ['ifood_status' => 'CONFIRMED']]);
    $cancelled->update(['status' => 'cancelled', 'external_metadata' => ['ifood_status' => 'CANCELLED']]);

    Event::assertDispatchedTimes(OrderAcceptedForProduction::class, 1);
    Event::assertDispatched(OrderAcceptedForProduction::class, fn ($event) => $event->order->is($confirmed));
});

test('fila da cozinha imprime o pedido aceito na impressora automática da estação', function () {
    $ctx = kitchenQueueContext();
    $payload = ['order_id' => 321, 'branch_id' => $ctx['branch']->id, 'order_type' => 'delivery', 'is_kitchen' => true, 'is_bar' => false];

    $this->actingAs($ctx['cozinha']);

    Livewire::test(OrdersIndex::class)
        ->call('onOrderAcceptedBroadcast', $payload)
        ->assertNotDispatched('production-ticket-ready');

    BranchPrinter::create([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'station' => 'cozinha',
        'ip_address' => '192.168.0.30', 'port' => 9100, 'paper_width' => 80, 'auto_print' => true, 'active' => true,
    ]);

    Livewire::test(OrdersIndex::class)
        ->call('onOrderAcceptedBroadcast', $payload)
        ->assertDispatched('production-ticket-ready', orderId: 321, stations: ['cozinha'])
        ->call('onNewOrderBroadcast', $payload + ['print_on_arrival' => false])
        ->assertNotDispatched('production-ticket-ready')
        ->call('onNewOrderBroadcast', $payload + ['print_on_arrival' => true])
        ->assertDispatched('production-ticket-ready', orderId: 321);

    $this->actingAs($ctx['bar']);
    Livewire::test(OrdersIndex::class)
        ->call('onOrderAcceptedBroadcast', $payload)
        ->assertNotDispatched('production-ticket-ready');
});

// ─── Aviso de agendado ────────────────────────────────────────────────────────

test('aviso "hora de preparar" do agendado chega no sino da cozinha', function () {
    $ctx = kitchenQueueContext();
    $order = kitchenQueueOrder($ctx, ['status' => 'scheduled', 'scheduled_at' => now()->addMinutes(15)], accepted: true);

    (new NotifyScheduledOrderJob($order->id))->handle();

    $notification = CompanyNotification::withoutGlobalScopes()->where('type', 'scheduled_order')->sole();
    expect($notification->is_kitchen)->toBeTrue()
        ->and($notification->is_bar)->toBeFalse()
        ->and($notification->is_delivery)->toBeTrue();
});

// ─── Tamanho do cupom em PDF ──────────────────────────────────────────────────

test('cupom da cozinha sem impressora própria sai na largura da impressora geral, igual aos outros', function () {
    $ctx = kitchenQueueContext();
    $order = kitchenQueueOrder($ctx, ['status' => 'preparing'], accepted: true);

    BranchPrinter::create([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'station' => 'geral',
        'ip_address' => '192.168.0.40', 'port' => 9100, 'paper_width' => 58, 'auto_print' => false, 'active' => true,
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($ctx['company']->id, ['role' => 'company_admin']);
    $this->actingAs($admin);

    $mediaBoxWidth = function (string $url): float {
        preg_match('#/MediaBox \[[\d.]+ [\d.]+ ([\d.]+)#', $this->get($url)->assertOk()->getContent(), $match);

        return round((float) ($match[1] ?? 0), 1);
    };

    $width58 = round(58 * 72 / 25.4, 1);
    expect($mediaBoxWidth("/admin/orders/{$order->id}/receipt"))->toBe($width58)
        ->and($mediaBoxWidth("/admin/orders/{$order->id}/receipt/cozinha"))->toBe($width58)
        ->and($mediaBoxWidth("/admin/orders/{$order->id}/receipt/entrega"))->toBe($width58);

    // Impressora própria da estação continua mandando na largura.
    BranchPrinter::create([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'station' => 'cozinha',
        'ip_address' => '192.168.0.41', 'port' => 9100, 'paper_width' => 80, 'auto_print' => true, 'active' => true,
    ]);
    expect($mediaBoxWidth("/admin/orders/{$order->id}/receipt/cozinha"))->toBe(round(80 * 72 / 25.4, 1));
});
