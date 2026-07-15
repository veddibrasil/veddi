<?php

use App\Events\OrderStatusUpdated;
use App\Jobs\IssueFiscalNote;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\Customer;
use App\Models\FiscalNote;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function autoIssueTestContext(bool $planEnabled = true, bool $configEnabled = true, string $orderStatus = 'paid'): array
{
    $company = Company::create([
        'name' => 'Empresa Fiscal Auto',
        'slug' => 'empresa-fiscal-auto-'.uniqid(),
        'order_prefix' => 'AUT',
        'active' => true,
        'status' => 'ACTIVE',
        'owner_cpf_cnpj' => '12345678000100',
        'fiscal_notes_enabled' => $planEnabled,
    ]);

    CompanyFiscalConfig::create([
        'company_id' => $company->id,
        'enabled' => $configEnabled,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua A',
        'number' => '100',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'state' => 'SP',
        'cep' => '01310000',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente',
        'phone' => '11999990001',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 30.00,
        'delivery_fee' => 0,
        'total' => 30.00,
        'fee' => 0,
        'net_value' => 30.00,
        'status' => $orderStatus,
        'payment_method' => 'PIX',
        'order_type' => 'delivery',
    ]);

    return compact('company', 'order');
}

test('pedido pago com plano e config habilitados dispara emissão automática', function () {
    Bus::fake();
    ['order' => $order] = autoIssueTestContext();

    event(new OrderStatusUpdated($order->fresh()));

    Bus::assertDispatched(IssueFiscalNote::class, fn ($job) => $job->orderId === $order->id);
});

test('pedido pago sem módulo fiscal no plano não dispara emissão', function () {
    Bus::fake();
    ['order' => $order] = autoIssueTestContext(planEnabled: false);

    event(new OrderStatusUpdated($order->fresh()));

    Bus::assertNotDispatched(IssueFiscalNote::class);
});

test('pedido pago com config fiscal desabilitada não dispara emissão', function () {
    Bus::fake();
    ['order' => $order] = autoIssueTestContext(configEnabled: false);

    event(new OrderStatusUpdated($order->fresh()));

    Bus::assertNotDispatched(IssueFiscalNote::class);
});

test('pedido que já possui nota fiscal não dispara emissão duplicada', function () {
    Bus::fake();
    ['order' => $order, 'company' => $company] = autoIssueTestContext();

    FiscalNote::create([
        'company_id' => $company->id,
        'order_id' => $order->id,
        'status' => 'authorized',
    ]);

    event(new OrderStatusUpdated($order->fresh()));

    Bus::assertNotDispatched(IssueFiscalNote::class);
});

test('mudança de status diferente de paid não dispara emissão', function () {
    Bus::fake();
    ['order' => $order] = autoIssueTestContext(orderStatus: 'preparing');

    event(new OrderStatusUpdated($order->fresh()));

    Bus::assertNotDispatched(IssueFiscalNote::class);
});
