<?php

use App\Livewire\SuperAdmin\Companies\Index;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SuperAdmin\CompanyCommercialInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 29)->setTime(12, 0));
    config([
        'plans.essencial.monthly_price' => 59,
        'plans.essencial.setup_fee' => 99,
        'plans.pro.monthly_price' => 119,
        'pdv.addon_monthly_price' => 99,
        'fiscal.addon_monthly_price' => 149,
        'waiter.addon_monthly_price' => 99,
    ]);
});

function commercialCompany(array $attributes = []): Company
{
    return Company::create(array_merge([
        'name' => 'Empresa Comercial',
        'slug' => 'comercial-'.uniqid(),
        'order_prefix' => 'COM',
        'active' => true,
        'plan' => 'free',
        'status' => 'ACTIVE',
    ], $attributes));
}

function commercialOrder(Company $company, array $attributes = []): Order
{
    $branch = Branch::withoutGlobalScopes()->firstOrCreate(['company_id' => $company->id], ['name' => 'Matriz', 'active' => true]);
    $customer = Customer::withoutGlobalScopes()->firstOrCreate(['company_id' => $company->id], ['name' => 'Cliente', 'phone' => '11999999999']);

    $createdAt = $attributes['created_at'] ?? now();
    unset($attributes['created_at']);

    $order = Order::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 100,
        'total' => 100,
        'status' => 'delivered',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'fee' => 1,
    ], $attributes));

    $order->forceFill(['created_at' => $createdAt])->save();

    return $order;
}

test('oportunidade usa taxas reais e exclui cancelados e abandonados', function () {
    $company = commercialCompany();
    commercialOrder($company, ['fee' => 60]);
    commercialOrder($company, ['status' => 'pending', 'fee' => 500]);
    commercialOrder($company, ['status' => 'cancelled', 'fee' => 500]);
    commercialOrder($company, ['status' => 'scheduled', 'fee' => 0]);

    $insights = app(CompanyCommercialInsights::class)->forCompany($company);

    expect($insights['usage']['month_orders'])->toBe(2)
        ->and($insights['usage']['month_fees'])->toBe(60.0)
        ->and($insights['opportunities'][0]['title'])->toBe('Oferecer plano Essencial')
        ->and($insights['opportunities'][0]['priority'])->toBe('Alta')
        ->and($insights['opportunities'][0]['monthlyIncrease'])->toBe(59.0)
        ->and($insights['opportunities'][0]['message'])->toContain('R$ 59,00/mês', 'R$ 99,00', '2 pedidos confirmados');
});

test('uso proximo da faixa mensal aumenta a prioridade do plano', function () {
    config(['plans.free.max_orders_per_month' => 5]);
    $company = commercialCompany();
    foreach (range(1, 4) as $index) {
        commercialOrder($company);
    }

    $insights = app(CompanyCommercialInsights::class)->forCompany($company);

    expect($insights['opportunities'][0]['priority'])->toBe('Alta')
        ->and($insights['opportunities'][0]['reason'])->toContain('4 pedidos', '5 pedidos');
});

test('pendencias e falta de uso priorizam acompanhamento em vez de venda', function (array $attributes, bool $hasOrder, string $title) {
    $company = commercialCompany($attributes);
    if ($hasOrder) {
        commercialOrder($company);
    }

    $insights = app(CompanyCommercialInsights::class)->forCompany($company);

    expect($insights['opportunities'])->toHaveCount(1)
        ->and($insights['opportunities'][0]['title'])->toBe($title)
        ->and($insights['opportunities'][0]['monthlyIncrease'])->toBeNull();
})->with([
    'inativa' => [['active' => false], true, 'Retomar a operação'],
    'inadimplente' => [['status' => 'OVERDUE'], true, 'Retomar a operação'],
    'mudanca pendente' => [['pending_plan' => 'pro'], true, 'Concluir mudança de plano'],
    'sem uso' => [[], false, 'Acompanhar ativação e uso'],
]);

