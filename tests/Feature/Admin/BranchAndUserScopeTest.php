<?php

use App\Livewire\Admin\Users\Permissions;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Company\UserPermissionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function scopeCompany(string $slug): Company
{
    return Company::create([
        'name' => "Empresa {$slug}", 'slug' => $slug, 'order_prefix' => strtoupper(substr($slug, 0, 3)),
        'active' => true, 'plan' => 'pro', 'status' => 'ACTIVE', 'setup_fee_paid_at' => now(), 'terms_accepted_at' => now(),
    ]);
}

function scopeBranch(Company $company, string $name): Branch
{
    return Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => $name, 'address' => 'Rua A', 'city' => 'SP',
        'active' => true, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59',
    ]);
}

function scopeMember(Company $company, string $role, ?Branch $branch = null): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => $role, 'branch_id' => $branch?->id]);
    UserPermissionService::assignRolePermissions($user, $company, $role);

    return $user;
}

function scopeOrder(Company $company, Branch $branch): Order
{
    $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Cliente', 'phone' => '1199999'.random_int(1000, 9999)]);

    return Order::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
        'order_number' => 'T-'.uniqid(), 'status' => 'paid', 'subtotal' => 10, 'total' => 10,
        'payment_method' => 'cash', 'order_type' => 'pickup', 'channel' => 'chat',
    ]);
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

test('papel preso a uma filial não abre pedido nem cupom de outra filial pela URL', function (string $role) {
    $company = scopeCompany('rede');
    $centro = scopeBranch($company, 'Centro');
    $bairro = scopeBranch($company, 'Bairro');
    $user = scopeMember($company, $role, $centro);

    $own = scopeOrder($company, $centro);
    $other = scopeOrder($company, $bairro);

    $this->actingAs($user)->get("/admin/orders/{$own->id}")->assertOk();
    $this->actingAs($user)->get("/admin/orders/{$other->id}")->assertNotFound();
    $this->actingAs($user)->get("/admin/orders/{$other->id}/receipt")->assertForbidden();
})->with(['branch_manager', 'caixa', 'cozinha']);

test('admin da empresa continua vendo pedidos de todas as filiais', function () {
    $company = scopeCompany('rede');
    $bairro = scopeBranch($company, 'Bairro');
    $admin = scopeMember($company, 'company_admin');

    $this->actingAs($admin)->get('/admin/orders/'.scopeOrder($company, $bairro)->id)->assertOk();
});

test('admin não abre nem grava permissões de usuário de outra empresa', function () {
    $alfa = scopeCompany('alfa');
    $beta = scopeCompany('beta');
    $adminAlfa = scopeMember($alfa, 'company_admin');
    $adminBeta = scopeMember($beta, 'company_admin');
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($adminAlfa)->get("/admin/users/{$adminBeta->id}/permissions")->assertNotFound();
    $this->actingAs($adminAlfa)->get("/admin/users/{$superAdmin->id}/permissions")->assertNotFound();

    $colleague = scopeMember($alfa, 'branch_manager', scopeBranch($alfa, 'Centro'));
    $this->actingAs($adminAlfa)->get("/admin/users/{$colleague->id}/permissions")->assertOk();

    app()->instance('current.company', $alfa);
    $this->actingAs($adminAlfa);
    Livewire::test(Permissions::class, ['user' => $adminBeta])->assertNotFound();
    expect(UserPermission::where('user_id', $adminBeta->id)->where('company_id', $alfa->id)->exists())->toBeFalse();
});

test('busca de usuários por e-mail não lista usuários de outra empresa', function () {
    $alfa = scopeCompany('alfa');
    $beta = scopeCompany('beta');
    $adminAlfa = scopeMember($alfa, 'company_admin');
    $adminBeta = scopeMember($beta, 'company_admin');
    $adminBeta->update(['email' => 'dono@beta.test']);

    app()->instance('current.company', $alfa);
    $this->actingAs($adminAlfa);

    Livewire::test(\App\Livewire\Admin\Users\Index::class)
        ->set('search', 'beta.test')
        ->assertDontSee('dono@beta.test');
});

test('busca de pedidos por nome do cliente respeita a filial do usuário', function (string $viewMode) {
    $company = scopeCompany('rede');
    $centro = scopeBranch($company, 'Centro');
    $bairro = scopeBranch($company, 'Bairro');
    $manager = scopeMember($company, 'branch_manager', $centro);
    $other = scopeOrder($company, $bairro);
    $other->customer()->update(['name' => 'Fulano Outra Filial']);

    app()->instance('current.company', $company);
    $this->actingAs($manager);

    Livewire::test(\App\Livewire\Admin\Orders\Index::class)
        ->set('viewMode', $viewMode)
        ->set('search', 'Fulano')
        ->assertDontSee($other->order_number);
})->with(['list', 'kanban']);

test('vincular existente não puxa usuário de outra empresa nem super admin', function () {
    $alfa = scopeCompany('alfa');
    $beta = scopeCompany('beta');
    $adminAlfa = scopeMember($alfa, 'company_admin');
    $adminBeta = scopeMember($beta, 'company_admin');
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $semEmpresa = User::factory()->create();

    app()->instance('current.company', $alfa);
    $this->actingAs($adminAlfa);

    foreach ([$adminBeta, $superAdmin] as $target) {
        Livewire::test(\App\Livewire\Admin\Users\Index::class)
            ->set('linkEmail', $target->email)
            ->set('linkRole', 'company_admin')
            ->call('linkUser')
            ->assertHasErrors('linkEmail');
        expect($target->fresh()->companies()->whereKey($alfa->id)->exists())->toBeFalse();
    }

    // A empresa de entrada do admin da Beta continua sendo a dele.
    expect($adminBeta->fresh()->companies()->orderBy('id')->first()->id)->toBe($beta->id);

    Livewire::test(\App\Livewire\Admin\Users\Index::class)
        ->set('linkEmail', $semEmpresa->email)
        ->set('linkRole', 'caixa')
        ->call('linkUser')
        ->assertHasNoErrors();
    expect($semEmpresa->fresh()->companies()->whereKey($alfa->id)->exists())->toBeTrue();
});
