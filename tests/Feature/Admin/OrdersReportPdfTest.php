<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function reportPdfContext(): array
{
    $company = Company::create([
        'name' => 'Empresa Relatório',
        'slug' => 'empresa-relatorio-'.uniqid(),
        'order_prefix' => 'REL',
        'active' => true,
        'plan' => 'pro',
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente',
        'phone' => '11999999999',
    ]);

    Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'status' => 'delivered',
        'subtotal' => 10,
        'total' => 10,
        'order_number' => 'REL-0001',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    return compact('company', 'admin');
}

test('exportar PDF de pedidos exige data inicial e final', function () {
    ['admin' => $admin] = reportPdfContext();

    $this->actingAs($admin)
        ->get(route('admin.orders.report.pdf'))
        ->assertInvalid(['date_start', 'date_end']);
});

test('exportar PDF de pedidos rejeita intervalo maior que 92 dias', function () {
    ['admin' => $admin] = reportPdfContext();

    $this->actingAs($admin)
        ->get(route('admin.orders.report.pdf', [
            'date_start' => now()->subDays(200)->format('Y-m-d'),
            'date_end' => now()->format('Y-m-d'),
        ]))
        ->assertStatus(422);
});

test('exportar PDF de pedidos funciona normalmente com intervalo válido', function () {
    ['admin' => $admin] = reportPdfContext();

    $this->actingAs($admin)
        ->get(route('admin.orders.report.pdf', [
            'date_start' => now()->startOfMonth()->format('Y-m-d'),
            'date_end' => now()->endOfMonth()->format('Y-m-d'),
        ]))
        ->assertStatus(200)
        ->assertHeader('content-type', 'application/pdf');
});
