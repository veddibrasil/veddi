<?php

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessOrder;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Orders\Show;
use App\Livewire\Chat\OrderChat;
use App\Models\Company;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Chat\ChatAccess;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([OrderStatusUpdated::class, NewOrderPlaced::class]);
    Queue::fake();
});

function payOnDeliveryChat(array $ctx, string $orderType = 'pickup'): OrderChat
{
    $chat = new OrderChat;
    $chat->companyId = $ctx['company']->id;
    $chat->customerId = $ctx['customer']->id;
    verifiedChatFixture($chat, $ctx['customer']);
    $chat->selectedBranchId = $ctx['branch']->id;
    $chat->orderType = $orderType;
    $chat->taxId = '';
    $chat->cart = [(string) $ctx['product']->id => ['product_id' => $ctx['product']->id, 'qty' => 1, 'name' => 'Item', 'price' => 20.0]];

    return $chat;
}

function payOnDeliveryAdmin(Company $company): User
{
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin', 'branch_id' => null]);

    return $admin;
}

test('cliente paga ao receber: pedido segue a regra do PDV, sem CPF nem cobrança online', function (string $method, bool $onDelivery, string $stored, string $label) {
    $ctx = flowAuditContext(stock: null);
    $chat = payOnDeliveryChat($ctx);

    $chat->selectPaymentMethod($method, $onDelivery);
    expect($chat->step)->toBe('CHECKOUT_CONFIRM')
        ->and($chat->paymentChoiceLabel)->toBe($label);

    $chat->confirmOrder();

    $order = Order::withoutGlobalScopes()->findOrFail($chat->orderId);
    expect($order->status)->toBe('awaiting_payment')
        ->and($order->payment_on_delivery)->toBeTrue()
        ->and($order->payment_method)->toBe($stored)
        ->and($order->needsPdvPaymentConfirmation())->toBeTrue()
        ->and($order->payments()->exists())->toBeFalse()
        ->and($chat->step)->toBe('ORDER_CONFIRMED')
        ->and($chat->lastNotifiedStatus)->toBe('awaiting_payment');

    Queue::assertNotPushed(ProcessOrder::class);
})->with([
    'dinheiro' => ['CASH', false, 'cash', 'Dinheiro na retirada'],
    'cartão na maquininha' => ['CARD', true, 'card', 'Cartão na retirada (maquininha)'],
    'pix ao receber' => ['PIX', true, 'pix', 'PIX na retirada'],
]);

test('a forma escolhida acompanha o tipo do pedido', function () {
    $chat = payOnDeliveryChat(flowAuditContext(stock: null), 'delivery');

    $chat->selectPaymentMethod('CARD', true);

    expect($chat->paymentChoiceLabel)->toBe('Cartão na entrega (maquininha)')
        ->and(collect($chat->messages)->last()['text'])->toBe('Cartão na entrega (maquininha)');
});

test('pagar agora continua pedindo CPF e criando pedido pendente de cobrança', function (string $method) {
    $ctx = flowAuditContext(stock: null);
    $chat = payOnDeliveryChat($ctx);

    $chat->selectPaymentMethod($method);
    expect($chat->step)->toBe('CHECKOUT_CPF')
        ->and($chat->paymentOnDelivery)->toBeFalse();

    $chat->taxId = '529.982.247-25';
    $chat->confirmOrder();

    $order = Order::withoutGlobalScopes()->findOrFail($chat->orderId);
    expect($order->status)->toBe('pending')
        ->and($order->payment_on_delivery)->toBeFalse()
        ->and($order->needsPdvPaymentConfirmation())->toBeFalse();
})->with(['PIX', 'CARD']);

test('trocar a forma depois de escolher pagar ao receber não sobra como pagamento online', function () {
    $chat = payOnDeliveryChat(flowAuditContext(stock: null));

    $chat->selectPaymentMethod('PIX', true);
    $chat->backToPaymentMethod();
    expect($chat->paymentOnDelivery)->toBeFalse();

    $chat->selectPaymentMethod('PIX');
    expect($chat->step)->toBe('CHECKOUT_CPF');
});

