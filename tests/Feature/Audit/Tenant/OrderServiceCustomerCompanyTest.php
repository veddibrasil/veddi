<?php

/*
 * Auditoria 16/10/2026 — OrderService::createOrder() buscava o cliente com
 * withoutGlobalScopes()->find() sem conferir a empresa: um id forjado (PDV) vinculava o
 * pedido da empresa A ao cliente da empresa B. Agora o cliente precisa ser da empresa
 * da filial do pedido.
 */

use App\Models\Customer;
use App\Models\Order;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/TenantAuditHelpers.php';

uses(RefreshDatabase::class);

test('createOrder recusa cliente de outra empresa e aceita o da própria empresa', function () {
    $companyB = atnCompany('OsB');
    $customerB = atnCustomer($companyB, 'Cliente B');

    ['company' => $companyA, 'branch' => $branchA, 'product' => $productA] = pdvContext(); // current.company = A
    $customerA = Customer::withoutGlobalScopes()->create([
        'company_id' => $companyA->id,
        'name' => 'Cliente A',
        'phone' => '11988887777',
    ]);

    $create = fn (int $customerId) => app(OrderService::class)->createOrder(
        customerId: $customerId,
        branchId: $branchA->id,
        cart: [(string) $productA->id => ['product_id' => $productA->id, 'qty' => 1]],
        notes: '',
        paymentMethod: 'cash',
        orderType: 'pdv',
    );

    expect(fn () => $create($customerB->id))
        ->toThrow(RuntimeException::class, 'Cliente não encontrado nesta empresa.');

    expect(Order::withoutGlobalScopes()->where('customer_id', $customerB->id)->exists())->toBeFalse();

    $order = $create($customerA->id);

    expect($order->customer_id)->toBe($customerA->id)
        ->and($order->company_id)->toBe($companyA->id);
});
