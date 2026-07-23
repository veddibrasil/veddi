<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('super admin visualiza dashboard com metricas da plataforma', function () {
    $company = Company::create([
        'name' => 'Empresa Dashboard',
        'slug' => 'empresa-dashboard-'.uniqid(),
        'order_prefix' => 'DSH',
        'active' => true,
        'plan' => 'pro',
        'status' => 'ACTIVE',
    ]);

    $customer = Customer::create([
        'company_id' => $company->id,
        'name' => 'Cliente Teste',
        'phone' => '11999999999',
    ]);

    $branch = Branch::create([
        'company_id' => $company->id,
        'name' => 'Filial Teste',
        'active' => true,
    ]);

    Order::create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 100,
        'delivery_fee' => 0,
        'total' => 100,
        'status' => 'delivered',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'fee' => 0,
        'net_value' => 100,
    ]);

    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($superAdmin)
        ->get(route('superadmin.dashboard'))
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Empresa Dashboard')
        ->assertSee('R$ 100,00');
});
