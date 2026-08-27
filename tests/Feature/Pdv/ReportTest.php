<?php

use App\Livewire\Admin\Pdv\Report;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\PdvCashSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function pdvReportContext(): array
{
    $company = Company::create([
        'name' => 'PDV Report Teste',
        'slug' => 'pdv-report-'.uniqid(),
        'order_prefix' => 'RPT',
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

    return compact('company', 'branch', 'admin');
}

// ─── Acesso ───────────────────────────────────────────────────────────────────

test('usuário sem permissão pdv.operate recebe 403 no relatório PDV', function () {
    $company = Company::create([
        'name' => 'Sem PDV',
        'slug' => 'sem-pdv-report-'.uniqid(),
        'order_prefix' => 'SRR',
        'active' => true,
        'plan' => 'pro',
    ]);

    app()->instance('current.company', $company);

    Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua A',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $admin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.pdv.report'))
        ->assertForbidden();
});

// ─── Métricas ─────────────────────────────────────────────────────────────────

test('relatório PDV exibe totais por método de pagamento', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch] = pdvReportContext();

    $customer = \App\Models\Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Balcão',
        'phone' => 'pdv-guest',
    ]);

    Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 10.00,
        'total' => 10.00,
        'fee' => 0,
        'net_value' => 10.00,
        'status' => 'paid',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
    ]);

    Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 20.00,
        'total' => 20.00,
        'fee' => 0,
        'net_value' => 20.00,
        'status' => 'paid',
        'payment_method' => 'pix',
        'order_type' => 'pdv',
    ]);

    Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 30.00,
        'total' => 30.00,
        'fee' => 0,
        'net_value' => 30.00,
        'status' => 'paid',
        'payment_method' => 'credit_card',
        'order_type' => 'pdv',
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(Report::class);

    expect($component->get('dateStart'))->not->toBeEmpty();
    $component->assertSee('R$ 10,00'); // cash
    $component->assertSee('R$ 20,00'); // pix
    $component->assertSee('R$ 30,00'); // card
    $component->assertSee('R$ 60,00'); // total
});

test('pedido cancelado não entra nos totais do relatório PDV', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch] = pdvReportContext();

    $customer = \App\Models\Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Balcão',
        'phone' => 'pdv-guest-cancel',
    ]);

    Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 50.00,
        'total' => 50.00,
        'fee' => 0,
        'net_value' => 50.00,
        'status' => 'cancelled',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(Report::class);

    expect((float) $component->viewData('totalRevenue'))->toBe(0.0);
    expect((float) $component->viewData('cashTotal'))->toBe(0.0);
});

// ─── Sessões de caixa ─────────────────────────────────────────────────────────

test('sessões de caixa aparecem na listagem do relatório PDV', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch] = pdvReportContext();

    PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'opening_amount' => 50.00,
        'closing_amount' => 120.00,
        'expected_amount' => 115.00,
        'closed_at' => now(),
    ]);

    $this->actingAs($admin);

    Livewire::test(Report::class)
        ->assertSee('Balcão')
        ->assertSee('Fechado');
});

test('admin consegue imprimir o fechamento de qualquer sessão a partir do relatório PDV', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch] = pdvReportContext();

    $session = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'opening_amount' => 50.00,
        'closing_amount' => 120.00,
        'expected_amount' => 115.00,
        'closed_at' => now(),
    ]);

    $openSession = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'opening_amount' => 30.00,
    ]);

    $this->actingAs($admin);

    Livewire::test(Report::class)
        ->assertSeeHtml(route('admin.pdv.cash-session.print', $session))
        ->assertDontSeeHtml(route('admin.pdv.cash-session.print', $openSession));

    $this->get(route('admin.pdv.cash-session.print', $session))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

// ─── Restrição do caixa ─────────────────────────────────────────────────────────

test('caixa não vê os cards de totais financeiros e fica travado na própria filial', function () {
    ['company' => $company, 'branch' => $branch] = pdvReportContext();

    $otherBranch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Outra Filial',
        'address' => 'Rua B, 2',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $caixa = User::factory()->create();
    $caixa->companies()->attach($company->id, ['role' => 'caixa', 'branch_id' => $branch->id]);

    $this->actingAs($caixa);

    $component = Livewire::test(Report::class);

    $component->assertDontSee('Total PDV')
        ->assertDontSee('Dinheiro')
        ->assertDontSee('PIX')
        ->assertDontSee('Cartão')
        ->assertDontSee('Descontos')
        ->assertDontSee('Outra Filial');

    expect($component->get('branchFilter'))->toBe((string) $branch->id);

    // Tentativa de manipular o filtro pra outra filial via Livewire é ignorada.
    $component->set('branchFilter', (string) $otherBranch->id);
    expect($component->get('branchFilter'))->toBe((string) $branch->id);
});

test('caixa só vê pedidos e sessões do próprio caixa, não os de outro operador na mesma filial', function () {
    ['company' => $company, 'branch' => $branch] = pdvReportContext();

    $caixa = User::factory()->create();
    $caixa->companies()->attach($company->id, ['role' => 'caixa', 'branch_id' => $branch->id]);

    $outroCaixa = User::factory()->create();
    $outroCaixa->companies()->attach($company->id, ['role' => 'caixa', 'branch_id' => $branch->id]);

    $ownSession = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $caixa->id,
        'opening_amount' => 0,
        'closing_amount' => 50,
        'expected_amount' => 50,
        'closed_at' => now(),
    ]);

    $otherSession = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $outroCaixa->id,
        'terminal_name' => 'Terminal do outro',
        'opening_amount' => 0,
        'closing_amount' => 90,
        'expected_amount' => 90,
        'closed_at' => now(),
    ]);

    $customer = \App\Models\Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Balcão',
        'phone' => 'pdv-report-scoped',
    ]);

    $ownOrder = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'pdv_cash_session_id' => $ownSession->id,
        'order_number' => 'RPT-OWN',
        'subtotal' => 15.00,
        'total' => 15.00,
        'fee' => 0,
        'net_value' => 15.00,
        'status' => 'paid',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
    ]);

    Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'pdv_cash_session_id' => $otherSession->id,
        'order_number' => 'RPT-OTHER',
        'subtotal' => 25.00,
        'total' => 25.00,
        'fee' => 0,
        'net_value' => 25.00,
        'status' => 'paid',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
    ]);

    $this->actingAs($caixa);

    Livewire::test(Report::class)
        ->assertSee($ownOrder->order_number)
        ->assertDontSee('RPT-OTHER')
        ->assertDontSee('Terminal do outro');
});
