<?php

use App\Jobs\ProcessVindiWebhook;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyWalletEntry;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function vindiGatewayCheckContext(?string $token = 'vindi_tok_check_001'): array
{
    $company = Company::create([
        'name' => 'Empresa Check',
        'slug' => 'empresa-check-'.uniqid(),
        'order_prefix' => 'VCK',
        'active' => true,
    ]);
    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Check',
        'address' => 'Rua V, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Check',
        'phone' => '11944440001',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 50.00,
        'total' => 50.00,
        'delivery_fee' => 0,
        'discount' => 0,
        'fee' => 0,
        'net_value' => 0,
        'status' => 'awaiting_payment',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'order_number' => 'VCK-2026-00001',
    ]);

    $payment = Payment::create([
        'order_id' => $order->id,
        'vindi_transaction_token' => $token,
        'payment_gateway' => 'vindi',
        'amount' => 50.00,
        'platform_fee' => 0.57,
        'company_net_amount' => 48.93,
        'status' => 'pending',
        'payment_token' => hash('sha256', 'check-'.uniqid()),
    ]);

    return compact('company', 'order', 'payment');
}

function fakeVindiTransactionStatus(string $status, int $httpStatus = 200): void
{
    Http::fake([
        '*/transactions/*' => Http::response(
            $httpStatus === 200 ? ['data_response' => ['transaction' => ['status_name' => $status]]] : ['error' => 'x'],
            $httpStatus,
        ),
    ]);
}

test('webhook Vindi recusa requisição sem token quando o token da conta não está configurado', function () {
    Bus::fake();
    config()->set('payments.vindi_token_account', null);

    $this->postJson('/webhooks/vindi', [
        'transaction' => [
            'transaction_token' => 'vindi_tok_check_001',
            'status_name' => 'Aprovada',
        ],
    ])->assertStatus(401);

    Bus::assertNotDispatched(ProcessVindiWebhook::class);
});

test('Aprovada forjada no payload é ignorada quando a API da Vindi diz outro status', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    fakeVindiTransactionStatus('Aguardando Pagamento');
    ['order' => $order, 'payment' => $payment] = vindiGatewayCheckContext();

    (new ProcessVindiWebhook('vindi_tok_check_001', 'Aprovada', ['transaction' => ['status_name' => 'Aprovada']]))->handle();

    expect($payment->fresh()->status)->toBe('pending')
        ->and($order->fresh()->status)->toBe('awaiting_payment')
        ->and(CompanyWalletEntry::where('order_id', $order->id)->exists())->toBeFalse();
});

test('Aprovada confirmada pela API credita a carteira', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    fakeVindiTransactionStatus('Aprovada');
    ['order' => $order, 'payment' => $payment] = vindiGatewayCheckContext();

    (new ProcessVindiWebhook('vindi_tok_check_001', 'Aprovada', ['transaction' => ['status_name' => 'Aprovada']]))->handle();

    expect($payment->fresh()->status)->toBe('paid')
        ->and(CompanyWalletEntry::where('order_id', $order->id)->where('type', 'credit')->exists())->toBeTrue();
});

test('Vindi fora do ar faz o job falhar para ser retentado, sem creditar', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    fakeVindiTransactionStatus('unknown', 500);
    ['order' => $order, 'payment' => $payment] = vindiGatewayCheckContext();

    expect(fn () => (new ProcessVindiWebhook('vindi_tok_check_001', 'Aprovada', []))->handle())
        ->toThrow(RuntimeException::class);

    expect($payment->fresh()->status)->toBe('pending')
        ->and(CompanyWalletEntry::where('order_id', $order->id)->exists())->toBeFalse();
});

test('pagamento achado pelo order_number passa a guardar o token da transação', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    fakeVindiTransactionStatus('Aprovada');
    ['order' => $order, 'payment' => $payment] = vindiGatewayCheckContext(token: null);

    (new ProcessVindiWebhook('vindi_tok_novo_999', 'Aprovada', [
        'transaction' => ['status_name' => 'Aprovada', 'order_number' => "ambiente-{$order->id}"],
    ]))->handle();

    $payment->refresh();

    expect($payment->status)->toBe('paid')
        ->and($payment->vindi_transaction_token)->toBe('vindi_tok_novo_999')
        ->and(CompanyWalletEntry::where('order_id', $order->id)->where('reference', 'vindi_tok_novo_999')->exists())->toBeTrue();
});
