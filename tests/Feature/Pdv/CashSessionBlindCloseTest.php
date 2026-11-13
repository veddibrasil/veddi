<?php

/*
 * Fechamento cego do caixa: o operador vê só o turno, a abertura e informa o valor contado —
 * esperado, vendas e diferença ficam só para o administrador, que também acompanha o que um
 * caixa ainda aberto já gerou (overlay de fechamentos do PDV e Relatório PDV).
 */

use App\Livewire\Admin\Pdv\Report;
use App\Livewire\Admin\Pdv\Terminal;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PdvCashSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function blindCloseCashSale(PdvCashSession $session, float $amount): Order
{
    $customer = Customer::withoutGlobalScopes()->firstOrCreate(
        ['company_id' => $session->company_id, 'phone' => 'pdv-guest'],
        ['name' => 'Balcão'],
    );

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $session->company_id,
        'customer_id' => $customer->id,
        'branch_id' => $session->branch_id,
        'pdv_cash_session_id' => $session->id,
        'subtotal' => $amount,
        'total' => $amount,
        'fee' => 0,
        'net_value' => $amount,
        'status' => 'paid',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
        'is_open_tab' => false,
    ]);

    Payment::create([
        'order_id' => $order->id,
        'payment_gateway' => 'cash',
        'amount' => $amount,
        'status' => 'paid',
        'paid_at' => now(),
        'payment_token' => 'blind-close-'.uniqid(),
    ]);

    return $order;
}

/** Sessão aberta do admin (abertura R$ 50, R$ 30 em dinheiro) e de outro operador na mesma filial. */
function blindCloseContext(): array
{
    $context = pdvContext();

    $adminSession = PdvCashSession::withoutGlobalScopes()->where('user_id', $context['admin']->id)->first();
    $adminSession->update(['opening_amount' => 50.00]);
    blindCloseCashSale($adminSession, 30.00);

    $operator = User::factory()->create(['name' => 'Operadora Turno Tarde']);
    $operatorSession = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $context['company']->id,
        'branch_id' => $context['branch']->id,
        'user_id' => $operator->id,
        'terminal_name' => 'Caixa Dois',
        'opening_amount' => 20.00,
    ]);
    blindCloseCashSale($operatorSession, 15.00);

    return $context + compact('adminSession', 'operatorSession');
}

function blindCloseCaixa(Company $company, Branch $branch): User
{
    $caixa = User::factory()->create();
    $caixa->companies()->attach($company->id, ['role' => 'caixa', 'branch_id' => $branch->id]);

    PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $caixa->id,
        'opening_amount' => 0,
    ]);

    return $caixa;
}

test('tela de fechar caixa mostra só turno, abertura e valor contado', function () {
    ['admin' => $admin] = blindCloseContext();

    Livewire::actingAs($admin)
        ->test(Terminal::class)
        ->call('proceedToCloseCash')
        ->assertSee('Turno')
        ->assertSee('Abertura')
        ->assertSee('R$ 50,00')
        ->assertSee('Valor contado no caixa')
        ->assertDontSee('Esperado no caixa')
        ->assertDontSee('Vendas em dinheiro')
        ->assertDontSee('Faturamento total')
        ->assertDontSee('80,00');
});

test('diferença entre contado e esperado não bloqueia o fechamento cego', function () {
    ['admin' => $admin, 'adminSession' => $session] = blindCloseContext();

    Livewire::actingAs($admin)
        ->test(Terminal::class)
        ->call('proceedToCloseCash')
        ->set('closingAmountInput', '10')
        ->call('closeCashSession')
        ->assertHasNoErrors();

    $session->refresh();

    expect($session->closed_at)->not->toBeNull()
        ->and($session->closing_amount)->toBe(10.0)
        ->and($session->expected_amount)->toBe(80.0);
});

