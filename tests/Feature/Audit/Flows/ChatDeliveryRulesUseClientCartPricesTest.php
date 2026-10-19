<?php

require_once __DIR__.'/flows_helpers.php';

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Livewire\Chat\OrderChat;
use App\Models\DeliverySetting;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * AUDITORIA FLUXOS — pedido mínimo e "frete grátis acima de R$ X" do delivery usam o preço que
 * veio do NAVEGADOR. HasPaymentFlow::reauthorizeDeliveryFee() recalcula a taxa no servidor, mas
 * passa `$this->cartTotal` para DeliveryService::validate(); cartTotal soma `cart.*.price` e
 * `options.*.selections.*.additional_price`, que ficam na propriedade pública `$cart`
 * (sem #[Locked]). O OrderService recalcula o subtotal pelo banco, mas mantém a taxa de entrega
 * decidida com o subtotal forjado. Basta um `$set('cart.<id>.price', 999)` no payload Livewire.
 */

beforeEach(function () {
    Event::fake([OrderStatusUpdated::class, NewOrderPlaced::class]);
    Queue::fake();
});

function flowAuditDeliveryChat(array $ctx, float $tamperedPrice): OrderChat
{
    $chat = new OrderChat;
    $chat->companyId = $ctx['company']->id;
    $chat->customerId = $ctx['customer']->id;
    $chat->selectedBranchId = $ctx['branch']->id;
    $chat->orderType = 'delivery';
    $chat->paymentMethod = 'CASH';
    $chat->neighborhood = 'Centro';
    $chat->cart = [
        (string) $ctx['product']->id => [
            'product_id' => $ctx['product']->id,
            'qty' => 1,
            'name' => 'Coxinha',
            'price' => $tamperedPrice, // real: R$ 20,00
        ],
    ];

    return $chat;
}

test('frete grátis acima de R$ 100 não vale para pedido real de R$ 20 com preço adulterado no carrinho', function () {
    $ctx = flowAuditContext(stock: null, price: 20.0);
    DeliverySetting::create([
        'branch_id' => $ctx['branch']->id,
        'company_id' => $ctx['company']->id,
        'fee_type' => 'flat',
        'flat_fee' => 12.00,
        'minimum_order_value' => 0,
        'free_delivery_above' => 100.00,
        'active' => true,
    ]);

    flowAuditDeliveryChat($ctx, tamperedPrice: 999.0)->confirmOrder();

    $order = Order::withoutGlobalScopes()->where('company_id', $ctx['company']->id)->latest('id')->firstOrFail();

    expect((float) $order->subtotal)->toBe(20.0);      // subtotal sai do banco (ok)
    expect((float) $order->delivery_fee)->toBe(12.0);  // hoje: 0.0 (frete grátis indevido)
});

test('pedido mínimo do delivery é validado com o subtotal real, não com o preço do navegador', function () {
    $ctx = flowAuditContext(stock: null, price: 20.0);
    DeliverySetting::create([
        'branch_id' => $ctx['branch']->id,
        'company_id' => $ctx['company']->id,
        'fee_type' => 'flat',
        'flat_fee' => 8.00,
        'minimum_order_value' => 50.00,
        'active' => true,
    ]);

    flowAuditDeliveryChat($ctx, tamperedPrice: 60.0)->confirmOrder();

    // Esperado: nenhum pedido (mínimo R$ 50, pedido real R$ 20). Hoje: pedido criado.
    expect(Order::withoutGlobalScopes()->where('company_id', $ctx['company']->id)->count())->toBe(0);
});

test('controle: o navegador consegue reescrever o preço do item no carrinho do chat (cart não é #[Locked])', function () {
    $ctx = flowAuditContext(stock: null, price: 20.0);

    $html = $this->get('/'.$ctx['company']->slug)->assertOk()->getContent();
    $snapshot = \Livewire\Drawer\Utils::extractAttributeDataFromHtml($html, 'wire:snapshot');
    expect($snapshot['memo']['name'] ?? null)->toBe('chat.order-chat');

    $this->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'calls' => [],
            'updates' => ['cart' => [(string) $ctx['product']->id => [
                'product_id' => $ctx['product']->id, 'qty' => 1, 'name' => 'Coxinha', 'price' => 999,
            ]]],
        ]],
    ])->assertOk();
});
