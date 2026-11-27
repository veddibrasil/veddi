<?php

use App\Livewire\Admin\Pdv\Report;
use App\Livewire\Admin\Pdv\Terminal;
use App\Models\CompanyTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Fiscal\FiscalAutoIssuePolicy;
use App\Services\Fiscal\FiscalNoteService;
use App\Services\Pdv\CashClosingReportService;
use App\Services\Sales\SoldItemsReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Liga "Consumo de funcionário" no balcão da filial e cria um funcionário (caixa) da empresa. */
function staffContext(): array
{
    $ctx = pdvContext();
    $ctx['branch']->update(['payment_methods' => ['pdv' => ['balcao' => ['cash', 'pix', 'credit_card', 'staff']]]]);

    $staff = User::factory()->create(['name' => 'Maria Cozinha']);
    $ctx['company']->users()->attach($staff->id, ['role' => 'cozinha', 'branch_id' => $ctx['branch']->id]);

    return $ctx + ['staff' => $staff];
}

function sellToStaff(User $admin, $product, ?int $staffId, int $qty = 1)
{
    test()->actingAs($admin);

    $component = Livewire::test(Terminal::class);
    foreach (range(1, $qty) as $_) {
        $component->call('addProduct', $product->id);
    }

    return $component
        ->call('proceedToPayment')
        ->set('paymentMethod', 'staff')
        ->set('staffUserId', $staffId)
        ->call('processOrder');
}

test('consumo de funcionário fica desligado por padrão na filial', function () {
    ['branch' => $branch] = pdvContext();

    expect($branch->paymentMethodsFor('pdv', 'balcao'))->not->toContain('staff');
});

test('consumo de funcionário só existe no balcão, mesmo configurado em outra modalidade', function () {
    ['branch' => $branch] = staffContext();
    $branch->update(['payment_methods' => ['pdv' => [
        'balcao' => ['cash', 'staff'], 'mesa' => ['cash', 'staff'], 'entrega' => ['cash', 'staff'],
    ]]]);

    expect($branch->fresh()->paymentMethodsFor('pdv', 'balcao'))->toContain('staff')
        ->and($branch->fresh()->paymentMethodsFor('pdv', 'mesa'))->not->toContain('staff')
        ->and($branch->fresh()->paymentMethodsFor('pdv', 'entrega'))->not->toContain('staff');
});

test('venda ao funcionário fecha o pedido como pago, sem taxa e ligado ao funcionário', function () {
    ['admin' => $admin, 'product' => $product, 'staff' => $staff] = staffContext();

    sellToStaff($admin, $product, $staff->id, 2)
        ->assertHasNoErrors()
        ->assertSet('step', 'catalog');

    $order = Order::withoutGlobalScopes()->sole();
    expect($order->payment_method)->toBe('staff')
        ->and($order->status)->toBe('paid')
        ->and($order->staff_user_id)->toBe($staff->id)
        ->and((float) $order->total)->toBe(16.0)
        ->and((float) $order->fee)->toBe(0.0)
        ->and($order->fiscal_auto_skip)->toBeTrue()
        ->and($order->staff_settled_at)->toBeNull();

    $payment = Payment::where('order_id', $order->id)->sole();
    expect($payment->payment_gateway)->toBe('staff_account')->and($payment->status)->toBe('paid');
});

