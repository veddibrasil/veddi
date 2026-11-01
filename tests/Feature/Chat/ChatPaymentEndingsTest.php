<?php

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessOrder;
use App\Jobs\ProcessVindiWebhook;
use App\Jobs\ResolveExpiredVindiPaymentsJob;
use App\Livewire\Chat\OrderChat;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Chat\ChatAccess;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentOrchestrator;
use App\Services\Payment\PaymentService;
use App\Services\Payment\VindiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    Queue::fake();
    Http::preventStrayRequests();
});

function endingsChat(array $ctx, ?Order $order = null): OrderChat
{
    $chat = new OrderChat;
    $chat->companyId = $ctx['company']->id;
    $chat->selectedBranchId = $ctx['branch']->id;
    $chat->customerId = $ctx['customer']->id;
    verifiedChatFixture($chat, $ctx['customer']);
    $chat->orderType = 'pickup';
    $chat->taxId = $ctx['customer']->tax_id;
    $chat->cart = [$ctx['product']->id => ['product_id' => $ctx['product']->id, 'qty' => 1, 'price' => 20, 'name' => 'Coxinha']];
    if ($order) {
        $chat->orderId = $order->id;
        app(ChatAccess::class)->grantOrder($order);
    }

    return $chat;
}

function endingsPayment(Order $order, array $attributes = []): Payment
{
    return $order->payments()->create(array_merge([
        'payment_gateway' => 'vindi', 'amount' => $order->total, 'status' => 'pending',
        'expires_at' => now()->subMinute(), 'payment_token' => bin2hex(random_bytes(16)),
    ], $attributes));
}

function endingsCard(): array
{
    return ['number' => '4111111111111111', 'holderName' => 'Cliente Teste', 'expiryMonth' => '12', 'expiryYear' => '2030', 'ccv' => '123'];
}

test('timer vencido preserva PIX pago e cancelamento ainda inicia estorno', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $payment = endingsPayment($order, ['status' => 'paid', 'paid_at' => now(), 'vindi_transaction_token' => 'sim_vindi_paid']);
    $chat = endingsChat($ctx, $order);
    $chat->step = 'PAYMENT_PIX';
    $chat->handlePaymentExpired();
    expect($payment->fresh()->status)->toBe('paid')->and($chat->step)->toBe('ORDER_CONFIRMED');
    expect(collect($chat->messages)->pluck('text')->implode(' '))->not->toContain('expirou');
    app(OrderService::class)->cancelOrder($order->fresh(), $ctx['customer']->id);
    expect($payment->refunds()->exists())->toBeTrue();
    Queue::assertNotPushed(ProcessOrder::class);
});

test('pagamento ao receber nunca renova e empresa alheia não tem acesso ao timer', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'awaiting_payment');
    $order->update(['payment_on_delivery' => true]);
    $payment = endingsPayment($order);
    app(PaymentService::class)->expireAndRenew($order, $ctx['customer'], $ctx['company'], 'PIX');
    $other = flowAuditContext();
    $chat = endingsChat($other);
    $chat->orderId = $order->id;
    $chat->handlePaymentExpired();
    expect($payment->fresh()->status)->toBe('pending');
    Queue::assertNotPushed(ProcessOrder::class);
});

test('renovar PIX usa referência nova e retentativa do mesmo job preserva referência após timeout', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx);
    config(['payments.vindi_token_account' => 'fake']);
    $refs = [];
    $vindi = Mockery::mock(VindiService::class);
    $vindi->shouldReceive('createPixCharge')->andReturnUsing(function (...$args) use (&$refs) {
        $ref = $args[1];
        $refs[] = $ref;
        if (count($refs) === 1) {
            throw new RuntimeException('timeout');
        }

        return ['transaction_token' => 'fake-'.count($refs), 'pix_qr_code' => null, 'pix_copy_paste' => 'qr-'.count($refs)];
    });
    $orchestrator = new PaymentOrchestrator($vindi);
    $job = new ProcessOrder($order, $ctx['customer'], $ctx['company'], 'PIX');
    expect(fn () => $job->handle($orchestrator))->toThrow(RuntimeException::class);
    $job->handle($orchestrator);
    $payment = $order->payments()->sole();
    $payment->update(['expires_at' => now()->subMinute()]);
    app(PaymentService::class)->expireAndRenew($order->fresh(), $ctx['customer'], $ctx['company'], 'PIX');
    $next = new ProcessOrder($order->fresh(), $ctx['customer'], $ctx['company'], 'PIX');
    $next->handle($orchestrator);
    expect($refs[0])->toBe($refs[1])->and($refs[2])->not->toBe($refs[1]);
    expect($order->payments()->reorder('id', 'desc')->first()->pix_copy_paste)->toBe('qr-3');
});

