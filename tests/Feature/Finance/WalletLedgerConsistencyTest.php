<?php

use App\Contracts\RefundServiceInterface;
use App\Contracts\TransactionServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Jobs\ProcessRefund;
use App\Livewire\Admin\Wallet\CompanyWallet;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyTransaction;
use App\Models\CompanyWalletEntry;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\Finance\BalanceService;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Modo simulação da Vindi: nenhuma chamada HTTP real.
    config()->set('payments.vindi_token_account', null);
    config()->set('payments.vindi_pix_rate', 0.0085);
    config()->set('payments.vindi_pix_platform_rate', 0.0014);
});

function financeLedgerContext(array $orderAttributes = []): array
{
    $company = Company::create([
        'name' => 'Empresa Ledger',
        'slug' => 'empresa-ledger-'.uniqid(),
        'order_prefix' => 'LED',
        'email' => 'ledger@teste.com',
        'plan' => 'free',
        'active' => true,
        'status' => 'ACTIVE',
    ]);
    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Ledger',
        'address' => 'Rua L, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Ledger',
        'phone' => '11955550001',
        'email' => 'cliente@teste.com',
    ]);

    $order = Order::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 100.00,
        'total' => 110.00,
        'delivery_fee' => 10.00,
        'discount' => 0,
        'fee' => 0,
        'net_value' => 0,
        'status' => 'awaiting_payment',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'order_number' => 'LED-2026-'.random_int(10000, 99999),
    ], $orderAttributes));

    return compact('company', 'branch', 'customer', 'order');
}

function financeConfirmPayment(Order $order, Payment $payment): void
{
    $payment->update(['status' => 'paid', 'paid_at' => now()]);
    $order->update(['status' => 'paid']);

    app(WalletServiceInterface::class)->creditForOrder($order->fresh(), $payment->fresh());
    app(TransactionServiceInterface::class)->createForPayment($order->fresh(), $payment->fresh());
}

test('PIX: carteira, transação e split do gateway usam o mesmo líquido, sem taxa sobre a entrega', function () {
    ['company' => $company, 'customer' => $customer, 'order' => $order] = financeLedgerContext();

    app(PaymentOrchestrator::class)->processPix($order, $customer, $company);
    $payment = Payment::where('order_id', $order->id)->firstOrFail();

    // 110 × (1 − 0,85% − 0,14%) = 108,911; comissão de 1% só sobre 98,911 (sem os R$ 10 de entrega).
    expect((float) $payment->company_net_amount)->toBe(107.92)
        ->and((float) $payment->platform_fee)->toBe(1.14);

    financeConfirmPayment($order, $payment);

    $transaction = CompanyTransaction::withoutGlobalScopes()->where('payment_id', $payment->id)->sole();

    expect((float) $transaction->net_value)->toBe(107.92)
        ->and(CompanyWalletEntry::balanceFor($company->id))->toBe(107.92)
        ->and((float) CompanyWalletEntry::where('order_id', $order->id)->where('type', 'credit')->value('amount'))->toBe(110.0);
});

test('cartão: taxa da plataforma do split entra na carteira (não é mais zero)', function () {
    ['company' => $company, 'order' => $order] = financeLedgerContext(['payment_method' => 'credit_card']);

    // Valores gravados pelo orchestrator na criação da cobrança.
    $payment = Payment::create([
        'order_id' => $order->id,
        'vindi_transaction_token' => 'vindi_tok_card_ledger',
        'payment_gateway' => 'vindi',
        'amount' => 113.50,
        'original_amount' => 110.00,
        'card_fee' => 3.50,
        'card_fee_rate' => 0.0308,
        'platform_fee' => 1.00,
        'company_net_amount' => 109.00,
        'status' => 'pending',
        'payment_token' => hash('sha256', 'card-ledger'),
    ]);

    financeConfirmPayment($order, $payment);

    expect(CompanyWalletEntry::balanceFor($company->id))->toBe(109.0)
        ->and((float) CompanyWalletEntry::where('order_id', $order->id)->where('type', 'fee')->value('amount'))->toBe(1.0)
        ->and((float) CompanyTransaction::withoutGlobalScopes()->where('payment_id', $payment->id)->value('net_value'))->toBe(109.0);
});

test('createForPayment é idempotente por pagamento', function () {
    ['order' => $order] = financeLedgerContext();

    $payment = Payment::create([
        'order_id' => $order->id,
        'vindi_transaction_token' => 'vindi_tok_idem',
        'payment_gateway' => 'vindi',
        'amount' => 110.00,
        'platform_fee' => 1.14,
        'company_net_amount' => 107.92,
        'status' => 'paid',
        'payment_token' => hash('sha256', 'idem'),
    ]);

    $first = app(TransactionServiceInterface::class)->createForPayment($order, $payment);
    $second = app(TransactionServiceInterface::class)->createForPayment($order, $payment);

    expect($second->id)->toBe($first->id)
        ->and(CompanyTransaction::withoutGlobalScopes()->where('payment_id', $payment->id)->count())->toBe(1);
});

test('estorno reverte os lançamentos originais mesmo se a taxa do plano mudou depois', function () {
    ['company' => $company, 'order' => $order] = financeLedgerContext();

    $payment = Payment::create([
        'order_id' => $order->id,
        'asaas_payment_id' => 'pay_ledger_plan_change',
        'payment_gateway' => 'asaas',
        'amount' => 110.00,
        'status' => 'pending',
        'payment_token' => hash('sha256', 'plan-change'),
    ]);

    financeConfirmPayment($order, $payment);
    $balanceAfterCredit = CompanyWalletEntry::balanceFor($company->id);

    // Taxa do plano muda entre o pagamento e o estorno.
    config()->set('plans.free.fee_percentage', 0.05);

    app(WalletServiceInterface::class)->debitForRefund($order->fresh(), $payment->fresh());

    expect($balanceAfterCredit)->toBe(109.0) // 110 − 1% de 100 (sem a entrega)
        ->and(CompanyWalletEntry::balanceFor($company->id))->toBe(0.0);
});