test('confirmar de novo com a mesma chave não cria outro pedido nem manda para a tela de pagamento', function () {
    $ctx = flowAuditContext(stock: null);
    $chat = payOnDeliveryChat($ctx);
    $chat->checkoutKey = str_repeat('a', 64);
    $chat->selectPaymentMethod('CARD', true);
    $chat->confirmOrder();

    $retry = payOnDeliveryChat($ctx);
    $retry->checkoutKey = $chat->checkoutKey;
    $retry->selectPaymentMethod('CARD', true);
    $retry->confirmOrder();

    expect(Order::withoutGlobalScopes()->where('company_id', $ctx['company']->id)->count())->toBe(1)
        ->and($retry->step)->toBe('ORDER_CONFIRMED');
});

test('pedido agendado com pagamento ao receber fica agendado e a receber', function () {
    $ctx = flowAuditContext(stock: null);
    $chat = payOnDeliveryChat($ctx);
    $chat->scheduledAt = now()->addDay()->toIso8601String();
    $chat->selectPaymentMethod('PIX', true);
    $chat->confirmOrder();

    $order = Order::withoutGlobalScopes()->findOrFail($chat->orderId);
    expect($order->status)->toBe('scheduled')
        ->and($order->needsPdvPaymentConfirmation())->toBeTrue()
        ->and($chat->step)->toBe('ORDER_CONFIRMED');
});

test('acompanhamento do cliente não trata pagamento ao receber como cobrança pendente', function () {
    $ctx = flowAuditContext(stock: null);
    $chat = payOnDeliveryChat($ctx);
    $chat->selectPaymentMethod('CARD', true);
    $chat->confirmOrder();
    $messages = count($chat->messages);

    $chat->checkPaymentStatus();
    expect($chat->messages)->toHaveCount($messages);

    $order = Order::withoutGlobalScopes()->findOrFail($chat->orderId);
    expect($order->status_label)->toBe('Pagamento na retirada');

    app(PaymentOrchestrator::class)->confirmDeliveryPayment($order);
    $chat->checkPaymentStatus();

    expect(collect($chat->messages)->last()['text'])->toContain('recebido');
});

test('pedido a receber não aparece como pagamento a retomar', function () {
    $ctx = flowAuditContext(stock: null);
    $chat = payOnDeliveryChat($ctx);
    $chat->selectPaymentMethod('PIX', true);
    $chat->confirmOrder();
    $orderId = $chat->orderId;

    $next = payOnDeliveryChat($ctx);
    $next->pendingOrderSummary = ['id' => $orderId];
    $next->resumePendingOrder();

    expect($next->orderId)->toBeNull()
        ->and($next->step)->toBe('MENU_BROWSE');
});

test('confirmar recebimento no painel registra na mesma forma de pagamento do PDV', function (string $method, bool $onDelivery, string $gateway) {
    $ctx = flowAuditContext(stock: null);
    $chat = payOnDeliveryChat($ctx);
    $chat->selectPaymentMethod($method, $onDelivery);
    $chat->confirmOrder();
    $order = Order::withoutGlobalScopes()->findOrFail($chat->orderId);

    $this->actingAs(payOnDeliveryAdmin($ctx['company']));

    Livewire::test(OrdersIndex::class)->call('confirmPayment', $order->id);

    expect($order->fresh()->status)->toBe('paid')
        ->and($order->fresh()->needsPdvPaymentConfirmation())->toBeFalse()
        ->and(Payment::where('order_id', $order->id)->where('status', 'paid')->value('payment_gateway'))->toBe($gateway);
})->with([
    'dinheiro' => ['CASH', false, 'cash'],
    'cartão' => ['CARD', true, 'card_machine'],
    'pix' => ['PIX', true, 'pix_manual'],
]);

