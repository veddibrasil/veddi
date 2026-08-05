<?php

use App\Contracts\PrinterServiceInterface;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function escposTestOrder(): Order
{
    $company = Company::create([
        'name' => 'Empresa ESC/POS',
        'slug' => 'escpos-'.uniqid(),
        'order_prefix' => 'ESC',
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Teste',
        'phone' => '11999990001',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 20.00,
        'delivery_fee' => 0,
        'total' => 20.00,
        'fee' => 0,
        'net_value' => 20.00,
        'status' => 'paid',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
    ]);

    $cozinhaCategory = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Salgados',
        'station' => 'cozinha',
    ]);

    $barCategory = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Bebidas',
        'station' => 'bar',
    ]);

    $coxinha = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $cozinhaCategory->id,
        'name' => 'Coxinha',
        'price' => 10.00,
        'active' => true,
    ]);

    $suco = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $barCategory->id,
        'name' => 'Suco de Laranja',
        'price' => 10.00,
        'active' => true,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $coxinha->id,
        'product_name' => 'Coxinha',
        'unit_price' => 10.00,
        'quantity' => 2,
        'subtotal' => 20.00,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $suco->id,
        'product_name' => 'Suco de Laranja',
        'unit_price' => 10.00,
        'quantity' => 1,
        'subtotal' => 10.00,
    ]);

    return $order->load('items.product.category', 'customer', 'branch');
}

test('buildOrderReceipt do cupom geral inclui todos os itens e o total', function () {
    $order = escposTestOrder();

    $bytes = app(PrinterServiceInterface::class)->buildOrderReceipt($order, 'geral');

    expect($bytes)->toContain('Coxinha')
        ->toContain('Suco de Laranja')
        ->toContain('TOTAL');
});

test('buildOrderReceipt da estação cozinha só inclui itens daquela estação', function () {
    $order = escposTestOrder();

    $bytes = app(PrinterServiceInterface::class)->buildOrderReceipt($order, 'cozinha');

    expect($bytes)->toContain('Coxinha')
        ->not->toContain('Suco de Laranja');
});

test('buildOrderReceipt da estação bar só inclui itens daquela estação', function () {
    $order = escposTestOrder();

    $bytes = app(PrinterServiceInterface::class)->buildOrderReceipt($order, 'bar');

    expect($bytes)->toContain('Suco de Laranja')
        ->not->toContain('Coxinha');
});

test('buildOrderReceipt com full=true ignora o filtro por estação e imprime todos os itens', function () {
    $order = escposTestOrder();

    $bytes = app(PrinterServiceInterface::class)->buildOrderReceipt($order, 'cozinha', full: true);

    expect($bytes)->toContain('Coxinha')
        ->toContain('Suco de Laranja');
});

test('buildOrderReceipt sempre termina com o comando de corte de papel ESC/POS', function () {
    $order = escposTestOrder();

    $bytes = app(PrinterServiceInterface::class)->buildOrderReceipt($order, 'geral');

    // GS V (corte) = 0x1D 0x56 conforme especificação ESC/POS.
    expect($bytes)->toContain(chr(0x1D).chr(0x56));
});

test('buildFiscalNoteReceipt monta o DANFE completo com itens, totais e chave de acesso', function () {
    $order = escposTestOrder();

    $note = FiscalNote::create([
        'company_id' => $order->company_id,
        'order_id' => $order->id,
        'status' => 'authorized',
        'access_key' => str_repeat('1', 44),
        'data' => ['customer_cpf_cnpj' => '11144477735'],
    ]);

    $bytes = app(PrinterServiceInterface::class)->buildFiscalNoteReceipt($note, $order);

    expect($bytes)->toContain('DANFE NFC-e')
        ->toContain('Coxinha')
        ->toContain('Suco de Laranja')
        ->toContain('TOTAL')
        ->toContain('11144477735')
        ->toContain(chunk_split(str_repeat('1', 44), 4, ' '));
});

test('buildFiscalNoteReceipt indica consumidor nao identificado quando nao ha documento', function () {
    $order = escposTestOrder();

    $note = FiscalNote::create([
        'company_id' => $order->company_id,
        'order_id' => $order->id,
        'status' => 'authorized',
        'access_key' => str_repeat('1', 44),
    ]);

    $bytes = app(PrinterServiceInterface::class)->buildFiscalNoteReceipt($note, $order);

    expect($bytes)->toContain('CONSUMIDOR NAO IDENTIFICADO');
});
