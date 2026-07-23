<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('super admin visualiza detalhes da empresa com metricas', function () {
    $company = Company::create([
        'name' => 'Empresa Detalhes',
        'slug' => 'empresa-detalhes-'.uniqid(),
        'order_prefix' => 'DET',
        'active' => true,
        'plan' => 'free',
        'status' => 'ACTIVE',
    ]);

    $manager = User::factory()->create();
    $company->users()->attach($manager->id, ['role' => 'company_admin']);

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
        'subtotal' => 50,
        'delivery_fee' => 0,
        'total' => 50,
        'status' => 'delivered',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'fee' => 0.5,
        'net_value' => 49.5,
    ]);

    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($superAdmin)
        ->get(route('superadmin.companies.show', $company))
        ->assertOk()
        ->assertSee('Empresa Detalhes')
        ->assertSee('R$ 50,00')
        ->assertSee($manager->email);
});

test('taxa da plataforma soma margem PIX Vindi alem da taxa do plano', function () {
    config(['payments.vindi_pix_platform_rate' => 0.0014]);

    $company = Company::create([
        'name' => 'Empresa PIX',
        'slug' => 'empresa-pix-'.uniqid(),
        'order_prefix' => 'PIX',
        'active' => true,
        'plan' => 'free',
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

    $order = Order::create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 100,
        'delivery_fee' => 0,
        'total' => 100,
        'status' => 'delivered',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'fee' => 1,
        'net_value' => 99,
    ]);

    Payment::create([
        'order_id' => $order->id,
        'payment_gateway' => 'vindi',
        'amount' => 100,
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    // Taxa do plano (R$1,00) + margem PIX Vindi (100 * 0,14% = R$0,14) = R$1,14.
    $this->actingAs($superAdmin)
        ->get(route('superadmin.companies.show', $company))
        ->assertOk()
        ->assertSee('R$ 1,14')
        ->assertSee('R$ 0,14');
});
