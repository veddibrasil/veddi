<?php

use App\Exceptions\CouponException;
use App\Models\Coupon;
use App\Models\Order;
use App\Services\Order\CouponService;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([\App\Events\NewOrderPlaced::class, \App\Events\OrderStatusUpdated::class]);
    Queue::fake();
    Mail::fake();
});

function guestLimitCoupon(array $ctx, ?int $perCustomer = 1): Coupon
{
    return Coupon::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'code' => 'PRIMEIRA', 'name' => 'Primeira compra',
        'type' => 'percentage', 'discount_value' => 10, 'scope' => 'order', 'active' => true,
        'max_uses_per_customer' => $perCustomer,
    ]);
}

function guestLimitOrder(array $ctx, Coupon $coupon, string $phone): Order
{
    return app(OrderService::class)->createOrder(
        customerId: null,
        branchId: $ctx['branch']->id,
        cart: [(string) $ctx['product']->id => ['product_id' => $ctx['product']->id, 'qty' => 1]],
        notes: '',
        paymentMethod: 'CASH',
        orderType: 'pickup',
        status: 'paid',
        coupon: $coupon,
        buyerData: ['name' => 'Visitante', 'phone' => $phone, 'email' => null, 'tax_id' => ''],
    );
}

function guestLimitValidate(array $ctx, ?int $customerId, ?string $phone): Coupon
{
    $cart = [(string) $ctx['product']->id => ['product_id' => $ctx['product']->id, 'qty' => 1]];

    return app(CouponService::class)->validate('PRIMEIRA', $customerId, $cart, 20.0, $phone);
}

test('visitante usa cupom com limite por cliente uma vez por telefone', function () {
    $ctx = flowAuditContext();
    $coupon = guestLimitCoupon($ctx);

    expect(guestLimitValidate($ctx, null, '11988887777')->id)->toBe($coupon->id);
    guestLimitOrder($ctx, $coupon, '11988887777');

    expect(fn () => guestLimitValidate($ctx, null, '11988887777'))
        ->toThrow(CouponException::class, 'Você já utilizou este cupom o número máximo de vezes.')
        ->and(guestLimitValidate($ctx, null, '11955554444')->id)->toBe($coupon->id);
    // A criação do pedido revalida com lock: a segunda compra com o mesmo telefone não leva o desconto.
    expect(fn () => guestLimitOrder($ctx, $coupon, '11988887777'))->toThrow(CouponException::class);
});

test('uso como visitante e como cliente cadastrado com o mesmo telefone contam juntos', function () {
    $ctx = flowAuditContext();
    $coupon = guestLimitCoupon($ctx);
    guestLimitOrder($ctx, $coupon, $ctx['customer']->phone);

    expect(fn () => guestLimitValidate($ctx, $ctx['customer']->id, null))
        ->toThrow(CouponException::class, 'Você já utilizou este cupom o número máximo de vezes.');

    $other = flowAuditContext();
    $otherCoupon = guestLimitCoupon($other);
    flowAuditChatOrder($other, 1, 'paid', 'CASH', $otherCoupon);
    app()->instance('current.company', $other['company']);
    expect(fn () => guestLimitValidate($other, null, $other['customer']->phone))
        ->toThrow(CouponException::class, 'Você já utilizou este cupom o número máximo de vezes.');
});

test('pedido cancelado devolve o uso do visitante e cupom sem limite por cliente não pede telefone', function () {
    $ctx = flowAuditContext();
    $coupon = guestLimitCoupon($ctx);
    guestLimitOrder($ctx, $coupon, '11988887777')->update(['status' => 'cancelled']);

    expect(guestLimitValidate($ctx, null, '11988887777')->id)->toBe($coupon->id)
        ->and(fn () => guestLimitValidate($ctx, null, null))->toThrow(CouponException::class, 'Informe seu telefone para usar este cupom.');

    $coupon->update(['max_uses_per_customer' => null]);
    \Illuminate\Support\Facades\Cache::flush();
    expect(guestLimitValidate($ctx, null, null)->id)->toBe($coupon->id);
});
