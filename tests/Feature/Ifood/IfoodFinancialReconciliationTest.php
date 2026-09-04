<?php

use App\Contracts\IfoodGatewayContract;
use App\Models\CompanyWalletEntry;
use App\Models\Order;
use App\Services\Ifood\IfoodFinancialReconciliationService;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeSettledIfoodOrder(array $ctx, float $fee = 2.00): Order
{
    return Order::create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'customer_id' => \App\Models\Customer::withoutGlobalScopes()->create([
            'company_id' => $ctx['company']->id,
            'name' => 'Cliente',
            'phone' => '11977776666',
        ])->id,
        'subtotal' => 50.00,
        'total' => 50.00,
        'discount' => 0,
        'fee' => $fee,
        'net_value' => 50.00 - $fee,
        'status' => 'paid',
        'payment_method' => 'ifood',
        'order_type' => 'delivery',
        'channel' => 'ifood',
        'external_order_id' => 'ifood-order-settle-1',
    ]);
}

test('processIfoodPrepaid nunca dispara chamada HTTP de cobrança', function () {
    $ctx = ifoodContext('pay1');
    $order = makeSettledIfoodOrder($ctx);

    Http::fake();

    app(PaymentOrchestrator::class)->processIfoodPrepaid($order);

    Http::assertNothingSent();
});

test('reconcile credita valor líquido do repasse e debita taxa da plataforma', function () {
    $ctx = ifoodContext('rec1');
    $order = makeSettledIfoodOrder($ctx, fee: 2.00);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('getSettlements')->once()->andReturn([
        ['id' => 'settle-001', 'orderId' => $order->external_order_id, 'type' => 'ORDER', 'netAmount' => 44.50],
    ]);

    (new IfoodFinancialReconciliationService($gateway))->reconcile($ctx['integration'], now()->subDay(), now());

    $credit = CompanyWalletEntry::where('order_id', $order->id)->where('type', 'credit')->first();
    $fee = CompanyWalletEntry::where('order_id', $order->id)->where('type', 'fee')->first();

    expect((float) $credit->amount)->toBe(44.50)
        ->and((float) $fee->amount)->toBe(2.00);
});

test('reconcile é idempotente — mesmo settlement processado duas vezes não duplica lançamento', function () {
    $ctx = ifoodContext('rec2');
    $order = makeSettledIfoodOrder($ctx);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('getSettlements')->twice()->andReturn([
        ['id' => 'settle-002', 'orderId' => $order->external_order_id, 'type' => 'ORDER', 'netAmount' => 44.50],
    ]);

    $service = new IfoodFinancialReconciliationService($gateway);
    $service->reconcile($ctx['integration'], now()->subDay(), now());
    $service->reconcile($ctx['integration'], now()->subDay(), now());

    expect(CompanyWalletEntry::where('order_id', $order->id)->where('type', 'credit')->count())->toBe(1);
});

test('reconcile de cancelamento gera lançamento de estorno (refund), não credit', function () {
    $ctx = ifoodContext('rec3');
    $order = makeSettledIfoodOrder($ctx);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('getSettlements')->once()->andReturn([
        ['id' => 'settle-003', 'orderId' => $order->external_order_id, 'type' => 'CANCELLATION', 'netAmount' => -44.50],
    ]);

    (new IfoodFinancialReconciliationService($gateway))->reconcile($ctx['integration'], now()->subDay(), now());

    expect(CompanyWalletEntry::where('order_id', $order->id)->where('type', 'refund')->exists())->toBeTrue()
        ->and(CompanyWalletEntry::where('order_id', $order->id)->where('type', 'credit')->exists())->toBeFalse();
});

test('reconcile ignora settlement de pedido não encontrado sem lançar exceção', function () {
    ['integration' => $integration] = ifoodContext('rec4');

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('getSettlements')->once()->andReturn([
        ['id' => 'settle-004', 'orderId' => 'ifood-order-inexistente', 'type' => 'ORDER', 'netAmount' => 10.00],
    ]);

    (new IfoodFinancialReconciliationService($gateway))->reconcile($integration, now()->subDay(), now());

    expect(CompanyWalletEntry::count())->toBe(0);
});
