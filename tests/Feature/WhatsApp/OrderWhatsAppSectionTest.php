<?php

use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function whatsappOrderAdmin(\App\Models\Company $company, string $role = 'company_admin'): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => $role]);

    return $user;
}

test('detalhe do pedido lista as notificações de WhatsApp com evento, status, horário e erro traduzido', function () {
    ['company' => $company, 'order' => $order, 'connection' => $connection] = whatsappOrderContext();
    $admin = whatsappOrderAdmin($company);

    WhatsAppMessage::factory()->sent()->create([
        'company_id' => $company->id,
        'whatsapp_connection_id' => $connection->id,
        'order_id' => $order->id,
        'event' => 'new_order',
        'template' => 'pedido_recebido',
        'sent_at' => now()->setDate(2026, 9, 23)->setTime(14, 5),
    ]);
    WhatsAppMessage::factory()->failed('131026', 'Message undeliverable')->create([
        'company_id' => $company->id,
        'whatsapp_connection_id' => $connection->id,
        'order_id' => $order->id,
        'event' => 'preparing',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('Notificações WhatsApp')
        ->assertSee('Pedido recebido')
        ->assertSee('Enviada')
        ->assertSee('23/09/2026 14:05')
        ->assertSee('Em preparo')
        ->assertSee('Falhou')
        ->assertSee('o cliente pode não ter WhatsApp neste número')
        ->assertDontSee('Message undeliverable');
});

test('a seção não expõe o telefone do cliente', function () {
    ['company' => $company, 'order' => $order, 'connection' => $connection] = whatsappOrderContext();
    $admin = whatsappOrderAdmin($company);

    WhatsAppMessage::factory()->sent()->create([
        'company_id' => $company->id,
        'whatsapp_connection_id' => $connection->id,
        'order_id' => $order->id,
        'event' => 'new_order',
        'to_phone' => '5511987654321',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.orders.show', $order))
        ->assertSee('Notificações WhatsApp')
        ->assertDontSee('5511987654321')
        ->assertDontSee('987654321');
});

test('pedido sem notificações não mostra a seção', function () {
    ['company' => $company, 'order' => $order] = whatsappOrderContext();
    $admin = whatsappOrderAdmin($company);

    $this->actingAs($admin)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertDontSee('Notificações WhatsApp');
});

test('a seção mostra só as notificações do próprio pedido', function () {
    ['company' => $company, 'order' => $order, 'connection' => $connection, 'customer' => $customer, 'branch' => $branch] = whatsappOrderContext();
    $admin = whatsappOrderAdmin($company);

    $outroPedido = \App\Models\Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 10, 'total' => 10, 'status' => 'delivered', 'payment_method' => 'pix', 'order_type' => 'delivery',
    ]);

    WhatsAppMessage::factory()->sent()->create([
        'company_id' => $company->id, 'whatsapp_connection_id' => $connection->id,
        'order_id' => $outroPedido->id, 'event' => 'delivered',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertDontSee('Notificações WhatsApp');
});

test('cozinha, bar e entrega não veem as notificações de WhatsApp', function (string $station) {
    ['company' => $company, 'order' => $order, 'connection' => $connection] = whatsappOrderContext();
    $user = whatsappOrderAdmin($company, $station);

    WhatsAppMessage::factory()->sent()->create([
        'company_id' => $company->id, 'whatsapp_connection_id' => $connection->id,
        'order_id' => $order->id, 'event' => 'new_order',
    ]);

    $this->actingAs($user)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertDontSee('Notificações WhatsApp');
})->with(['cozinha', 'bar', 'entrega']);
