<?php

/*
 * Auditoria 15/10/2026 — extensão do F-02 (escopo de filial ignorado fora de Orders/Index).
 * Papéis presos à filial (branch_manager, cozinha, caixa, bar, entrega) só deveriam ver/agir
 * na própria filial. Index filtra por branch_id; relatórios, PDFs, estoque e a busca do
 * próprio Index não. Os testes afirmam o comportamento seguro e devem FALHAR hoje.
 */

use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Orders\Report as OrdersReport;
use App\Livewire\Admin\Stock\Index as StockIndex;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/TenantAuditHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->company = atnCompany('Fil');
    app()->instance('current.company', $this->company);
    $this->centro = atnBranch($this->company, 'Centro');
    $this->bairro = atnBranch($this->company, 'Bairro');
    $this->managerCentro = atnUser($this->company, 'branch_manager', $this->centro);
    $this->bairroOrder = atnOrder($this->company, $this->bairro, atnCustomer($this->company, 'Cliente Do Bairro'), [
        'subtotal' => 7777.77, 'total' => 7777.77,
    ]);
});

test('Orders/Report: gerente da filial Centro não vê pedidos/receita da filial Bairro', function () {
    $html = Livewire::actingAs($this->managerCentro)->test(OrdersReport::class)->html();

    expect($html)
        ->not->toContain($this->bairroOrder->order_number)
        ->not->toContain('7.777,77');
});

test('Orders/Index: busca por nome de cliente não fura o filtro de filial do gerente', function () {
    $html = Livewire::actingAs($this->managerCentro)
        ->test(OrdersIndex::class)
        ->set('search', 'Cliente Do Bairro')
        ->html();

    expect($html)->not->toContain($this->bairroOrder->order_number);
});

test('Stock: gerente da filial Centro não ajusta estoque da filial Bairro', function () {
    $product = atnProduct($this->company, atnCategory($this->company), 'Coxinha', $this->bairro, 5);

    atnAttempt(fn () => Livewire::actingAs($this->managerCentro)
        ->test(StockIndex::class)
        // Ids setados direto (como faria o navegador): applyAdjustment precisa rechecar a filial.
        ->set('adjustingProductId', $product->id)
        ->set('adjustingBranchId', $this->bairro->id)
        ->set('adjustMode', 'set')
        ->set('adjustQuantity', '0')
        ->set('adjustNotes', 'zerando estoque da outra filial')
        ->call('applyAdjustment'));

    $qty = DB::table('branch_product')->where('branch_id', $this->bairro->id)->where('product_id', $product->id)->value('quantity');

    expect((int) $qty)->toBe(5);
});

test('ReportPdfController: cozinha (sem orders.report) não baixa o relatório de pedidos da empresa', function () {
    $cook = atnUser($this->company, 'cozinha', $this->centro);

    $this->actingAs($cook)
        ->get(route('admin.orders.report.pdf', ['date_start' => now()->startOfMonth()->toDateString(), 'date_end' => now()->toDateString()]))
        ->assertForbidden();
});

test('ReceiptPdfController: caixa da filial Centro não baixa o cupom de pedido da filial Bairro', function () {
    $cashier = atnUser($this->company, 'caixa', $this->centro);

    $this->actingAs($cashier)
        ->get(route('admin.orders.receipt', ['order' => $this->bairroOrder->id]))
        ->assertForbidden();
});