test('modulos respeitam contratacao dependencia do PDV e precos configurados', function () {
    config(['waiter.addon_monthly_price' => 135]);
    $company = commercialCompany(['plan' => 'pro', 'pdv_module_enabled' => true, 'fiscal_notes_enabled' => true]);
    $order = commercialOrder($company, ['order_type' => 'pdv']);
    RestaurantTable::create(['company_id' => $company->id, 'branch_id' => $order->branch_id, 'number' => '1', 'active' => true]);

    $insights = app(CompanyCommercialInsights::class)->forCompany($company);

    expect($insights['monthlyTotal'])->toBe(367.0)
        ->and($insights['opportunities'])->toHaveCount(1)
        ->and($insights['opportunities'][0]['title'])->toBe('Apresentar Módulo Garçom')
        ->and($insights['opportunities'][0]['priority'])->toBe('Alta')
        ->and($insights['opportunities'][0]['monthlyIncrease'])->toBe(135.0);

    $company->update(['pdv_module_enabled' => false]);
    $insights = app(CompanyCommercialInsights::class)->forCompany($company);
    expect(array_column($insights['opportunities'], 'title'))->toBe(['Apresentar Módulo PDV']);
});

test('comparacao de periodos e cache mantem os dados isolados mesmo com outro tenant no contexto', function () {
    $company = commercialCompany(['plan' => 'pro']);
    commercialOrder($company, ['created_at' => now()->subDays(45)]);
    commercialOrder($company, ['created_at' => now()->subDays(2)]);
    commercialOrder($company, ['created_at' => now()->subDays(1)]);

    $other = commercialCompany(['name' => 'Outra empresa']);
    commercialOrder($other, ['fee' => 999]);
    app()->instance('current.company', $other);

    $insights = app(CompanyCommercialInsights::class)->forCompany($company);
    expect($insights['usage']['recent_orders'])->toBe(2)
        ->and($insights['usage']['previous_orders'])->toBe(1)
        ->and($insights['usage']['month_fees'])->toBe(2.0)
        ->and($insights['usage']['branches'])->toBe(1)
        ->and($insights['growth'])->toBe(100.0)
        ->and($insights['opportunities'][0]['title'])->toBe('Conversar sobre o crescimento');

    app()->forgetInstance('current.company');
    commercialOrder($company);
    expect(app(CompanyCommercialInsights::class)->forCompany($company)['usage']['recent_orders'])->toBe(2);
    $this->travel(6)->minutes();
    expect(app(CompanyCommercialInsights::class)->forCompany($company)['usage']['recent_orders'])->toBe(3);
});

test('detalhes mostram contatos comerciais assinatura atual e mensagens sem acionar envio', function () {
    $company = commercialCompany(['email' => 'empresa@example.com', 'asaas_subscription_id' => 'sub_atual']);
    commercialOrder($company);
    $manager = User::factory()->create();
    $company->users()->attach($manager, ['role' => 'company_admin']);
    Subscription::create(['company_id' => $company->id, 'asaas_subscription_id' => 'sub_atual', 'plan' => 'free', 'status' => 'active', 'amount' => 123, 'billing_cycle' => 'MONTHLY']);
    Subscription::create(['company_id' => $company->id, 'asaas_subscription_id' => 'sub_antiga', 'plan' => 'pro', 'status' => 'cancelled', 'amount' => 999, 'billing_cycle' => 'MONTHLY']);

    $this->actingAs(User::factory()->create(['is_super_admin' => true]))
        ->get(route('superadmin.companies.show', $company))
        ->assertOk()
        ->assertSee('Oportunidades comerciais')
        ->assertSee('Oferecer plano Essencial')
        ->assertSee('Copiar mensagem')
        ->assertSee('empresa@example.com')
        ->assertSee($manager->email)
        ->assertSee('R$ 123,00')
        ->assertDontSee('R$ 999,00');
});

test('listagem filtra plano e modulo ausente e reinicia a paginacao', function () {
    $target = commercialCompany(['name' => 'Alvo comercial', 'plan' => 'pro', 'pdv_module_enabled' => true]);
    commercialCompany(['name' => 'Fiscal contratado', 'plan' => 'pro', 'fiscal_notes_enabled' => true]);
    commercialCompany(['name' => 'Plano gratuito']);

    Livewire::actingAs(User::factory()->create(['is_super_admin' => true]))
        ->test(Index::class)
        ->set('planFilter', 'pro')
        ->set('missingModule', 'fiscal')
        ->assertSee($target->name)
        ->assertSee('R$ 218,00')
        ->assertDontSee('Fiscal contratado')
        ->assertDontSee('Plano gratuito')
        ->call('setPage', 2)
        ->set('search', 'Alvo')
        ->assertSet('paginators.page', 1)
        ->assertSee($target->name);
});

test('usuario comum nao acessa oportunidades do superadmin', function () {
    $company = commercialCompany();
    $this->actingAs(User::factory()->create())
        ->get(route('superadmin.companies.show', $company))->assertForbidden();
});
