<?php

use App\Contracts\TransactionServiceInterface;
use App\Jobs\ProcessStarkWebhook;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyTransaction;
use App\Models\CompanyWalletEntry;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function starkWebhookContext(): array
{
    $company = Company::create([
        'name' => 'Empresa Stark',
        'slug' => 'empresa-stark',
        'order_prefix' => 'STK',
        'active' => true,
        'status' => 'ACTIVE',
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua B',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Stark',
        'phone' => '11999990002',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 60.00,
        'delivery_fee' => 0,
        'total' => 60.00,
        'fee' => 2.00,
        'net_value' => 58.00,
        'status' => 'awaiting_payment',
        'payment_method' => 'PIX',
        'order_type' => 'delivery',
    ]);

    $payment = Payment::create([
        'order_id' => $order->id,
        'stark_payment_id' => 'inv_stark_test_001',
        'payment_gateway' => 'stark',
        'amount' => 60.00,
        'original_amount' => 60.00,
        'status' => 'pending',
        'payment_token' => 'tok_stark_test',
    ]);

    return compact('company', 'order', 'payment');
}

function starkCreditedPayload(string $invoiceId): array
{
    return [
        'event' => [
            'log' => [
                'invoice' => ['id' => $invoiceId],
            ],
        ],
    ];
}

function dispatchStark(string $event, array $payload): void
{
    (new ProcessStarkWebhook($event, $payload))->handle();
}

// ─── Testes ───────────────────────────────────────────────────────────────────

test('stark: pagamento PIX confirmado atualiza status e credita carteira', function () {
    ['company' => $company, 'order' => $order, 'payment' => $payment] = starkWebhookContext();

    dispatchStark('credited', starkCreditedPayload($payment->stark_payment_id));

    expect($payment->fresh()->status)->toBe('paid');
    expect($order->fresh()->status)->toBe('paid');
    expect(
        CompanyWalletEntry::where('company_id', $company->id)
            ->where('order_id', $order->id)
            ->where('type', 'credit')
            ->exists()
    )->toBeTrue();
    expect(
        CompanyTransaction::where('order_id', $order->id)->exists()
    )->toBeTrue();
});

test('stark: webhook duplicado não credita carteira duas vezes', function () {
    ['company' => $company, 'order' => $order, 'payment' => $payment] = starkWebhookContext();

    $payload = starkCreditedPayload($payment->stark_payment_id);

    dispatchStark('credited', $payload);
    dispatchStark('credited', $payload);

    $creditCount = CompanyWalletEntry::where('company_id', $company->id)
        ->where('order_id', $order->id)
        ->where('type', 'credit')
        ->count();

    expect($creditCount)->toBe(1);
});

test('stark: falha em createForPayment reverte credit da carteira', function () {
    ['order' => $order, 'payment' => $payment] = starkWebhookContext();

    $this->instance(TransactionServiceInterface::class, new class implements TransactionServiceInterface
    {
        public function createForPayment(\App\Models\Order $order, \App\Models\Payment $payment): \App\Models\CompanyTransaction
        {
            throw new \RuntimeException('DB falhou');
        }
    });

    expect(fn () => dispatchStark('credited', starkCreditedPayload($payment->stark_payment_id)))
        ->toThrow(\RuntimeException::class);

    expect($payment->fresh()->status)->toBe('pending');
    expect(CompanyWalletEntry::where('order_id', $order->id)->count())->toBe(0);
});

test('stark: eventos não-credited são ignorados sem erro', function () {
    starkWebhookContext();

    expect(fn () => dispatchStark('created', ['event' => ['log' => []]]))->not->toThrow(\Throwable::class);
    expect(fn () => dispatchStark('overdue', ['event' => ['log' => []]]))->not->toThrow(\Throwable::class);
});
