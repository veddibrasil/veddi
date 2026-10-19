<?php

/*
 * Auditoria 15/10/2026 — PDV Terminal: $customerId é público (sem #[Locked]).
 * selectCustomer() valida a empresa, mas o cliente pode mandar customerId direto no
 * payload; resolveCustomerId() devolve o valor sem checar e OrderService::createOrder()
 * busca o Customer com withoutGlobalScopes() sem validar company_id. O pedido da
 * empresa A fica vinculado a um cliente da empresa B (nome/telefone/endereço de B
 * passam a aparecer no pedido e no cupom de A). O teste deve FALHAR hoje.
 */

use App\Livewire\Admin\Pdv\Terminal;
use App\Models\Order;
use App\Models\PdvCashSession;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/TenantAuditHelpers.php';

uses(RefreshDatabase::class);

test('PDV: operador da empresa A não vincula venda a cliente da empresa B forjando customerId', function () {
    $this->seed(PermissionSeeder::class);

    $companyB = atnCompany('PdvB');
    $customerB = atnCustomer($companyB, 'Cliente Sigiloso B');

    ['company' => $companyA, 'branch' => $branchA, 'product' => $productA] = pdvContext(); // current.company = A
    $operator = atnUser($companyA, 'company_admin');
    PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $companyA->id, 'branch_id' => $branchA->id, 'user_id' => $operator->id, 'opening_amount' => 0,
    ]);

    atnAttempt(fn () => Livewire::actingAs($operator)
        ->test(Terminal::class)
        ->call('addProduct', $productA->id)
        ->set('customerId', $customerB->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('processOrder'));

    expect(Order::withoutGlobalScopes()
        ->where('company_id', $companyA->id)
        ->where('customer_id', $customerB->id)
        ->exists())->toBeFalse();
});
