<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function closingReportContext(): array
{
    $company = Company::create([
        'name' => 'Lancheria Fechamento',
        'slug' => 'lancheria-fechamento',
        'order_prefix' => 'LF',
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Central',
        'address' => 'Rua B, 10',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Maria',
        'phone' => '11999990002',
        'email' => 'maria@teste.com',
        'address' => 'Rua das Flores',
        'number' => '123',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'cep' => '01310-100',
    ]);

    $admin = User::factory()->create(['is_super_admin' => true]);

    Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 30.00,
        'delivery_fee' => 5.00,
        'discount' => 0,
        'total' => 35.00,
        'fee' => 0,
        'net_value' => 35.00,
        'status' => 'delivered',
        'notes' => '',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
    ]);

    Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 20.00,
        'delivery_fee' => 0,
        'discount' => 0,
        'total' => 20.00,
        'fee' => 0,
        'net_value' => 20.00,
        'status' => 'paid',
        'notes' => '',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
    ]);

    Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 10.00,
        'delivery_fee' => 0,
        'discount' => 0,
        'total' => 10.00,
        'fee' => 0,
        'net_value' => 10.00,
        'status' => 'cancelled',
        'notes' => '',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
    ]);

    return compact('company', 'admin');
}

test('fechamento de pedidos em pdf responde ok pra admin da empresa', function () {
    ['admin' => $admin] = closingReportContext();

    $this->actingAs($admin);

    $response = $this->get(route('admin.orders.closing.pdf'));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
});

test('usuário sem permissão de orders.view não acessa o fechamento em pdf', function () {
    closingReportContext();

    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('admin.orders.closing.pdf'))->assertForbidden();
});