test('recusa síncrona fica failed, webhook não cancela e outro cartão aprova', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, paymentMethod: 'CARD');
    config(['payments.vindi_token_account' => 'fake']);
    $vindi = Mockery::mock(VindiService::class);
    $refs = [];
    $vindi->shouldReceive('createCreditCardCharge')->twice()->andReturnUsing(function (...$args) use (&$refs) {
        $refs[] = $args[1];

        return ['transaction_token' => 'card-'.count($refs), 'status_name' => count($refs) === 1 ? 'Reprovada' : 'Aprovada'];
    });
    $orchestrator = new PaymentOrchestrator($vindi);
    $orchestrator->processCreditCard($order, $ctx['customer'], $ctx['company'], endingsCard());
    expect($order->payments()->sole()->status)->toBe('failed');
    (new ProcessVindiWebhook('card-1', 'Reprovada', []))->handle();
    expect($order->fresh()->status)->toBe('pending');
    $result = $orchestrator->processCreditCard($order, $ctx['customer'], $ctx['company'], endingsCard());
    expect($result['approved'])->toBeTrue()->and($order->fresh()->status)->toBe('paid')->and($refs[0])->not->toBe($refs[1]);
});

test('webhook recupera cobrança aprovada após timeout sem token local e isola o pedido', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, paymentMethod: 'CARD');
    $other = flowAuditContext();
    $otherOrder = flowAuditChatOrder($other);
    config(['payments.vindi_token_account' => 'fake']);
    $vindi = Mockery::mock(VindiService::class);
    $ref = null;
    $vindi->shouldReceive('createCreditCardCharge')->once()->andReturnUsing(function (...$args) use (&$ref) {
        $ref = $args[1];
        throw new RuntimeException('timeout');
    });
    $vindi->shouldReceive('getTransactionStatus')->with('approved-after-timeout')->andReturn('Aprovada');
    app()->instance(VindiService::class, $vindi);
    expect(fn () => (new PaymentOrchestrator($vindi))->processCreditCard($order, $ctx['customer'], $ctx['company'], endingsCard()))->toThrow(RuntimeException::class);
    app()->forgetInstance('current.company');
    (new ProcessVindiWebhook('approved-after-timeout', 'Aprovada', ['transaction' => ['order_number' => $ref]]))->handle();
    expect($order->fresh()->status)->toBe('paid')->and($order->payments()->sole()->vindi_transaction_token)->toBe('approved-after-timeout');
    expect($otherOrder->fresh()->status)->toBe('pending')->and($otherOrder->payments()->exists())->toBeFalse();
});

test('checkout abandonado sem Payment ou sem expiração devolve estoque e libera cupom', function (string $kind) {
    $ctx = flowAuditContext();
    $coupon = \App\Models\Coupon::create(['company_id' => $ctx['company']->id, 'name' => 'Teste', 'code' => 'ENDINGS', 'type' => 'fixed', 'discount_value' => 2, 'active' => true]);
    $order = flowAuditChatOrder($ctx, coupon: $coupon, paymentMethod: 'CARD');
    $order->forceFill(['created_at' => now()->subHour()])->save();
    if ($kind !== 'none') {
        endingsPayment($order, ['expires_at' => null, 'vindi_transaction_token' => $kind === 'token' ? 'fake-check' : null]);
    }
    $other = flowAuditContext();
    $protected = flowAuditChatOrder($other);
    $protected->forceFill(['created_at' => now()->subHour(), 'payment_on_delivery' => true])->save();
    $vindi = Mockery::mock(VindiService::class);
    $vindi->shouldReceive('getTransactionStatus')->andReturn('Cancelada');
    (new ResolveExpiredVindiPaymentsJob)->handle($vindi);
    expect($order->fresh()->status)->toBe('cancelled')->and(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(10);
    expect($coupon->fresh()->countedUsages()->count())->toBe(0)->and($protected->fresh()->status)->toBe('pending');
})->with(['none', 'null', 'token']);

test('status unknown nunca cancela pagamento nem pedido abandonado', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx);
    $order->forceFill(['created_at' => now()->subHour()])->save();
    $payment = endingsPayment($order, ['vindi_transaction_token' => 'temporary', 'expires_at' => now()->subMinutes(10)]);
    $vindi = Mockery::mock(VindiService::class);
    $vindi->shouldReceive('getTransactionStatus')->andReturn('unknown');
    (new ResolveExpiredVindiPaymentsJob)->handle($vindi);
    expect($order->fresh()->status)->toBe('pending')->and($payment->fresh()->status)->toBe('pending');
});

