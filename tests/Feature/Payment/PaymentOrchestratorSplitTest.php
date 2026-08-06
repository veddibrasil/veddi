<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeSplitTestOrder(float $total): Order
{
    ['company' => $company, 'branch' => $branch] = pdvContext();

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Balcão',
        'phone' => 'pdv-guest',
    ]);

    return Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => $total,
        'total' => $total,
        'status' => 'paid',
        'payment_method' => 'split',
        'order_type' => 'pdv',
        'order_number' => 'TST-SPLIT-'.uniqid(),
    ]);
}

test('processCash sem amount usa order->total (regressão)', function () {
    $order = makeSplitTestOrder(50.0);
    $order->cash_received = 60.0;
    $order->save();

    $result = app(PaymentOrchestrator::class)->processCash($order);

    expect($result['change'])->toBe(10.0);

    $payment = Payment::where('order_id', $order->id)->first();
    expect((float) $payment->amount)->toBe(50.0);
});

test('processCash com amount parcial cria payment com valor parcial', function () {
    $order = makeSplitTestOrder(50.0);

    $result = app(PaymentOrchestrator::class)->processCash($order, 20.0, 20.0);

    expect($result['change'])->toBe(0.0);

    $payment = Payment::where('order_id', $order->id)->first();
    expect((float) $payment->amount)->toBe(20.0);
});

test('processCardMachine e processPixManual sem amount usam order->total (regressão)', function () {
    $order = makeSplitTestOrder(50.0);

    app(PaymentOrchestrator::class)->processCardMachine($order);

    $payment = Payment::where('order_id', $order->id)->first();
    expect((float) $payment->amount)->toBe(50.0);
});

test('processSplit cria um payment por parte quando soma bate com o total', function () {
    $order = makeSplitTestOrder(50.0);

    $results = app(PaymentOrchestrator::class)->processSplit($order, [
        ['method' => 'cash', 'amount' => 20.0, 'cash_received' => 20.0],
        ['method' => 'credit_card', 'amount' => 30.0],
    ]);

    expect($results)->toHaveCount(2);

    $payments = Payment::where('order_id', $order->id)->orderBy('id')->get();
    expect($payments)->toHaveCount(2);
    expect($payments[0]->payment_gateway)->toBe('cash');
    expect((float) $payments[0]->amount)->toBe(20.0);
    expect($payments[1]->payment_gateway)->toBe('card_machine');
    expect((float) $payments[1]->amount)->toBe(30.0);
});

test('processSplit lança exceção quando soma das partes não bate com o total e não cria payment', function () {
    $order = makeSplitTestOrder(50.0);

    expect(fn () => app(PaymentOrchestrator::class)->processSplit($order, [
        ['method' => 'cash', 'amount' => 20.0, 'cash_received' => 20.0],
        ['method' => 'credit_card', 'amount' => 10.0],
    ]))->toThrow(\RuntimeException::class);

    expect(Payment::where('order_id', $order->id)->count())->toBe(0);
});
