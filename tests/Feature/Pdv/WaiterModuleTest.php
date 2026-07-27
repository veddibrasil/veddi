<?php

use App\Contracts\AsaasServiceInterface;
use App\Livewire\Admin\Pdv\Selector;
use App\Livewire\Admin\Pdv\TabTerminal;
use App\Livewire\Admin\Settings\BillingSettings;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function waiterBillingCreditCardFields($test)
{
    return $test
        ->set('cardNumber', '4111111111111111')
        ->set('cardExpiry', '12/30')
        ->set('cardCvv', '123')
        ->set('cardHolderName', 'FULANO DA SILVA')
        ->set('cardCpfCnpj', '12345678909')
        ->set('cardPostalCode', '01310000')
        ->set('cardAddressNumber', '100');
}

// ─── Gate de acesso ─────────────────────────────────────────────────────────

test('garçom sem módulo Garçom ativo toma 403 ao acessar mesas/comandas', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $company->update(['waiter_module_enabled' => false]);

    $waiter = makeWaiter($company, $branch);

    Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->assertForbidden();
});

test('garçom sem módulo Garçom ativo toma 403 no seletor do PDV', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $company->update(['waiter_module_enabled' => false]);

    $waiter = makeWaiter($company, $branch);

    Livewire::actingAs($waiter)
        ->test(Selector::class)
        ->assertForbidden();
});

test('garçom com módulo Garçom ativo acessa mesas/comandas normalmente', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $company->update(['waiter_module_enabled' => true]);

    $waiter = makeWaiter($company, $branch);

    Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->assertOk()
        ->assertSet('isWaiter', true);
});

test('caixa com pdv.operate acessa mesas/comandas mesmo sem o módulo Garçom', function () {
    ['company' => $company, 'admin' => $admin] = pdvContext();
    $company->update(['waiter_module_enabled' => false]);

    Livewire::actingAs($admin)
        ->test(TabTerminal::class)
        ->assertOk()
        ->assertSet('isWaiter', false);
});

// ─── Billing ────────────────────────────────────────────────────────────────

function waiterBillingTestCompany(): Company
{
    return Company::create([
        'name' => 'Empresa Billing Garçom',
        'slug' => 'empresa-billing-garcom-'.uniqid(),
        'order_prefix' => 'BLG',
        'active' => true,
        'status' => 'ACTIVE',
        'plan' => 'essencial',
        'asaas_customer_id' => 'cus_000000000002',
        'asaas_subscription_id' => 'sub_000000000002',
        'pdv_module_enabled' => true,
    ]);
}

test('ativação do módulo Garçom exige o módulo PDV ativo', function () {
    $company = waiterBillingTestCompany();
    $company->update(['pdv_module_enabled' => false]);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        $mock->shouldNotReceive('createCreditCardCharge');
    });

    Livewire::actingAs($admin)
        ->test(BillingSettings::class)
        ->call('activateWaiterModule');

    expect($company->fresh()->waiter_module_enabled)->toBeFalse();
});

test('ativação do módulo Garçom combina valor do plano + PDV + garçom em uma única assinatura', function () {
    $company = waiterBillingTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        $mock->shouldReceive('createCreditCardCharge')
            ->once()
            ->withArgs(fn ($customerId, $amount) => $amount === 59.0 + 99.0 + 99.0)
            ->andReturn(['status' => 'CONFIRMED']);
        $mock->shouldReceive('cancelSubscription')->once();
        $mock->shouldReceive('createSubscription')
            ->once()
            ->withArgs(fn ($customerId, $plan, $billingType, $creditCard, $holderInfo, $nextDueDate, $extraAmount, $extraDescription) => $extraAmount === 99.0 + 99.0 && $extraDescription === 'Módulo PDV + Módulo Garçom')
            ->andReturn(['id' => 'sub_new_waiter', 'status' => 'ACTIVE', 'value' => 59.0 + 99.0 + 99.0, 'nextDueDate' => now()->addMonth()->toDateString()]);
    });

    $test = Livewire::actingAs($admin)->test(BillingSettings::class);
    waiterBillingCreditCardFields($test)
        ->call('activateWaiterModule')
        ->assertHasNoErrors();

    expect($company->fresh()->waiter_module_enabled)->toBeTrue();
});

