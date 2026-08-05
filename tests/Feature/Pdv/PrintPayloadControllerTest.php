<?php

use App\Models\Branch;
use App\Models\BranchPrinter;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function printPayloadContext(): array
{
    $company = Company::create([
        'name' => 'Print Payload Teste',
        'slug' => 'print-payload-'.uniqid(),
        'order_prefix' => 'PPL',
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Balcão',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
    ]);

    $admin = User::factory()->create(['is_super_admin' => true]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Balcão',
        'phone' => 'pdv-guest',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 10.00,
        'total' => 10.00,
        'fee' => 0,
        'net_value' => 10.00,
        'status' => 'paid',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
    ]);

    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Salgados',
    ]);

    $product = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Coxinha',
        'price' => 10.00,
        'active' => true,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => 'Coxinha',
        'unit_price' => 10.00,
        'quantity' => 1,
        'subtotal' => 10.00,
    ]);

    return compact('company', 'branch', 'admin', 'order');
}

test('cupom retorna 404 quando a filial não tem impressora pra estação', function () {
    ['admin' => $admin, 'order' => $order] = printPayloadContext();

    $this->actingAs($admin)
        ->get(route('admin.pdv.print.receipt', ['order' => $order, 'station' => 'geral']))
        ->assertNotFound();
});

test('cupom retorna ip/porta e payload em base64 quando a impressora existe e está ativa', function () {
    ['admin' => $admin, 'order' => $order, 'branch' => $branch, 'company' => $company] = printPayloadContext();

    BranchPrinter::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'station' => 'geral',
        'ip_address' => '192.168.0.50',
        'port' => 9100,
        'paper_width' => 80,
        'active' => true,
    ]);

    $response = $this->actingAs($admin)
        ->get(route('admin.pdv.print.receipt', ['order' => $order, 'station' => 'geral']))
        ->assertOk()
        ->json();

    expect($response['printer'])->toBe(['connection_type' => 'network', 'ip' => '192.168.0.50', 'port' => 9100, 'name' => null]);
    expect(base64_decode($response['payload']))->toContain('Coxinha');
});

test('cupom com ?full=1 ignora o filtro da estação e inclui o pedido inteiro', function () {
    ['admin' => $admin, 'order' => $order, 'branch' => $branch, 'company' => $company] = printPayloadContext();

    BranchPrinter::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'station' => 'cozinha',
        'ip_address' => '192.168.0.51',
        'port' => 9100,
        'paper_width' => 80,
        'active' => true,
    ]);

    $response = $this->actingAs($admin)
        ->get(route('admin.pdv.print.receipt', ['order' => $order, 'station' => 'cozinha']).'?full=1')
        ->assertOk()
        ->json();

    expect(base64_decode($response['payload']))->toContain('Coxinha');
});

test('cupom retorna nome da impressora (sem ip/porta) quando conexão é USB', function () {
    ['admin' => $admin, 'order' => $order, 'branch' => $branch, 'company' => $company] = printPayloadContext();

    BranchPrinter::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'station' => 'geral',
        'connection_type' => 'usb',
        'printer_name' => 'EPSON TM-T20',
        'paper_width' => 80,
        'active' => true,
    ]);

    $response = $this->actingAs($admin)
        ->get(route('admin.pdv.print.receipt', ['order' => $order, 'station' => 'geral']))
        ->assertOk()
        ->json();

    // port não é nullable no schema (default 9100) — fica sem uso pra USB, mas
    // sempre presente; connection_type é o que o front usa pra decidir o transporte.
    expect($response['printer'])->toBe(['connection_type' => 'usb', 'ip' => null, 'port' => 9100, 'name' => 'EPSON TM-T20']);
});

test('cupom retorna 404 pra estação inválida', function () {
    ['admin' => $admin, 'order' => $order] = printPayloadContext();

    $this->actingAs($admin)
        ->get(route('admin.pdv.print.receipt', ['order' => $order, 'station' => 'inexistente']))
        ->assertNotFound();
});

test('cupom retorna 404 quando a impressora existe mas está inativa', function () {
    ['admin' => $admin, 'order' => $order, 'branch' => $branch, 'company' => $company] = printPayloadContext();

    BranchPrinter::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'station' => 'geral',
        'ip_address' => '192.168.0.50',
        'port' => 9100,
        'paper_width' => 80,
        'active' => false,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.pdv.print.receipt', ['order' => $order, 'station' => 'geral']))
        ->assertNotFound();
});

test('nota fiscal retorna 404 quando não há nota autorizada', function () {
    ['admin' => $admin, 'order' => $order] = printPayloadContext();

    $this->actingAs($admin)
        ->get(route('admin.pdv.print.fiscal-note', ['order' => $order]))
        ->assertNotFound();
});

test('nota fiscal retorna 404 quando não há impressora marcada para nota fiscal', function () {
    ['admin' => $admin, 'order' => $order, 'company' => $company] = printPayloadContext();

    FiscalNote::create([
        'company_id' => $company->id,
        'order_id' => $order->id,
        'status' => 'authorized',
        'access_key' => str_repeat('1', 44),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.pdv.print.fiscal-note', ['order' => $order]))
        ->assertNotFound();
});

test('nota fiscal retorna payload quando autorizada e há impressora marcada', function () {
    ['admin' => $admin, 'order' => $order, 'branch' => $branch, 'company' => $company] = printPayloadContext();

    BranchPrinter::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'station' => 'geral',
        'ip_address' => '192.168.0.50',
        'port' => 9100,
        'paper_width' => 80,
        'print_fiscal_note' => true,
        'active' => true,
    ]);

    FiscalNote::create([
        'company_id' => $company->id,
        'order_id' => $order->id,
        'status' => 'authorized',
        'access_key' => str_repeat('1', 44),
    ]);

    $response = $this->actingAs($admin)
        ->get(route('admin.pdv.print.fiscal-note', ['order' => $order]))
        ->assertOk()
        ->json();

    expect($response['printer'])->toBe(['connection_type' => 'network', 'ip' => '192.168.0.50', 'port' => 9100, 'name' => null]);
    expect(base64_decode($response['payload']))->toContain('DANFE NFC-e')->toContain('Coxinha');
});

test('usuário sem permissão de pdv/pedidos não acessa o payload de impressão', function () {
    // branch_manager tem orders.view por padrão via role (ver CLAUDE.md), então pra
    // testar o 403 é preciso um usuário sem nenhum vínculo com a empresa — assim
    // hasPermission() cai no "sem role pra essa empresa" e nega de verdade.
    ['order' => $order] = printPayloadContext();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.pdv.print.receipt', ['order' => $order, 'station' => 'geral']))
        ->assertForbidden();
});