test('estorno concluído tira a transação do saldo e é idempotente', function () {
    ['company' => $company, 'order' => $order] = financeLedgerContext();

    $payment = Payment::create([
        'order_id' => $order->id,
        'vindi_transaction_token' => 'vindi_tok_refund_ledger',
        'payment_gateway' => 'vindi',
        'amount' => 110.00,
        'platform_fee' => 1.14,
        'company_net_amount' => 107.92,
        'status' => 'pending',
        'payment_token' => hash('sha256', 'refund-ledger'),
    ]);

    financeConfirmPayment($order, $payment);
    expect(app(BalanceService::class)->calculateBalance($company)['blocked_balance'])->toBe(107.92);

    $refund = PaymentRefund::create([
        'company_id' => $company->id,
        'order_id' => $order->id,
        'payment_id' => $payment->id,
        'gateway' => 'vindi',
        'amount' => 110.00,
        'status' => 'in_progress',
        'requested_by_type' => 'admin',
        'requested_at' => now(),
    ]);

    app(RefundServiceInterface::class)->markSucceeded($refund, ['external_status' => 'Estornada']);
    $entriesAfterFirst = CompanyWalletEntry::where('order_id', $order->id)->count();

    app(RefundServiceInterface::class)->markSucceeded($refund->fresh(), ['external_status' => 'Estornada']);

    $balance = app(BalanceService::class)->calculateBalance($company);

    expect(CompanyTransaction::withoutGlobalScopes()->where('payment_id', $payment->id)->value('status'))->toBe('refunded')
        ->and($balance['blocked_balance'])->toBe(0.0)
        ->and($balance['available_balance'])->toBe(0.0)
        ->and(CompanyWalletEntry::where('order_id', $order->id)->count())->toBe($entriesAfterFirst)
        ->and(CompanyWalletEntry::balanceFor($company->id))->toBe(0.0);
});

test('estorno offline conclui sem nunca enfileirar o estorno no gateway', function () {
    Queue::fake();
    ['company' => $company, 'order' => $order] = financeLedgerContext();

    $payment = Payment::create([
        'order_id' => $order->id,
        'vindi_transaction_token' => 'vindi_tok_offline',
        'payment_gateway' => 'vindi',
        'amount' => 110.00,
        'platform_fee' => 1.14,
        'company_net_amount' => 107.92,
        'status' => 'pending',
        'payment_token' => hash('sha256', 'offline'),
    ]);

    financeConfirmPayment($order, $payment);

    $refund = app(RefundServiceInterface::class)->recordOfflineRefund(
        $order->fresh(), $payment->fresh(), 'admin', null, 'store_issue', ['justification' => 'Devolvido em dinheiro no balcão'],
    );

    Queue::assertNotPushed(ProcessRefund::class);

    expect($refund->gateway)->toBe('offline')
        ->and($refund->status)->toBe('succeeded')
        ->and($payment->fresh()->status)->toBe('refunded')
        ->and(CompanyWalletEntry::balanceFor($company->id))->toBe(0.0);
});

test('saldo trata como liberada a transação confirmada com data de liberação vencida', function () {
    ['company' => $company] = financeLedgerContext();

    $base = [
        'company_id' => $company->id,
        'type' => 'pix',
        'value' => 10,
        'payment_date' => now()->subDays(5)->toDateString(),
    ];

    CompanyTransaction::withoutGlobalScopes()->create($base + ['status' => 'confirmed', 'net_value' => 10, 'release_date' => now()->subDay()->toDateString()]);
    CompanyTransaction::withoutGlobalScopes()->create($base + ['status' => 'released', 'net_value' => 5, 'release_date' => now()->subDays(2)->toDateString()]);
    CompanyTransaction::withoutGlobalScopes()->create($base + ['status' => 'confirmed', 'net_value' => 20, 'release_date' => now()->addDays(3)->toDateString()]);
    CompanyTransaction::withoutGlobalScopes()->create($base + ['status' => 'refunded', 'net_value' => 40, 'release_date' => now()->subDay()->toDateString()]);

    $balance = app(BalanceService::class)->calculateBalance($company);

    expect($balance['available_balance'])->toBe(15.0)
        ->and($balance['blocked_balance'])->toBe(20.0)
        ->and($balance['total_balance'])->toBe(35.0);
});

test('carteira mostra estorno de taxa como devolução e o líquido de cada crédito', function () {
    ['company' => $company, 'order' => $order] = financeLedgerContext();

    $payment = Payment::create([
        'order_id' => $order->id,
        'vindi_transaction_token' => 'vindi_tok_view',
        'payment_gateway' => 'vindi',
        'amount' => 110.00,
        'platform_fee' => 1.14,
        'company_net_amount' => 107.92,
        'status' => 'pending',
        'payment_token' => hash('sha256', 'view'),
    ]);

    financeConfirmPayment($order, $payment);
    app(WalletServiceInterface::class)->debitForRefund($order->fresh(), $payment->fresh());

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(CompanyWallet::class)
        ->assertSee('Taxas R$ 2,08 · líquido R$ 107,92')
        ->assertSee('- R$ 110,00', false)
        ->assertSee('+ R$ 1,14', false);
});