test('detalhe do pedido mostra como cobrar e confirma o recebimento', function () {
    $ctx = flowAuditContext(stock: null);
    $chat = payOnDeliveryChat($ctx);
    $chat->selectPaymentMethod('CARD', true);
    $chat->confirmOrder();
    $order = Order::withoutGlobalScopes()->findOrFail($chat->orderId);

    $this->actingAs(payOnDeliveryAdmin($ctx['company']));

    Livewire::test(Show::class, ['order' => $order])
        ->assertSee('A receber: Cartão na retirada.')
        ->call('openConfirmPaymentModal')
        ->call('confirmPayment')
        ->assertHasNoErrors();

    expect(Payment::where('order_id', $order->id)->value('payment_gateway'))->toBe('card_machine');
});

test('loja não confirma manualmente pagamento online', function () {
    $ctx = flowAuditContext(stock: null);
    $chat = payOnDeliveryChat($ctx);
    $chat->selectPaymentMethod('PIX');
    $chat->taxId = '529.982.247-25';
    $chat->confirmOrder();
    $order = Order::withoutGlobalScopes()->findOrFail($chat->orderId);
    $order->update(['status' => 'awaiting_payment']);

    $this->actingAs(payOnDeliveryAdmin($ctx['company']));

    Livewire::test(Show::class, ['order' => $order])->call('confirmPayment')->assertHasErrors('status');
    Livewire::test(OrdersIndex::class)->call('confirmPayment', $order->id);

    expect(Payment::where('order_id', $order->id)->exists())->toBeFalse()
        ->and($order->fresh()->status)->toBe('awaiting_payment');
});

test('empresa não confirma recebimento de pedido de outra empresa', function () {
    $ctxA = flowAuditContext(['name' => 'Empresa A', 'order_prefix' => 'EMA'], stock: null);
    $chat = payOnDeliveryChat($ctxA);
    $chat->selectPaymentMethod('CARD', true);
    $chat->confirmOrder();
    $orderId = $chat->orderId;

    $ctxB = flowAuditContext(['name' => 'Empresa B', 'order_prefix' => 'EMB'], stock: null);
    $this->actingAs(payOnDeliveryAdmin($ctxB['company']));

    expect(fn () => Livewire::test(OrdersIndex::class)->call('confirmPayment', $orderId))
        ->toThrow(ModelNotFoundException::class);

    expect(Payment::where('order_id', $orderId)->exists())->toBeFalse();
});

test('pedido do PDV não é alterado pela opção do chat', function () {
    $ctx = flowAuditContext(stock: null);

    $order = app(OrderService::class)->createOrder(
        customerId: $ctx['customer']->id,
        branchId: $ctx['branch']->id,
        cart: [(string) $ctx['product']->id => ['product_id' => $ctx['product']->id, 'qty' => 1, 'name' => 'Item', 'price' => 20.0]],
        notes: '',
        paymentMethod: 'credit_card',
        orderType: 'pdv',
        status: 'awaiting_payment',
    );

    expect($order->fresh()->payment_on_delivery)->toBeFalse()
        ->and($order->paymentOnDeliveryLabel())->toBeNull()
        ->and($order->needsPdvPaymentConfirmation())->toBeTrue()
        ->and($order->status_label)->toBe('Aguardando Pagamento');

    app(PaymentOrchestrator::class)->confirmDeliveryPayment($order);

    expect(Payment::where('order_id', $order->id)->value('payment_gateway'))->toBe('card_machine');
});

test('visitante também paga ao receber sem informar CPF', function () {
    $ctx = flowAuditContext(stock: null);
    $chat = new OrderChat;
    $chat->companyId = $ctx['company']->id;
    $chat->checkoutReady = true;
    $chat->phone = '43999990000';
    $chat->name = 'Visitante Teste';
    $chat->selectedBranchId = $ctx['branch']->id;
    $chat->orderType = 'pickup';
    $chat->taxId = '';
    $chat->cart = [(string) $ctx['product']->id => ['product_id' => $ctx['product']->id, 'qty' => 1, 'name' => 'Item', 'price' => 20.0]];

    $chat->selectPaymentMethod('PIX', true);
    $chat->confirmOrder();

    $order = Order::withoutGlobalScopes()->findOrFail($chat->orderId);
    expect($order->customer_id)->toBeNull()
        ->and($order->payment_on_delivery)->toBeTrue()
        ->and(app(ChatAccess::class)->canAccessOrder($order, true))->toBeTrue();
});
