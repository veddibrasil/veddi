<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PdvAuditLog;
use App\Models\PdvCashSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
