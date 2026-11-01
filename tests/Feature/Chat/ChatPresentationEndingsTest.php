<?php

require_once __DIR__.'/endings_helpers.php';

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessRefund;
use App\Models\CompanyNotification;
use App\Models\Order;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Drawer\Utils;
use Monolog\Handler\TestHandler;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    Queue::fake();
});

test('cancelamento e estorno encerram tela confirmada, mantêm motivo no reload e param poll', function (string $status) {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: $status);
    $order->update(['cancellation_reason' => 'Sem entregador disponível']);
    $chat = endingsChat($ctx, $order);
    $chat->step = 'ORDER_CONFIRMED';
    $chat->checkPaymentStatus();
    expect($chat->step)->toBe('ORDER_CANCELLED')->and($chat->orderOutcome['reason'])->toBe('Sem entregador disponível');
    if ($status === 'refunded') {
        expect($chat->orderOutcome['refund'])->toContain('estorno foi confirmado');
    }
    $html = $this->get('/'.$ctx['company']->slug)->assertOk()->assertSee('Sem entregador disponível')->getContent();
    $data = Utils::extractAttributeDataFromHtml($html, 'wire:snapshot')['data'];
    expect($data['step'])->toBe('ORDER_CANCELLED');
    expect($html)->not->toContain('wire:poll.15s="checkPaymentStatus"');
})->with(['cancelled', 'refunded']);

test('erro de cartão tem texto próprio e tentar novamente preserva carrinho e escolhas', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, paymentMethod: 'CARD', status: 'cancelled');
    $chat = endingsChat($ctx, $order);
    $chat->paymentMethod = 'CARD';
    expect($chat->orderFailureMessage)->toContain('cartão')->not->toContain('PIX');
    $cart = $chat->cart;
    $chat->notes = 'Sem cebola';
    $chat->retryOrder();
    expect($chat->cart)->toBe($cart)->and($chat->notes)->toBe('Sem cebola')->and($chat->paymentMethod)->toBe('CARD');
    expect($chat->step)->toBe('CHECKOUT_CONFIRM')->and($chat->orderId)->toBeNull();
});

test('textos de pagamento e retirada descrevem o estado real', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $chat = endingsChat($ctx, $order);
    $chat->checkPaymentStatus();
    expect(collect($chat->messages)->last()['text'])->toContain('Pagamento confirmado');
    $order->update(['status' => 'delivered']);
    $chat->checkPaymentStatus();
    expect(collect($chat->messages)->last()['text'])->toContain('retirado')->not->toContain('entregue');
});

test('estoque, cupom e pedido mínimo são recusas de negócio sem sino ou Discord', function (string $cause) {
    $ctx = flowAuditContext(stock: 0);
    $chat = endingsChat($ctx);
    if ($cause === 'coupon') {
        $coupon = \App\Models\Coupon::create(['company_id' => $ctx['company']->id, 'name' => 'Cupom', 'code' => 'VENCIDO', 'type' => 'fixed', 'discount_value' => 1, 'active' => false]);
        $chat->appliedCoupon = ['id' => $coupon->id, 'type' => 'fixed'];
    } elseif ($cause === 'minimum') {
        \App\Models\DeliverySetting::create(['branch_id' => $ctx['branch']->id, 'company_id' => $ctx['company']->id, 'active' => true, 'fee_type' => 'flat', 'flat_fee' => 1, 'minimum_order_value' => 100]);
        $chat->orderType = 'delivery';
        $chat->address = 'Rua do Teste';
        $chat->number = '1';
        $chat->neighborhood = 'Centro';
        $chat->city = 'São Paulo';
        $chat->cep = '01001000';
    }
    $logs = new TestHandler;
    Log::channel('discord')->getLogger()->pushHandler($logs);
    $chat->confirmOrder();
    expect($chat->orderId)->toBeNull()->and($chat->step)->toBe('CHECKOUT_CONFIRM');
    expect(CompanyNotification::withoutGlobalScopes()->where('type', 'order_failed')->exists())->toBeFalse();
    expect($logs->hasErrorRecords())->toBeFalse();
})->with(['stock', 'coupon', 'minimum']);

