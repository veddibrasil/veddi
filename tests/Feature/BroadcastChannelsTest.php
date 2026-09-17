<?php

use App\Events\OrderStatusUpdated;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * `routes/channels.php` só é lido uma vez, no boot, contra o driver de
 * broadcasting padrão daquele momento (`null` em teste). Pra exercitar de
 * fato o authorizer (e não o NullBroadcaster, que nem chama o callback),
 * trocamos pra 'reverb' (mesma classe PusherBroadcaster usada em produção,
 * já que Reverb fala o protocolo Pusher) e recarregamos o arquivo de rotas
 * contra esse driver antes de bater em /broadcasting/auth.
 */
function useRealBroadcastAuthorizers(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'testkey',
        'broadcasting.connections.reverb.secret' => 'testsecret',
        'broadcasting.connections.reverb.app_id' => 'testapp',
    ]);
    require base_path('routes/channels.php');
}

function broadcastAuthCompany(string $suffix): Company
{
    return Company::create([
        'name' => 'Empresa Broadcast '.$suffix,
        'slug' => 'empresa-broadcast-'.$suffix.'-'.uniqid(),
        'order_prefix' => 'BC'.strtoupper($suffix),
        'active' => true,
    ]);
}

test('canal orders.{companyId} autoriza staff da própria empresa e nega staff de outra empresa', function () {
    useRealBroadcastAuthorizers();

    $companyA = broadcastAuthCompany('a');
    $companyB = broadcastAuthCompany('b');

    $staffA = User::factory()->create();
    $staffA->companies()->attach($companyA->id, ['role' => 'company_admin']);

    $this->actingAs($staffA)->post('/broadcasting/auth', [
        'channel_name' => 'private-orders.'.$companyA->id,
        'socket_id' => '1.1',
    ])->assertOk();

    $this->actingAs($staffA)->post('/broadcasting/auth', [
        'channel_name' => 'private-orders.'.$companyB->id,
        'socket_id' => '1.1',
    ])->assertForbidden();
});

test('canal orders.{companyId} nega convidado não autenticado', function () {
    useRealBroadcastAuthorizers();

    $company = broadcastAuthCompany('c');

    $this->post('/broadcasting/auth', [
        'channel_name' => 'private-orders.'.$company->id,
        'socket_id' => '1.1',
    ])->assertForbidden();
});

test('canal order.{orderId} autoriza staff da empresa dona do pedido e nega staff de outra empresa', function () {
    useRealBroadcastAuthorizers();

    $companyA = broadcastAuthCompany('d');
    $companyB = broadcastAuthCompany('e');

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $companyA->id,
        'name' => 'Filial',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);
    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $companyA->id,
        'name' => 'Cliente',
        'phone' => '11999999999',
    ]);
    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $companyA->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'status' => 'pending',
        'subtotal' => 10,
        'total' => 10,
        'order_number' => 'BC-0001',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
    ]);

    $staffA = User::factory()->create();
    $staffA->companies()->attach($companyA->id, ['role' => 'company_admin']);

    $staffB = User::factory()->create();
    $staffB->companies()->attach($companyB->id, ['role' => 'company_admin']);

    $this->actingAs($staffA)->post('/broadcasting/auth', [
        'channel_name' => 'private-order.'.$order->id,
        'socket_id' => '1.1',
    ])->assertOk();

    $this->actingAs($staffB)->post('/broadcasting/auth', [
        'channel_name' => 'private-order.'.$order->id,
        'socket_id' => '1.1',
    ])->assertForbidden();
});

test('canal order.{orderId} nega convidado não autenticado, mesmo sabendo o id do pedido', function () {
    useRealBroadcastAuthorizers();

    $company = broadcastAuthCompany('f');
    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);
    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente',
        'phone' => '11999999999',
    ]);
    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'status' => 'pending',
        'subtotal' => 10,
        'total' => 10,
        'order_number' => 'BC-0002',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
    ]);

    $this->post('/broadcasting/auth', [
        'channel_name' => 'private-order.'.$order->id,
        'socket_id' => '1.1',
    ])->assertForbidden();
});

test('canal wallet.{companyId} autoriza company_admin e super admin, mas nega papel operacional', function () {
    useRealBroadcastAuthorizers();

    $company = broadcastAuthCompany('g');

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $cozinha = User::factory()->create();
    $cozinha->companies()->attach($company->id, ['role' => 'cozinha']);

    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($admin)->post('/broadcasting/auth', [
        'channel_name' => 'private-wallet.'.$company->id,
        'socket_id' => '1.1',
    ])->assertOk();

    $this->actingAs($superAdmin)->post('/broadcasting/auth', [
        'channel_name' => 'private-wallet.'.$company->id,
        'socket_id' => '1.1',
    ])->assertOk();

    $this->actingAs($cozinha)->post('/broadcasting/auth', [
        'channel_name' => 'private-wallet.'.$company->id,
        'socket_id' => '1.1',
    ])->assertForbidden();
});

test('OrderStatusUpdated publica tanto no canal privado quanto num canal público mínimo, pro chat convidado', function () {
    $order = new Order;
    $order->id = 42;
    $order->company_id = 7;

    $channels = (new OrderStatusUpdated($order))->broadcastOn();

    expect($channels)->toHaveCount(3);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-order.42');
    expect($channels[1])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[1]->name)->toBe('private-orders.7');
    expect($channels[2])->toBeInstanceOf(Channel::class)
        ->not->toBeInstanceOf(PrivateChannel::class);
    expect($channels[2]->name)->toBe('order.42');
});
