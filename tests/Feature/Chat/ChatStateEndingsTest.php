<?php

require_once __DIR__.'/endings_helpers.php';

use App\Jobs\ProcessOrder;
use App\Livewire\Chat\OrderChat;
use App\Models\Order;
use App\Services\Chat\ChatAccess;
use Illuminate\Support\Facades\Queue;
use Livewire\Drawer\Utils;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\NewOrderPlaced::class, \App\Events\OrderStatusUpdated::class]);
    Queue::fake();
});

test('identidade expirada sem grant descarta pedido e chave, permitindo pedido novo', function () {
    $ctx = flowAuditContext(stock: null);
    $old = flowAuditChatOrder($ctx);
    $chat = endingsChat($ctx, $old);
    $chat->checkoutKey = 'old-checkout';
    $chat->trackingLink = 'old-link';
    session()->forget('chat_orders');
    session()->forget('chat_identity');
    $chat->hydrate();
    expect($chat->orderId)->toBeNull()->and($chat->trackingLink)->toBeNull()->and($chat->checkoutKey)->not->toBe('old-checkout');
    $chat->continueAsGuest();
    $chat->name = 'Outro pedido';
    $chat->submitName();
    $chat->taxId = $ctx['customer']->tax_id;
    $chat->confirmOrder();
    expect($chat->orderId)->not->toBeNull()->not->toBe($old->id);
});

test('identidade expirada preserva acompanhamento quando grant continua válido', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $chat = endingsChat($ctx, $order);
    session()->forget('chat_identity');
    $chat->hydrate();
    expect($chat->customerId)->toBeNull()->and($chat->orderId)->toBe($order->id)->and($chat->step)->toBe('ORDER_CONFIRMED');
});

test('etapas avançadas saem da tela de pagamento mesmo se já notificadas', function (string $status) {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: $status);
    $chat = endingsChat($ctx, $order);
    $chat->step = 'PAYMENT_PIX';
    $chat->lastNotifiedStatus = $status;
    $chat->checkPaymentStatus();
    expect($chat->step)->toBe('ORDER_CONFIRMED');
})->with(['paid', 'scheduled', 'preparing', 'ready', 'out_for_delivery', 'delivered']);

test('segunda aba da mesma sessão sem grant reutiliza pedido criado pela primeira', function () {
    $ctx = flowAuditContext(stock: null);
    $first = endingsChat($ctx);
    $second = clone $first;
    $first->checkoutKey = $second->checkoutKey = str_repeat('a', 64);
    $first->confirmOrder();
    session()->forget('chat_orders');
    session()->forget('chat_identity');
    $second->confirmOrder();
    expect(Order::withoutGlobalScopes()->count())->toBe(1)->and($second->orderId)->toBe($first->orderId);
    expect(app(ChatAccess::class)->canAccessOrder(Order::find($first->orderId), true))->toBeTrue();
    Queue::assertPushed(ProcessOrder::class, 1);
});

test('prova de sessão não concede pedido de outra empresa', function () {
    $ctx = flowAuditContext(stock: null);
    $first = endingsChat($ctx);
    $first->confirmOrder();
    $other = flowAuditContext(stock: null);
    $second = endingsChat($other);
    $second->checkoutKey = $first->checkoutKey;
    $second->confirmOrder();
    expect($second->orderId)->toBeNull()->and(Order::withoutGlobalScopes()->count())->toBe(1);

});

test('acompanhamento recarrega grant existente sem token e respeita revogação e empresa', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'preparing');
    $link = app(ChatAccess::class)->orderLink($order);
    $chat = new OrderChat;
    $chat->companyId = $ctx['company']->id;
    $chat->trackingOnly = true;
    $chat->redeemTrackingLink(explode('#pedido=', $link)[1]);
    $html = $this->get('/'.$ctx['company']->slug.'/acompanhar')->assertOk()->getContent();
    $data = Utils::extractAttributeDataFromHtml($html, 'wire:snapshot')['data'];
    expect($data['step'])->toBe('ORDER_TRACKING')->and($data['orderId'])->toBe($order->id);
    $other = flowAuditContext();
    session(["chat_tracking.{$other['company']->id}" => $order->id]);
    $html = $this->get('/'.$other['company']->slug.'/acompanhar')->assertOk()->getContent();
    expect(Utils::extractAttributeDataFromHtml($html, 'wire:snapshot')['data']['step'])->toBe('TRACKING_LINK');
    \Illuminate\Support\Facades\DB::table('chat_order_access')->where('order_id', $order->id)->update(['revoked_at' => now()]);
    $html = $this->get('/'.$ctx['company']->slug.'/acompanhar')->assertOk()->getContent();
    expect(Utils::extractAttributeDataFromHtml($html, 'wire:snapshot')['data']['step'])->toBe('TRACKING_LINK');
});

test('identidade expirada com grant conserva pagamento pendente acessível', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'awaiting_payment');
    $chat = endingsChat($ctx, $order);
    session()->forget('chat_identity');
    $chat->hydrate();
    expect($chat->orderId)->toBe($order->id)->and($chat->step)->toBe('PAYMENT_PIX');
});