test('administrador vê o andamento de um caixa aberto nos fechamentos do PDV', function () {
    ['admin' => $admin, 'operatorSession' => $operatorSession] = blindCloseContext();

    Livewire::actingAs($admin)
        ->test(Terminal::class)
        ->call('openClosingReports')
        ->assertSee('Caixas abertos')
        ->assertSee('Caixa Dois')
        ->call('viewClosedSession', $operatorSession->id)
        ->assertSee('Caixa em andamento')
        ->assertSee('Operadora Turno Tarde')
        ->assertSee('Esperado no caixa agora')
        ->assertSee('R$ 35,00');
});

test('caixa não vê caixas abertos nem o andamento do próprio caixa', function () {
    ['company' => $company, 'branch' => $branch] = blindCloseContext();
    $caixa = blindCloseCaixa($company, $branch);

    $component = Livewire::actingAs($caixa)->test(Terminal::class);

    expect($component->instance()->openSessions)->toBeEmpty();

    $ownSession = PdvCashSession::withoutGlobalScopes()->where('user_id', $caixa->id)->first();

    expect(fn () => $component->instance()->liveSessionReport($ownSession))->toThrow(HttpException::class);
});

test('administrador vê o esperado parcial e o andamento do caixa aberto no Relatório PDV', function () {
    ['admin' => $admin, 'operatorSession' => $operatorSession] = blindCloseContext();

    Livewire::actingAs($admin)
        ->test(Report::class)
        ->assertSee('até agora')
        ->assertSee('R$ 35,00')
        ->call('viewLiveSession', $operatorSession->id)
        ->assertSet('showLiveSession', true)
        ->assertSee('Caixa em andamento')
        ->assertSee('Operadora Turno Tarde')
        ->assertSee('Esperado no caixa agora');
});

test('caixa não abre o andamento de caixa no Relatório PDV', function () {
    ['company' => $company, 'branch' => $branch, 'operatorSession' => $operatorSession] = blindCloseContext();
    $caixa = blindCloseCaixa($company, $branch);

    Livewire::actingAs($caixa)
        ->test(Report::class)
        ->assertDontSee('até agora')
        ->assertDontSee('Esperado no caixa agora')
        ->call('viewLiveSession', $operatorSession->id)
        ->assertForbidden();
});

test('andamento de caixa no Relatório PDV não mostra caixa de outra empresa', function () {
    ['admin' => $admin, 'company' => $company] = blindCloseContext();

    $other = Company::create([
        'name' => 'Outra Empresa',
        'slug' => 'outra-'.uniqid(),
        'order_prefix' => 'OUT',
        'active' => true,
        'plan' => 'pro',
        'pdv_module_enabled' => true,
    ]);
    $otherBranch = Branch::withoutGlobalScopes()->create([
        'company_id' => $other->id,
        'name' => 'Filial Alheia',
        'address' => 'Rua B, 2',
        'city' => 'SP',
        'active' => true,
    ]);
    $foreignSession = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $other->id,
        'branch_id' => $otherBranch->id,
        'user_id' => User::factory()->create()->id,
        'terminal_name' => 'Terminal Alheio',
        'opening_amount' => 999.00,
    ]);

    app()->instance('current.company', $company);

    Livewire::actingAs($admin)
        ->test(Report::class)
        ->call('viewLiveSession', $foreignSession->id)
        ->assertDontSee('Terminal Alheio')
        ->assertDontSee('999,00');
});

test('caixa não vê o faturamento do turno na barra do PDV; administrador vê', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = blindCloseContext();
    $caixa = blindCloseCaixa($company, $branch);
    blindCloseCashSale(PdvCashSession::withoutGlobalScopes()->where('user_id', $caixa->id)->first(), 42.50);

    Livewire::actingAs($caixa)
        ->test(Terminal::class)
        ->assertSee('Turno aberto há')
        ->assertDontSee('42,50');

    Livewire::actingAs($admin)
        ->test(Terminal::class)
        ->assertSee('R$ 30,00');
});