test('cancelamento do módulo PDV cancela o módulo Garçom em cascata', function () {
    $company = waiterBillingTestCompany();
    $company->update(['waiter_module_enabled' => true]);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        $mock->shouldReceive('updateSubscriptionValue')
            ->once()
            ->withArgs(fn ($subscriptionId, $value, $description) => $value === 59.0)
            ->andReturn(['value' => 59.0]);
    });

    Livewire::actingAs($admin)
        ->test(BillingSettings::class)
        ->call('cancelPdvModule')
        ->assertHasNoErrors();

    $company->refresh();
    expect($company->pdv_module_enabled)->toBeFalse();
    expect($company->waiter_module_enabled)->toBeFalse();
});

test('cancelamento do módulo Garçom mantém o módulo PDV ativo', function () {
    $company = waiterBillingTestCompany();
    $company->update(['waiter_module_enabled' => true]);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        $mock->shouldReceive('updateSubscriptionValue')
            ->once()
            ->withArgs(fn ($subscriptionId, $value, $description) => $value === 59.0 + 99.0)
            ->andReturn(['value' => 59.0 + 99.0]);
    });

    Livewire::actingAs($admin)
        ->test(BillingSettings::class)
        ->call('cancelWaiterModule')
        ->assertHasNoErrors();

    $company->refresh();
    expect($company->pdv_module_enabled)->toBeTrue();
    expect($company->waiter_module_enabled)->toBeFalse();
});

// ─── Criação simplificada de garçom ─────────────────────────────────────────

test('atalho rápido em mesas/comandas cria garçom vinculado à filial quando o módulo Garçom está ativo', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = pdvContext();
    $company->update(['waiter_module_enabled' => true]);

    Livewire::actingAs($admin)
        ->test(TabTerminal::class)
        ->assertSet('waiterModuleEnabled', true)
        ->assertSet('canManageUsers', true)
        ->set('quickWaiterName', 'Garçom Rápido')
        ->set('quickWaiterEmail', 'garcom.rapido@teste.com')
        ->set('quickWaiterBranchId', $branch->id)
        ->call('quickCreateWaiter')
        ->assertHasNoErrors();

    $waiter = User::where('email', 'garcom.rapido@teste.com')->first();
    expect($waiter)->not->toBeNull();

    $pivot = $waiter->companies()->where('companies.id', $company->id)->first()->pivot;
    expect($pivot->role)->toBe('garcom');
    expect($pivot->branch_id)->toBe($branch->id);
});

test('atalho rápido de garçom em mesas/comandas fica indisponível sem o módulo Garçom ativo', function () {
    ['company' => $company, 'admin' => $admin] = pdvContext();
    $company->update(['waiter_module_enabled' => false]);

    Livewire::actingAs($admin)
        ->test(TabTerminal::class)
        ->assertSet('waiterModuleEnabled', false)
        ->set('quickWaiterName', 'Garçom Indevido')
        ->set('quickWaiterEmail', 'garcom.indevido@teste.com')
        ->call('quickCreateWaiter')
        ->assertForbidden();

    expect(User::where('email', 'garcom.indevido@teste.com')->exists())->toBeFalse();
});

test('caixa sem permissão users.manage não consegue usar o atalho de criar garçom', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $company->update(['waiter_module_enabled' => true]);

    $caixa = User::factory()->create();
    $caixa->companies()->attach($company->id, ['role' => 'caixa', 'branch_id' => $branch->id]);

    \App\Models\PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $caixa->id,
        'opening_amount' => 0,
    ]);

    Livewire::actingAs($caixa)
        ->test(TabTerminal::class)
        ->assertOk()
        ->assertSet('canManageUsers', false)
        ->set('quickWaiterName', 'Garçom Indevido')
        ->set('quickWaiterEmail', 'garcom.semperm@teste.com')
        ->call('quickCreateWaiter')
        ->assertForbidden();

    expect(User::where('email', 'garcom.semperm@teste.com')->exists())->toBeFalse();
});

test('modal de adicionar garçom lista todos os garçons já cadastrados na empresa', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = pdvContext();
    $company->update(['waiter_module_enabled' => true]);

    $waiter1 = makeWaiter($company, $branch);
    $waiter1->update(['name' => 'Ana Garçonete']);
    $waiter2 = makeWaiter($company, $branch);
    $waiter2->update(['name' => 'Bruno Garçom']);

    Livewire::actingAs($admin)
        ->test(TabTerminal::class)
        ->assertSee('Ana Garçonete')
        ->assertSee('Bruno Garçom');
});
