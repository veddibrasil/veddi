<?php

use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PdvAuditLog;
use App\Models\PdvCashSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function cashClosingContext(): array
{
    $company = Company::create([
        'name' => 'Fechamento Teste',
        'slug' => 'fechamento-'.uniqid(),
        'order_prefix' => 'FCH',
        'active' => true,
        'plan' => 'pro',
        'pdv_module_enabled' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Balcão',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $admin = User::factory()->create(['is_super_admin' => true]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Balcão',
        'phone' => 'pdv-guest',
    ]);

    return compact('company', 'branch', 'admin', 'customer');
}

test('operador consegue imprimir fechamento de sessão fechada', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch, 'customer' => $customer] = cashClosingContext();

    $session = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'opening_amount' => 50.00,
        'closing_amount' => 80.00,
        'expected_amount' => 80.00,
        'closed_at' => now(),
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'pdv_cash_session_id' => $session->id,
        'subtotal' => 30.00,
        'total' => 30.00,
        'fee' => 0,
        'net_value' => 30.00,
        'status' => 'paid',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
        'is_open_tab' => false,
    ]);

    $category = \App\Models\ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Salgados',
    ]);

    $product = \App\Models\Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Coxinha',
        'price' => 10.00,
        'active' => true,
    ]);

    \App\Models\OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => 'Coxinha',
        'unit_price' => 10.00,
        'quantity' => 3,
        'subtotal' => 30.00,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.pdv.cash-session.print', $session))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('caixa não consegue imprimir fechamento (só visualiza)', function () {
    ['company' => $company, 'branch' => $branch] = cashClosingContext();

    $caixa = User::factory()->create();
    $caixa->companies()->attach($company->id, ['role' => 'caixa', 'branch_id' => $branch->id]);

    $session = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $caixa->id,
        'opening_amount' => 50.00,
        'closing_amount' => 80.00,
        'expected_amount' => 80.00,
        'closed_at' => now(),
    ]);

    $this->actingAs($caixa)
        ->get(route('admin.pdv.cash-session.print', $session))
        ->assertForbidden();
});

test('sessão de outra empresa não pode ser impressa', function () {
    ['admin' => $admin] = cashClosingContext();

    $otherCompany = Company::create([
        'name' => 'Outra Empresa',
        'slug' => 'outra-'.uniqid(),
        'order_prefix' => 'OUT',
        'active' => true,
        'plan' => 'pro',
        'pdv_module_enabled' => true,
    ]);

    $otherBranch = Branch::withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Filial Outra',
        'address' => 'Rua B, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $otherSession = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id,
        'branch_id' => $otherBranch->id,
        'user_id' => $admin->id,
        'opening_amount' => 10.00,
        'closing_amount' => 10.00,
        'expected_amount' => 10.00,
        'closed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.pdv.cash-session.print', $otherSession))
        ->assertNotFound();
});

test('sessão ainda aberta não pode ser impressa', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch] = cashClosingContext();

    $openSession = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'opening_amount' => 50.00,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.pdv.cash-session.print', $openSession))
        ->assertNotFound();
});

test('cancelados não entram no total de vendas do fechamento e retiradas aparecem no histórico', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch, 'customer' => $customer] = cashClosingContext();

    $session = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'opening_amount' => 50.00,
        'closing_amount' => 50.00,
        'expected_amount' => 50.00,
        'closed_at' => now(),
    ]);

    Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'pdv_cash_session_id' => $session->id,
        'subtotal' => 99.00,
        'total' => 99.00,
        'fee' => 0,
        'net_value' => 99.00,
        'status' => 'cancelled',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
        'is_open_tab' => false,
    ]);

    PdvAuditLog::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'pdv_cash_session_id' => $session->id,
        'user_id' => $admin->id,
        'action' => 'cash_withdrawal',
        'amount' => 15.00,
        'reason' => 'Pagamento fornecedor',
    ]);

    $report = app(\App\Services\Pdv\CashClosingReportService::class)->build($session->fresh());

    expect($report['revenue'])->toBe(0.0)
        ->and($report['orders_count'])->toBe(0)
        ->and($report['cancelled_count'])->toBe(1)
        ->and($report['withdrawals'])->toBe(15.0)
        ->and($report['movements'])->toHaveCount(1);
});

test('pedido com pagamento dividido bate por gateway no fechamento de caixa, não por pedido inteiro', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch, 'customer' => $customer] = cashClosingContext();

    $session = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'opening_amount' => 0.00,
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'pdv_cash_session_id' => $session->id,
        'subtotal' => 50.00,
        'total' => 50.00,
        'fee' => 0,
        'net_value' => 50.00,
        'status' => 'paid',
        'payment_method' => 'split',
        'order_type' => 'pdv',
        'is_open_tab' => false,
    ]);

    \App\Models\Payment::create([
        'order_id' => $order->id,
        'payment_gateway' => 'cash',
        'amount' => 20.00,
        'status' => 'paid',
        'paid_at' => now(),
        'payment_token' => 'test-cashreport-cash-token',
    ]);

    \App\Models\Payment::create([
        'order_id' => $order->id,
        'payment_gateway' => 'card_machine',
        'amount' => 30.00,
        'status' => 'paid',
        'paid_at' => now(),
        'payment_token' => 'test-cashreport-card-token',
    ]);

    $report = app(\App\Services\Pdv\CashClosingReportService::class)->build($session->fresh());

    // Só a perna cash entra no "esperado no caixa" — não os R$50 inteiros do pedido.
    expect($report['cash_sales'])->toBe(20.0)
        ->and($report['expected'])->toBe(20.0)
        ->and($report['payments']['cash'])->toBe(20.0)
        ->and($report['payments']['credit_card'])->toBe(30.0)
        ->and($report['payments']['pix'])->toBe(0.0);
});

test('pedido de entrega (pagar na entrega) confirmado pelo kanban entra no TOTAL VENDAS e na forma de pagamento — sem gap', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch, 'customer' => $customer] = cashClosingContext();

    $session = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'opening_amount' => 0.00,
    ]);

    // Nasce "aguardando pagamento" (receber na entrega), sem Payment — mesmo estado
    // do pedido real que gerou o gap de R$66,90 no relatório impresso.
    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'pdv_cash_session_id' => $session->id,
        'subtotal' => 66.90,
        'total' => 66.90,
        'fee' => 0,
        'net_value' => 66.90,
        'status' => 'awaiting_payment',
        'payment_method' => 'credit_card',
        'order_type' => 'pdv',
        'is_open_tab' => false,
    ]);

    $this->actingAs($admin);

    // Operador arrasta o card pra "Pago" no kanban ao confirmar que o entregador recebeu.
    Livewire::test(OrdersIndex::class)
        ->call('updateOrderStatus', $order->id, 'paid');

    $report = app(\App\Services\Pdv\CashClosingReportService::class)->build($session->fresh());

    $paymentsSum = array_sum($report['payments']);

    expect($order->fresh()->status)->toBe('paid')
        ->and(\App\Models\Payment::where('order_id', $order->id)->where('status', 'paid')->exists())->toBeTrue()
        ->and($report['revenue'])->toBe(66.90)
        ->and($report['payments']['credit_card'])->toBe(66.90)
        ->and($paymentsSum)->toBe($report['revenue']);
});