test('preço alterado pede nova confirmação antes de criar e cobrança usa valor do pedido', function () {
    $ctx = flowAuditContext(stock: null);
    $chat = endingsChat($ctx);
    $ctx['product']->update(['price' => 45]);
    $chat->confirmOrder();
    expect($chat->orderId)->toBeNull()->and($chat->step)->toBe('CHECKOUT_CONFIRM')->and($chat->cart[$ctx['product']->id]['price'])->toBe(45.0);
    expect(Order::withoutGlobalScopes()->count())->toBe(0);
    $chat->confirmOrder();
    expect($chat->orderTotal)->toBe(45.0);
    $chat->cart[$ctx['product']->id]['price'] = 1;
    expect($chat->orderTotal)->toBe(45.0);
});

test('prévia de cartão usa taxa da bandeira no ambiente e recarrega pelo pedido', function () {
    $ctx = flowAuditContext(['card_fee_absorbed_by_company' => false]);
    $chat = endingsChat($ctx);
    $chat->selectPaymentMethod('CARD');
    $chat->cardNumber = '4111111111111111';
    $chat->updatedCardNumber();
    expect($chat->cardFeeBreakdown['card_rate'])->toBe(\App\Enums\CardBrand::Visa->rate());
    app()->instance('env', 'production');
    $chat->updatedCardNumber();
    expect($chat->cardFeeBreakdown['card_rate'])->toBe(0.0291);
    $chat->cardFeeBreakdown = [];
    $chat->hydrate();
    expect($chat->cardFeeBreakdown['final_amount'])->toBe(round(20 / (1 - 0.0291), 2));
});

test('produção exibe exatamente os centavos enviados à cobrança com taxa de 2,91 por cento', function () {
    $ctx = flowAuditContext(['card_fee_absorbed_by_company' => false]);
    $order = flowAuditChatOrder($ctx, qty: 1, paymentMethod: 'CARD');
    $chat = endingsChat($ctx, $order);
    $chat->paymentMethod = 'CARD';
    $chat->cardNumber = '4111111111111111';
    app()->instance('env', 'production');
    config(['payments.vindi_token_account' => 'fake']);
    $chat->updatedCardNumber();
    $expected = $chat->cardFeeBreakdown['final_amount'];
    $vindi = Mockery::mock(VindiService::class);
    $vindi->shouldReceive('createCreditCardCharge')->once()->andReturnUsing(function (...$args) use ($expected) {
        expect($args[0])->toBe($expected);

        return ['transaction_token' => 'fake-precise', 'status_name' => 'Aprovada'];
    });
    (new PaymentOrchestrator($vindi))->processCreditCard($order, $ctx['customer'], $ctx['company'], endingsCard());
    expect((float) $order->payments()->sole()->amount)->toBe($expected);
});

test('expiração configurada alcança checkout abandonado sem encurtar validade do PIX', function () {
    config(['payments.chat_checkout_timeout_minutes' => 5]);
    $ctx = flowAuditContext(stock: null);
    $card = flowAuditChatOrder($ctx, qty: 1, paymentMethod: 'CARD');
    $pix = flowAuditChatOrder($ctx, qty: 1);
    foreach ([$card, $pix] as $order) {
        $order->forceFill(['created_at' => now()->subMinutes(6)])->save();
    }
    endingsPayment($pix, ['expires_at' => now()->addMinutes(24)]);
    $vindi = Mockery::mock(VindiService::class);
    $vindi->shouldNotReceive('getTransactionStatus');
    (new ResolveExpiredVindiPaymentsJob)->handle($vindi);
    expect($card->fresh()->status)->toBe('cancelled')->and($pix->fresh()->status)->toBe('pending');
});

test('batch de expiração progride além de cinquenta pedidos com status temporário', function () {
    $ctx = flowAuditContext(stock: null);
    for ($i = 0; $i < 51; $i++) {
        $order = flowAuditChatOrder($ctx, qty: 1);
        $order->forceFill(['created_at' => now()->subHour(), 'updated_at' => now()->subHour()])->save();
        if ($i < 50) {
            endingsPayment($order, ['vindi_transaction_token' => 'temporary-'.$i, 'expires_at' => now()->subMinutes(10)]);
        }
    }
    $vindi = Mockery::mock(VindiService::class);
    $vindi->shouldReceive('getTransactionStatus')->andReturn('unknown');
    $job = new ResolveExpiredVindiPaymentsJob;
    $job->handle($vindi);
    expect($order->fresh()->status)->toBe('pending');
    $this->travel(1)->seconds();
    $job->handle($vindi);
    expect($order->fresh()->status)->toBe('cancelled');
});

test('fallback legado do webhook não associa namespace de outro ambiente', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx);
    $payment = endingsPayment($order);
    (new ProcessVindiWebhook('foreign-environment', 'Aprovada', ['transaction' => ['order_number' => 'ffffff-'.$order->id]]))->handle();
    expect($payment->fresh()->status)->toBe('pending')->and($order->fresh()->status)->toBe('pending');
});
