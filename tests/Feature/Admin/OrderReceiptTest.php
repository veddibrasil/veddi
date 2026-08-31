<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function receiptOrder(array $attributes = []): Order
{
    $company = Company::create([
        'name' => 'Empresa Cupom',
        'slug' => 'empresa-cupom-'.uniqid(),
        'order_prefix' => 'CUP',
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Cupom',
        'address' => 'Rua C, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Cupom',
        'phone' => '11999990004',
    ]);

    $order = Order::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 20.00,
        'total' => 20.00,
        'net_value' => 20.00,
        'status' => 'paid',
        'notes' => '',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
    ], $attributes));

    $order->load(['items', 'customer', 'branch', 'payment', 'coupon']);

    return $order;
}

test('cupom mostra "BALCAO / PDV" pra venda de balcão', function () {
    $order = receiptOrder(['delivery_type' => 'balcao']);

    $html = view('livewire.admin.orders.receipt', ['order' => $order, 'company' => $order->company])->render();

    expect($html)->toContain('BALCAO / PDV')
        ->not->toContain('RETIRADA NO LOCAL');
});

test('cupom mostra "RETIRADA NO LOCAL" pra pedido de retirada do PDV', function () {
    $order = receiptOrder(['delivery_type' => 'retirar']);

    $html = view('livewire.admin.orders.receipt', ['order' => $order, 'company' => $order->company])->render();

    expect($html)->toContain('RETIRADA NO LOCAL')
        ->not->toContain('BALCAO / PDV');
});

test('cupom mostra "ENTREGA" pra pedido de entrega do PDV', function () {
    $order = receiptOrder(['delivery_type' => 'entrega', 'delivery_address_id' => null]);

    $address = \App\Models\Address::create([
        'line1' => 'Rua das Flores',
        'number' => '10',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'cep' => '01000-000',
    ]);
    $order->update(['delivery_address_id' => $address->id]);

    $html = view('livewire.admin.orders.receipt', ['order' => $order->fresh(['items', 'customer', 'branch', 'payment', 'coupon']), 'company' => $order->company])->render();

    expect($html)->toContain('ENTREGA')
        ->not->toContain('BALCAO / PDV')
        ->not->toContain('RETIRADA NO LOCAL');
});
