<?php

require_once __DIR__.'/flows_helpers.php';

use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Orders\Report;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderClosingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * AUDITORIA FLUXOS — "Fechamento do dia" (Orders\Index::todayClosing), PDF de fechamento
 * (OrderClosingReportService) e Relatório (Orders\Report) somam como faturamento todo pedido
 * que não está cancelled/refunded — inclusive 'pending' e 'awaiting_payment' do chat, que
 * NUNCA foram pagos. Checkout de cartão abandonado fica 'pending' para sempre (só PIX tem
 * expiração), então infla o faturamento de forma permanente. A contagem inclui cancelados
 * (o ticket médio do Relatório divide pelo total com cancelados) e o PDF não distribui
 * pedidos 'split' do PDV em nenhuma forma de pagamento (Dinheiro+PIX+Cartão ≠ TOTAL).
 */

/** @return array{company: \App\Models\Company, admin: User} */
function flowAuditClosingScenario(): array
{
    $ctx = flowAuditContext(stock: null);

    $make = fn (string $status, float $total, string $type = 'delivery', string $method = 'pix') => Order::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'customer_id' => $ctx['customer']->id,
        'subtotal' => $total,
        'total' => $total,
        'status' => $status,
        'payment_method' => $method,
        'order_type' => $type,
    ]);

    $make('delivered', 50.0, 'delivery', 'pix');        // pago e entregue → conta
    $make('pending', 80.0, 'delivery', 'card');         // checkout de cartão abandonado → não conta
    $make('awaiting_payment', 30.0, 'delivery', 'pix'); // PIX gerado e não pago → não conta
    $make('cancelled', 40.0, 'delivery', 'pix');        // cancelado → não conta

    return ['company' => $ctx['company'], 'admin' => User::factory()->create(['is_super_admin' => true])];
}

test('fechamento do dia (kanban) não soma pedidos não pagos como faturamento', function () {
    ['admin' => $admin] = flowAuditClosingScenario();

    $component = Livewire::actingAs($admin)->test(OrdersIndex::class);
    $closing = (fn () => $this->todayClosing())->call($component->instance());

    // Hoje: total 160 (50 + 80 + 30) e 4 pedidos (inclui o cancelado).
    expect((float) $closing['geral']['total'])->toBe(50.0);
});

test('PDF de fechamento não soma pedidos não pagos como faturamento', function () {
    flowAuditClosingScenario();

    $report = app(OrderClosingReportService::class)->build(today(), false, null);

    // Hoje: revenue 160 (inclui 'pending' 80 e 'awaiting_payment' 30).
    expect($report['geral']['revenue'])->toBe(50.0);
});

test('relatório de pedidos: faturamento e ticket médio ignoram não pagos e cancelados', function () {
    ['admin' => $admin] = flowAuditClosingScenario();

    $component = Livewire::actingAs($admin)->test(Report::class);

    expect((float) $component->viewData('totalRevenue'))->toBe(50.0);
    expect((float) $component->viewData('averageTicket'))->toBe(50.0);
});

test('PDF de fechamento: venda PDV com pagamento dividido entra em alguma forma de pagamento', function () {
    $ctx = flowAuditContext(stock: null);

    foreach ([['pix', 'delivery', 50.0], ['split', 'pdv', 20.0]] as [$method, $type, $total]) {
        Order::withoutGlobalScopes()->create([
            'company_id' => $ctx['company']->id,
            'branch_id' => $ctx['branch']->id,
            'customer_id' => $ctx['customer']->id,
            'subtotal' => $total,
            'total' => $total,
            'status' => 'paid',
            'payment_method' => $method,
            'order_type' => $type,
        ]);
    }

    $report = app(OrderClosingReportService::class)->build(today(), false, null);

    expect($report['geral']['revenue'])->toBe(70.0);
    // Hoje: Dinheiro 0 + PIX 50 + Cartão 0 = 50 ≠ TOTAL 70 (o split some do detalhamento).
    expect(array_sum($report['geral']['payments']))->toBe(70.0);
});
