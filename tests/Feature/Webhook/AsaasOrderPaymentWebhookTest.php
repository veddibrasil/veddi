<?php

use App\Contracts\TransactionServiceInterface;
use App\Jobs\ProcessAsaasWebhook;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyTransaction;
use App\Models\CompanyWalletEntry;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Company\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function asaasWebhookContext(): array
{
    $company = Company::create([
        'name' => 'Empresa Asaas',
        'slug' => 'empresa-asaas',
        'order_prefix' => 'ASS',
        'active' => true,
        'status' => 'ACTIVE',
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua A',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente',
        'phone' => '11999990001',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 50.00,
        'delivery_fee' => 0,
        'total' => 50.00,
        'fee' => 2.00,
        'net_value' => 48.00,
        'status' => 'awaiting_payment',
        'payment_method' => 'PIX',
        'order_type' => 'delivery',
    ]);

    $payment = Payment::create([
        'order_id' => $order->id,
        'asaas_payment_id' => 'pay_asaas_test_001',
        'payment_gateway' => 'asaas',
        'amount' => 50.00,
        'original_amount' => 50.00,
        'status' => 'pending',
        'payment_token' => 'tok_test',
    ]);

    return compact('company', 'order', 'payment');
}

function asaasConfirmedPayload(string $asaasPaymentId, int $orderId): array
{
    return [
        'payment' => [
            'id' => $asaasPaymentId,
            'externalReference' => (string) $orderId,
            'status' => 'CONFIRMED',
            'value' => 50.00,
        ],
    ];
}

function dispatchAsaas(string $event, array $payload): void
{
    (new ProcessAsaasWebhook($event, $payload))->handle(app(CompanyService::class));
}

// ─── Testes ───────────────────────────────────────────────────────────────────

test('asaas: pagamento de pedido confirmado atualiza status e credita carteira', function () {
    ['company' => $company, 'order' => $order, 'payment' => $payment] = asaasWebhookContext();

    dispatchAsaas('PAYMENT_CONFIRMED', asaasConfirmedPayload($payment->asaas_payment_id, $order->id));

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

test('asaas: webhook duplicado não credita carteira duas vezes', function () {
    ['company' => $company, 'order' => $order, 'payment' => $payment] = asaasWebhookContext();

    $payload = asaasConfirmedPayload($payment->asaas_payment_id, $order->id);

    dispatchAsaas('PAYMENT_CONFIRMED', $payload);
    dispatchAsaas('PAYMENT_CONFIRMED', $payload);

    $creditCount = CompanyWalletEntry::where('company_id', $company->id)
        ->where('order_id', $order->id)
        ->where('type', 'credit')
        ->count();

    expect($creditCount)->toBe(1);
});

test('asaas: falha em createForPayment reverte credit da carteira', function () {
    ['order' => $order, 'payment' => $payment] = asaasWebhookContext();

    $this->instance(TransactionServiceInterface::class, new class implements TransactionServiceInterface
    {
        public function createForPayment(\App\Models\Order $order, \App\Models\Payment $payment): \App\Models\CompanyTransaction
        {
            throw new \RuntimeException('DB falhou');
        }
    });

    expect(fn () => dispatchAsaas('PAYMENT_CONFIRMED', asaasConfirmedPayload($payment->asaas_payment_id, $order->id)))
        ->toThrow(\RuntimeException::class);

    expect($payment->fresh()->status)->toBe('pending');
    expect(CompanyWalletEntry::where('order_id', $order->id)->count())->toBe(0);
});

test('asaas: pedido inexistente não lança exceção', function () {
    asaasWebhookContext();

    expect(fn () => dispatchAsaas('PAYMENT_CONFIRMED', [
        'payment' => [
            'id' => 'pay_ghost',
            'externalReference' => '999999',
            'status' => 'CONFIRMED',
        ],
    ]))->not->toThrow(\Throwable::class);
});