test('produto indisponível usa nome da própria empresa sem revelar nome de outra', function () {
    $ctx = flowAuditContext();
    $chat = endingsChat($ctx);
    $ctx['product']->update(['active' => false]);
    $chat->confirmOrder();
    expect(collect($chat->messages)->last()['text'])->toContain('Coxinha')->not->toContain('Produto #');
    $other = flowAuditContext();
    $other['product']->update(['name' => 'Produto confidencial']);
    app()->instance('current.company', $ctx['company']);
    $chat->cart = [$other['product']->id => ['qty' => 1, 'price' => 20]];
    $chat->confirmOrder();
    expect(collect($chat->messages)->last()['text'])->not->toContain('Produto confidencial');
});

test('CPF aparece mascarado na conversa', function () {
    $chat = endingsChat(flowAuditContext());
    $chat->submitCpf();
    expect(collect($chat->messages)->last()['text'])->toBe('***.***.***-25');
});

test('cancelar pagamentos presenciais não dispara estorno nem alerta falso', function (string $gateway, string $method) {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'paid', paymentMethod: $method);
    endingsPayment($order, ['payment_gateway' => $gateway, 'status' => 'paid']);
    app(OrderService::class)->cancelOrder($order, $ctx['customer']->id);
    expect($order->fresh()->status)->toBe('cancelled');
    expect(\App\Models\PaymentRefund::where('order_id', $order->id)->exists())->toBeFalse();
    expect(CompanyNotification::withoutGlobalScopes()->where('type', 'refund_failed')->exists())->toBeFalse();
    Queue::assertNotPushed(ProcessRefund::class);
})->with([['card_machine', 'CARD'], ['pix_manual', 'PIX'], ['cash', 'CASH']]);

test('PIX mostra total do pedido sem taxa fictícia e copia e cola vem antes do QR e do acompanhamento', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'awaiting_payment');
    $chat = endingsChat($ctx, $order);
    $chat->pixCopyPaste = '000201-fake-pix';
    $chat->trackingLink = app(\App\Services\Chat\ChatAccess::class)->orderLink($order);
    // A mudança de etapa persiste o estado real do componente para o reload HTTP.
    $chat->step = 'PAYMENT_PIX';
    $chat->checkPaymentStatus();
    $html = $this->get('/'.$ctx['company']->slug)->assertOk()->assertSee('R$ 60,00')->assertDontSee('Taxa PIX')->getContent();
    expect(strpos($html, 'PIX Copia e Cola'))->toBeLessThan(strpos($html, 'class="mc-qr-frame"'));
    expect(strpos($html, 'class="mc-qr-frame"'))->toBeLessThan(strpos($html, 'id="chat-tracking-link"'));
});

test('cancelamento misto solicita apenas estorno do pagamento online', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'paid');
    endingsPayment($order, ['payment_gateway' => 'cash', 'status' => 'paid', 'amount' => 20]);
    $online = endingsPayment($order, ['payment_gateway' => 'vindi', 'status' => 'paid', 'amount' => 40]);
    app(OrderService::class)->cancelOrder($order, $ctx['customer']->id);
    expect(\App\Models\PaymentRefund::where('order_id', $order->id)->sole()->payment_id)->toBe($online->id);
    expect($order->fresh()->cancellation_reason)->toBe('Cancelado pelo cliente.');
});

test('erro do serviço não revela produto de filial de outra empresa', function () {
    $ctx = flowAuditContext();
    $other = flowAuditContext();
    $other['product']->update(['name' => 'Produto sigiloso']);
    app()->instance('current.company', $ctx['company']);
    try {
        app(OrderService::class)->createOrder($ctx['customer']->id, $other['branch']->id, [$other['product']->id => ['qty' => 1]], '', 'PIX', 'pickup');
        test()->fail('Pedido fora da empresa deveria ser recusado.');
    } catch (\RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain('Produto sigiloso');
    }
    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});
