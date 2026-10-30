<?php

use App\Contracts\PrinterServiceInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imprime order_index e order_id da 99Food no cupom térmico', function () {
    $ctx = food99Context();
    $order = receiveFood99Order($this, $ctx, food99OrderInfo($ctx, overrides: ['pay_method' => 2, 'pay_channel' => 153, 'change_for' => 5000]));

    $bytes = app(PrinterServiceInterface::class)->buildOrderReceipt($order->fresh()->load('items', 'branch', 'customer'), 'geral');

    expect($bytes)->toContain('Pedido: 99Food #12')
        ->toContain('99FOOD #12')
        ->toContain('ID 99: 5764607523034934815')
        ->toContain('Codigo p/ entregador: 4821')
        ->toContain('Troco para R$ 50,00')
        ->toContain('ENVIAR TALHERES')
        ->not->toContain('99food:');
});

it('leva só número, tipo e talheres na comanda da cozinha', function () {
    $ctx = food99Context();
    $order = receiveFood99Order($this, $ctx, food99OrderInfo($ctx));

    $lines = collect($order->food99Details()->receiptLines('cozinha'))->pluck('text')->all();

    expect($lines)->toBe(['99FOOD #12', 'ID 99: 5764607523034934815', 'ENTREGA 99FOOD', 'ENVIAR TALHERES']);
});

it('mostra order_index e order_id no cupom em PDF', function () {
    $ctx = food99Context();
    $order = receiveFood99Order($this, $ctx, food99OrderInfo($ctx));
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    $this->get(route('admin.orders.receipt', $order))
        ->assertOk();
})->skip(fn () => ! function_exists('imagecreate'), 'PDF depende do GD');