test('caixa vê só turno, abertura e contado depois de fechar o caixa', function () {
    ['company' => $company, 'branch' => $branch] = blindCloseContext();
    $caixa = blindCloseCaixa($company, $branch);
    $session = PdvCashSession::withoutGlobalScopes()->where('user_id', $caixa->id)->first();
    $session->update(['opening_amount' => 25.00]);
    blindCloseCashSale($session, 42.50);

    Livewire::actingAs($caixa)
        ->test(Terminal::class)
        ->call('proceedToCloseCash')
        ->set('closingAmountInput', '60')
        ->call('closeCashSession')
        ->assertHasNoErrors()
        ->assertSet('viewingClosedSessionId', $session->id)
        ->assertSee('Caixa fechado')
        ->assertSee('R$ 25,00')
        ->assertSee('R$ 60,00')
        ->assertDontSee('Esperado no caixa')
        ->assertDontSee('Faturamento total')
        ->assertDontSee('Sobra')
        ->assertDontSee('Falta')
        ->assertDontSee('42,50')
        ->assertDontSee('67,50');
});

test('erro de sangria acima do disponível não revela o valor da gaveta ao caixa', function () {
    ['company' => $company, 'branch' => $branch] = blindCloseContext();
    $caixa = blindCloseCaixa($company, $branch);
    blindCloseCashSale(PdvCashSession::withoutGlobalScopes()->where('user_id', $caixa->id)->first(), 42.50);

    $component = Livewire::actingAs($caixa)
        ->test(Terminal::class)
        ->call('toggleCashMovementForm', 'withdrawal')
        ->set('cashMovementAmountInput', '100')
        ->set('cashMovementReason', 'Cofre')
        ->call('registerCashMovement')
        ->assertHasErrors('cash_movement_amount');

    expect($component->errors()->first('cash_movement_amount'))
        ->toBe('Sangria maior que o dinheiro disponível no caixa.');
});

test('caixa não vê esperado nem diferença das sessões no Relatório PDV', function () {
    ['company' => $company, 'branch' => $branch] = blindCloseContext();
    $caixa = blindCloseCaixa($company, $branch);

    PdvCashSession::withoutGlobalScopes()->where('user_id', $caixa->id)->update([
        'closing_amount' => 10.00,
        'expected_amount' => 77.70,
        'reconciliation_notes' => 'Nota de conferência',
        'closed_at' => now(),
    ]);

    Livewire::actingAs($caixa)
        ->test(Report::class)
        ->assertSee('R$ 10,00')
        ->assertDontSee('Esperado')
        ->assertDontSee('Diferença')
        ->assertDontSee('77,70')
        ->assertDontSee('67,70')
        ->assertDontSee('Nota de conferência');
});

// ─── Caixa aberto há dias / em outra filial (validação 13/11) ───────────────
// Turno aberto no mês anterior sumia do Relatório PDV (filtro do período pela data de abertura),
// o overlay só mostrava "Aberto às HH:mm" e só listava caixas da filial selecionada.

function blindCloseAgeSession(PdvCashSession $session, \Carbon\CarbonInterface $openedAt): void
{
    PdvCashSession::withoutGlobalScopes()->whereKey($session->id)->update(['created_at' => $openedAt]);
}

test('caixa aberto antes do período aparece no Relatório PDV, primeiro e com o esperado parcial', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch, 'operatorSession' => $operatorSession] = blindCloseContext();
    blindCloseAgeSession($operatorSession, now()->startOfMonth()->subDays(20));

    $oldClosed = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'terminal_name' => 'Fechado mês passado',
        'opening_amount' => 0,
        'closing_amount' => 0,
        'expected_amount' => 0,
        'closed_at' => now()->startOfMonth()->subDays(19),
    ]);
    blindCloseAgeSession($oldClosed, now()->startOfMonth()->subDays(20));

    Livewire::actingAs($admin)
        ->test(Report::class)
        ->assertSee('Caixa Dois')
        ->assertSee($operatorSession->fresh()->created_at->format('d/m/Y H:i'))
        ->assertSee('R$ 35,00')
        ->assertDontSee('Fechado mês passado');

    // Abertos antes dos fechados na tabela.
    PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'terminal_name' => 'Fechado hoje',
        'opening_amount' => 0,
        'closing_amount' => 0,
        'expected_amount' => 0,
        'closed_at' => now(),
    ]);

    $html = Livewire::actingAs($admin)->test(Report::class)->html();

    expect(strpos($html, 'Caixa Dois'))->toBeLessThan(strpos($html, 'Fechado hoje'));
});

