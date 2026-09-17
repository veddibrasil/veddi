<?php

use App\Livewire\Chat\OrderChat;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * `resumePendingOrder()` é chamado a partir de `pendingOrderSummary`, uma
 * propriedade pública Livewire adulterável pelo client via payload AJAX —
 * por isso o teste chama o método diretamente no componente (sem passar pelo
 * harness de teste do Livewire, que exige contexto de rota que o mount() do
 * OrderChat não expõe via parâmetro tipado) para reproduzir exatamente esse
 * cenário: o valor de `pendingOrderSummary.id` chega vindo do client.
 */
function resumeOrderContext(): array
{
    $company = Company::create([
        'name' => 'Empresa Resume',
        'slug' => 'empresa-resume-'.uniqid(),
        'order_prefix' => 'RES',
        'active' => true,
        'email' => 'contato@empresa.com',
    ]);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customerA = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente A',
        'phone' => '11999999999',
    ]);

    $customerB = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente B',
        'phone' => '11988888888',
    ]);

    return compact('company', 'branch', 'customerA', 'customerB');
}

test('cliente anônimo não recupera pedido/dados de pagamento de outro cliente via pendingOrderSummary adulterado', function () {
    ['company' => $company, 'branch' => $branch, 'customerA' => $customerA, 'customerB' => $customerB] = resumeOrderContext();

    $foreignOrder = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customerB->id,
        'status' => 'awaiting_payment',
        'subtotal' => 20,
        'total' => 20,
        'order_number' => 'RES-0001',
        'payment_method' => 'pix',
    ]);

    \App\Models\Payment::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'order_id' => $foreignOrder->id,
        'amount' => 20,
        'status' => 'pending',
        'gateway' => 'asaas',
        'pix_qr_code' => 'QR-SECRETO-DE-OUTRO-CLIENTE',
        'pix_copy_paste' => 'COPIA-COLA-SECRETO',
        'asaas_payment_id' => 'pay_secreto123',
    ]);

    $component = new OrderChat;
    $component->customerId = $customerA->id;
    $component->companyId = $company->id;
    $component->pendingOrderSummary = ['id' => $foreignOrder->id];

    $component->resumePendingOrder();

    expect($component->orderId)->toBeNull();
    expect($component->pixQrCode)->toBeNull();
    expect($component->pixCopyPaste)->toBeNull();
    expect($component->paymentId)->toBeNull();
    expect($component->pendingOrderSummary)->toBeNull();
});

test('cliente recupera normalmente o próprio pedido pendente', function () {
    ['company' => $company, 'branch' => $branch, 'customerA' => $customerA] = resumeOrderContext();

    $ownOrder = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customerA->id,
        'status' => 'awaiting_payment',
        'subtotal' => 30,
        'total' => 30,
        'order_number' => 'RES-0002',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
    ]);

    \App\Models\Payment::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'order_id' => $ownOrder->id,
        'amount' => 30,
        'status' => 'pending',
        'gateway' => 'asaas',
        'pix_qr_code' => 'QR-DO-PROPRIO-CLIENTE',
        'pix_copy_paste' => 'COPIA-COLA-PROPRIO',
        'asaas_payment_id' => 'pay_proprio123',
    ]);

    $component = new OrderChat;
    $component->customerId = $customerA->id;
    $component->companyId = $company->id;
    $component->pendingOrderSummary = ['id' => $ownOrder->id];

    $component->resumePendingOrder();

    expect($component->orderId)->toBe($ownOrder->id);
    expect($component->pixQrCode)->toBe('QR-DO-PROPRIO-CLIENTE');
});
