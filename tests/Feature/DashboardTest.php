<?php

use App\Livewire\Admin\Dashboard;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('admin.dashboard'));
    $response->assertRedirect(route('login'));
});

test('caixa não vê receita nem valor dos pedidos na dashboard', function () {
    $company = Company::create([
        'name' => 'Dashboard Teste',
        'slug' => 'dashboard-'.uniqid(),
        'order_prefix' => 'DSH',
        'active' => true,
        'plan' => 'pro',
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Balcão',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $caixa = User::factory()->create();
    $caixa->companies()->attach($company->id, ['role' => 'caixa', 'branch_id' => $branch->id]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Balcão',
        'phone' => 'dashboard-guest',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 42.00,
        'total' => 42.00,
        'fee' => 0,
        'net_value' => 42.00,
        'status' => 'paid',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
    ]);

    $this->actingAs($caixa);

    Livewire::test(Dashboard::class)
        ->assertDontSee('Receita hoje')
        ->assertDontSee('R$ 42,00')
        ->assertSee($order->order_number);
});