test('Relatório PDV sem datas continua listando sessões fechadas', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch] = blindCloseContext();

    PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'terminal_name' => 'Fechamento antigo',
        'opening_amount' => 0,
        'closing_amount' => 0,
        'expected_amount' => 0,
        'closed_at' => now()->subMonths(3),
    ]);

    Livewire::actingAs($admin)
        ->test(Report::class)
        ->set('dateStart', '')
        ->set('dateEnd', '')
        ->assertSee('Fechamento antigo')
        ->assertSee('Caixa Dois');
});

test('overlay do PDV mostra a data de abertura de caixa aberto há dias', function () {
    ['admin' => $admin, 'operatorSession' => $operatorSession] = blindCloseContext();
    $openedAt = now()->subDays(40)->setTime(14, 27);
    blindCloseAgeSession($operatorSession, $openedAt);

    Livewire::actingAs($admin)
        ->test(Terminal::class)
        ->call('openClosingReports')
        ->assertSee('Aberto em '.$openedAt->format('d/m/Y H:i'))
        ->call('viewClosedSession', $operatorSession->id)
        ->assertSee('Caixa em andamento')
        ->assertSee('R$ 35,00');
});

test('overlay do PDV lista caixa aberto de outra filial para o administrador', function () {
    ['admin' => $admin, 'company' => $company] = blindCloseContext();

    $otherBranch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Shopping',
        'address' => 'Rua C, 3',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);
    $otherSession = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $otherBranch->id,
        'user_id' => User::factory()->create(['name' => 'Operador Shopping'])->id,
        'terminal_name' => 'Caixa Shopping',
        'opening_amount' => 12.00,
    ]);
    blindCloseCashSale($otherSession, 8.00);

    Livewire::actingAs($admin)
        ->test(Terminal::class)
        ->call('openClosingReports')
        ->assertSee('Caixa Dois')
        ->assertSee('Caixa Shopping')
        ->assertSee('Filial Shopping')
        ->call('viewClosedSession', $otherSession->id)
        ->assertSee('Caixa em andamento')
        ->assertSee('Operador Shopping')
        ->assertSee('R$ 20,00');
});

test('gerente preso a uma filial não vê caixa aberto de outra filial no overlay', function () {
    ['company' => $company, 'branch' => $branch] = blindCloseContext();

    $otherBranch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Shopping',
        'address' => 'Rua C, 3',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);
    $otherSession = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $otherBranch->id,
        'user_id' => User::factory()->create()->id,
        'terminal_name' => 'Caixa Shopping',
        'opening_amount' => 12.00,
    ]);

    $manager = User::factory()->create();
    $manager->companies()->attach($company->id, ['role' => 'branch_manager', 'branch_id' => $branch->id]);
    \App\Models\UserPermission::create([
        'user_id' => $manager->id,
        'company_id' => $company->id,
        'permission_id' => \App\Models\Permission::firstOrCreate(['name' => 'pdv.operate'], ['group' => 'pdv', 'label' => 'Operar terminal PDV'])->id,
        'granted' => true,
    ]);

    $component = Livewire::actingAs($manager)
        ->test(Terminal::class)
        ->call('openClosingReports')
        ->assertSee('Caixa Dois')
        ->assertDontSee('Caixa Shopping');

    $component->instance()->viewingClosedSessionId = $otherSession->id;
    unset($component->instance()->viewingClosedSession);

    expect($component->instance()->viewingClosedSession)->toBeNull();
});