test('sem funcionário selecionado a venda é barrada', function () {
    ['admin' => $admin, 'product' => $product] = staffContext();

    sellToStaff($admin, $product, null)->assertHasErrors('order')->assertSet('step', 'payment');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

test('funcionário de outra empresa não é aceito', function () {
    ['admin' => $admin, 'product' => $product] = staffContext();
    $outsider = User::factory()->create();

    sellToStaff($admin, $product, $outsider->id)->assertHasErrors('order');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

test('filial com a forma desligada recusa o consumo de funcionário forjado', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'staff' => $staff] = staffContext();
    $branch->update(['payment_methods' => ['pdv' => ['balcao' => ['cash']]]]);

    sellToStaff($admin, $product, $staff->id)->assertHasErrors('order');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

test('pagamento dividido não oferece consumo de funcionário', function () {
    ['admin' => $admin, 'product' => $product] = staffContext();
    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('isSplitPayment', true);

    expect(collect($component->get('splitPayments'))->pluck('method')->all())->not->toContain('staff');
});

test('consumo de funcionário não entra em caixa, faturamento, itens vendidos, carteira nem nota', function () {
    ['admin' => $admin, 'product' => $product, 'staff' => $staff, 'company' => $company] = staffContext();

    // Uma venda normal em dinheiro e uma ao funcionário.
    test()->actingAs($admin);
    Livewire::test(Terminal::class)->call('addProduct', $product->id)->call('proceedToPayment')
        ->set('paymentMethod', 'cash')->call('processOrder');
    sellToStaff($admin, $product, $staff->id, 3);

    expect(Order::withoutGlobalScopes()->count())->toBe(2);
    $staffOrder = Order::withoutGlobalScopes()->whereNotNull('staff_user_id')->sole();
    $session = \App\Models\PdvCashSession::withoutGlobalScopes()->sole();

    // Caixa: só os R$ 8 em dinheiro; nada do consumo.
    $report = app(CashClosingReportService::class)->build($session);
    expect($report['cash_sales'])->toBe(8.0)
        ->and($report['expected'])->toBe(8.0)
        ->and($report['revenue'])->toBe(8.0)
        ->and($report['orders_count'])->toBe(1)
        ->and($report['top_products']->sum('total_qty'))->toBe(1);

    // Faturamento do relatório PDV.
    Livewire::test(Report::class)->assertViewHas('totalRevenue', 8.0);

    // Itens vendidos.
    $sold = app(SoldItemsReportService::class);
    $last = $sold->lastSaleDates($company->id, null, \Carbon\CarbonImmutable::now(), 7);
    expect($last)->toHaveKey($product->id);

    // Carteira: nenhuma transação criada.
    expect(CompanyTransaction::withoutGlobalScopes()->count())->toBe(0);

    // Nota fiscal: nem automática nem manual.
    expect(FiscalAutoIssuePolicy::candidate($staffOrder))->toBeFalse();
    expect(app(FiscalNoteService::class)->preflight($staffOrder))->toContain('não é venda');
});

test('relatório lista o débito por funcionário e o acerto zera o em aberto', function () {
    ['admin' => $admin, 'product' => $product, 'staff' => $staff] = staffContext();

    sellToStaff($admin, $product, $staff->id, 2);
    sellToStaff($admin, $product, $staff->id, 1);

    $component = Livewire::test(Report::class);
    $row = $component->instance()->staffConsumption->sole();
    expect($row['name'])->toBe('Maria Cozinha')->and($row['pending'])->toBe(24.0)->and($row['settled'])->toBe(0.0);

    $component->call('settleStaffConsumption', $staff->id);

    $row = $component->instance()->staffConsumption->sole();
    expect($row['pending'])->toBe(0.0)->and($row['settled'])->toBe(24.0);
});

test('pedido de consumo cancelado sai do débito do funcionário', function () {
    ['admin' => $admin, 'product' => $product, 'staff' => $staff] = staffContext();

    sellToStaff($admin, $product, $staff->id, 2);
    Order::withoutGlobalScopes()->update(['status' => 'cancelled']);

    expect(Livewire::test(Report::class)->instance()->staffConsumption)->toBeEmpty();
});

// ─── Consumo de funcionário nos relatórios ────────────────────────────────────

test('fechamento de caixa lista o consumo por funcionário, fora das vendas', function () {
    ['admin' => $admin, 'product' => $product, 'staff' => $staff] = staffContext();

    sellToStaff($admin, $product, $staff->id, 2);
    sellToStaff($admin, $product, $staff->id, 1);

    $session = \App\Models\PdvCashSession::withoutGlobalScopes()->sole();
    $report = app(CashClosingReportService::class)->build($session);

    expect($report['revenue'])->toBe(0.0)
        ->and($report['staff']['orders'])->toBe(2)
        ->and($report['staff']['total'])->toBe(24.0)
        ->and($report['staff']['by_user'])->toBe([['name' => 'Maria Cozinha', 'orders' => 2, 'total' => 24.0]]);

    $session->update(['closed_at' => now(), 'closing_amount' => 0, 'expected_amount' => 0]);

    $this->get(route('admin.pdv.cash-session.print', $session))->assertOk();
    $html = view('livewire.admin.pdv.cash-closing-receipt', [
        'report' => app(CashClosingReportService::class)->build($session->fresh()),
        'company' => $session->company,
    ])->render();
    expect($html)->toContain('CONSUMO DE FUNCIONÁRIOS')->toContain('Maria Cozinha')->toContain('R$ 24,00');
});

test('fechamento do dia separa o consumo de funcionário do total', function () {
    ['admin' => $admin, 'product' => $product, 'staff' => $staff] = staffContext();

    test()->actingAs($admin);
    Livewire::test(Terminal::class)->call('addProduct', $product->id)->call('proceedToPayment')
        ->set('paymentMethod', 'cash')->call('processOrder');
    sellToStaff($admin, $product, $staff->id, 3);

    $report = app(\App\Services\Order\OrderClosingReportService::class)->build(now(), false, null);

    expect($report['pdv']['revenue'])->toBe(8.0)
        ->and($report['pdv']['count'])->toBe(1)
        ->and($report['pdv']['staff'])->toBe(['count' => 1, 'total' => 24.0])
        ->and($report['geral']['staff'])->toBe(['count' => 1, 'total' => 24.0]);

    $html = view('livewire.admin.orders.closing-receipt', ['report' => $report, 'company' => null])->render();
    expect($html)->toContain('Consumo de funcionários (1)');
});

test('relatório de pedidos mostra o consumo à parte e mantém receita só com vendas', function () {
    ['admin' => $admin, 'product' => $product, 'staff' => $staff] = staffContext();

    test()->actingAs($admin);
    Livewire::test(Terminal::class)->call('addProduct', $product->id)->call('proceedToPayment')
        ->set('paymentMethod', 'cash')->call('processOrder');
    sellToStaff($admin, $product, $staff->id, 3);

    Livewire::test(\App\Livewire\Admin\Orders\Report::class)
        ->set('dateStart', now()->subDay()->format('Y-m-d'))
        ->set('dateEnd', now()->addDay()->format('Y-m-d'))
        ->assertViewHas('totalRevenue', 8.0)
        ->assertViewHas('staffCount', 1)
        ->assertViewHas('staffTotal', 24.0)
        ->assertSee('Consumo de funcionários');

    $this->get(route('admin.orders.report.pdf', [
        'date_start' => now()->subDay()->format('Y-m-d'),
        'date_end' => now()->addDay()->format('Y-m-d'),
    ]))->assertOk();

    $html = view('livewire.admin.orders.report-pdf', [
        'orders' => Order::withoutGlobalScopes()->with(['customer', 'branch'])->get(),
        'company' => null,
    ])->render();
    expect($html)->toContain('Receita: R$ 8,00')->toContain('Consumo de funcionários (fora da receita): 1 pedido(s), R$ 24,00');
});
