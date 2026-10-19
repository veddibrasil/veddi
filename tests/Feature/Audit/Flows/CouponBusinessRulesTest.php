<?php

require_once __DIR__.'/flows_helpers.php';

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Livewire\Admin\Coupons\Index as CouponsIndex;
use App\Livewire\Chat\OrderChat;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([OrderStatusUpdated::class, NewOrderPlaced::class]);
    Queue::fake();
});

function flowAuditCoupon(array $ctx, array $overrides = []): Coupon
{
    return Coupon::withoutGlobalScopes()->create(array_merge([
        'company_id' => $ctx['company']->id,
        'code' => 'BEMVINDO',
        'name' => 'Boas-vindas',
        'type' => 'fixed',
        'discount_value' => 5,
        'scope' => 'order',
        'max_uses_per_customer' => 1,
        'active' => true,
    ], $overrides));
}

/*
 * AUDITORIA FLUXOS — uso de cupom não é liberado quando o pedido é cancelado/abandonado.
 * CouponUsage é gravado em createOrder() e nenhum caminho de cancelamento o remove;
 * hasReachedMaxUses() e hasReachedMaxUsesForCustomer() contam todos os usos, inclusive de
 * pedidos cancelados. Pior caso: no chat, "Trocar forma de pagamento" cancela o pedido
 * pendente e o novo pedido com o MESMO cupom é recusado ("já utilizou este cupom").
 */
test('cliente que troca a forma de pagamento no chat consegue refazer o pedido com o mesmo cupom', function () {
    $ctx = flowAuditContext(stock: null);
    $coupon = flowAuditCoupon($ctx);

    $chat = new OrderChat;
    $chat->companyId = $ctx['company']->id;
    $chat->customerId = $ctx['customer']->id;
    $chat->selectedBranchId = $ctx['branch']->id;
    $chat->orderType = 'pickup';
    $chat->taxId = '52998224725';
    $chat->cart = [
        (string) $ctx['product']->id => ['product_id' => $ctx['product']->id, 'qty' => 2, 'name' => 'Coxinha', 'price' => 20.0],
    ];
    $chat->couponInput = 'BEMVINDO';
    $chat->applyCoupon();
    expect($chat->appliedCoupon['id'])->toBe($coupon->id);

    // Escolhe cartão → pedido nasce 'pending' e o chat abre o formulário do cartão.
    $chat->selectPaymentMethod('CARD');
    $chat->confirmOrder();
    $first = Order::withoutGlobalScopes()->findOrFail($chat->orderId);
    expect($first->coupon_id)->toBe($coupon->id);

    // "← Trocar forma de pagamento": cancela o pedido pendente e volta pra escolha do método.
    $chat->changePaymentMethod();
    expect($first->fresh()->status)->toBe('cancelled');

    // Escolhe dinheiro e confirma de novo, ainda com o cupom aplicado.
    $chat->selectPaymentMethod('CASH');
    $chat->confirmOrder();

    $live = Order::withoutGlobalScopes()
        ->where('customer_id', $ctx['customer']->id)
        ->where('status', '!=', 'cancelled')
        ->get();

    // Hoje: nenhum pedido válido — o chat mostra "Você já utilizou este cupom o número máximo de vezes."
    $lastBot = collect($chat->messages)->where('from', 'bot')->last()['text'] ?? '';
    expect($lastBot)->not->toContain('já utilizou este cupom');
    expect($live)->toHaveCount(1);
    expect((float) $live->first()->discount)->toBe(5.0);
});

test('pedido cancelado não consome o limite total de usos do cupom', function () {
    $ctx = flowAuditContext(stock: null);
    $coupon = flowAuditCoupon($ctx, ['max_uses' => 1, 'max_uses_per_customer' => null]);

    $order = flowAuditChatOrder($ctx, qty: 1, status: 'pending', coupon: $coupon);
    app(OrderService::class)->cancelOrder($order, $ctx['customer']->id);

    $other = \App\Models\Customer::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'name' => 'Outro', 'phone' => '11966660000',
    ]);

    $second = app(OrderService::class)->createOrder(
        customerId: $other->id,
        branchId: $ctx['branch']->id,
        cart: [(string) $ctx['product']->id => ['product_id' => $ctx['product']->id, 'qty' => 1]],
        notes: '',
        paymentMethod: 'PIX',
        orderType: 'pickup',
        coupon: $coupon,
    );

    expect((float) $second->discount)->toBe(5.0);
});

/*
 * AUDITORIA FLUXOS — cupom "Produto Grátis" dá o benefício em dobro: OrderService adiciona
 * o brinde como item a R$ 0,00 E CouponService::calculateDiscount devolve o preço do brinde
 * como desconto, abatido do resto do pedido.
 */
test('cupom de produto grátis não desconta o preço do brinde do resto do pedido', function () {
    $ctx = flowAuditContext(stock: null);
    $refri = flowAuditProduct($ctx['company'], $ctx['branch'], $ctx['category'], 'Refrigerante', 15.0);
    $coupon = flowAuditCoupon($ctx, [
        'code' => 'REFRIGRATIS', 'type' => 'free_product', 'discount_value' => null, 'free_product_id' => $refri->id,
    ]);

    $order = flowAuditChatOrder($ctx, qty: 1, status: 'pending', coupon: $coupon); // 1 coxinha de R$ 20

    $items = $order->items()->get();
    expect($items->pluck('product_name')->all())->toContain('Refrigerante (Brinde)');
    expect((float) $items->firstWhere('product_name', 'Refrigerante (Brinde)')->unit_price)->toBe(0.0);

    // Esperado: paga a coxinha (20) e leva o refri de brinde. Hoje: total 5 (20 - 15 de "desconto").
    expect((float) $order->total)->toBe(20.0);
});

/*
 * AUDITORIA FLUXOS — editar cupom não consegue LIMPAR campos opcionais. Coupons\Index::save()
 * faz array_filter(... $v !== null ...) antes do update(): validade, limite de usos,
 * restrição de forma de pagamento, pedido mínimo etc. ficam com o valor antigo para sempre.
 */
test('admin consegue remover validade, limite de usos e restrição de pagamento de um cupom', function () {
    $ctx = flowAuditContext(stock: null);
    $admin = User::factory()->create(['is_super_admin' => true]);
    $coupon = flowAuditCoupon($ctx, [
        'code' => 'SOPIX',
        'payment_method' => 'PIX',
        'max_uses' => 10,
        'minimum_order_value' => 50,
        'expires_at' => now()->addDays(2),
    ]);

    Livewire::actingAs($admin)
        ->test(CouponsIndex::class)
        ->call('edit', $coupon->id)
        ->set('payment_method', null)
        ->set('max_uses', null)
        ->set('minimum_order_value', null)
        ->set('expires_at', null)
        ->call('save')
        ->assertHasNoErrors();

    $coupon->refresh();

    expect($coupon->payment_method)->toBeNull()
        ->and($coupon->max_uses)->toBeNull()
        ->and($coupon->minimum_order_value)->toBeNull()
        ->and($coupon->expires_at)->toBeNull();
});
