<?php

use App\Livewire\SuperAdmin\Permissions\UserPermissions;
use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function makeCompany(): Company
{
    return Company::create([
        'name' => 'Empresa Teste '.uniqid(),
        'slug' => 'empresa-'.uniqid(),
        'order_prefix' => 'TST',
        'active' => true,
    ]);
}

function makeUserWithRole(string $role): array
{
    $company = makeCompany();
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => $role]);

    return [$company, $user];
}

// ─── Visitante não autenticado ────────────────────────────────────────────────

test('visitante é redirecionado para login ao acessar admin dashboard', function () {
    makeCompany(); // garante uma empresa ativa para o middleware IdentifyCompany

    $this->get(route('admin.dashboard'))
        ->assertRedirect(route('login'));
});

test('visitante é redirecionado para login ao acessar admin settings', function () {
    makeCompany();

    $this->get(route('admin.settings'))
        ->assertRedirect(route('login'));
});

// ─── Usuário sem papel na empresa ─────────────────────────────────────────────

test('usuário sem papel na empresa recebe 403 no admin dashboard', function () {
    $company = makeCompany();
    $user = User::factory()->create();
    // Sem attach — usuário não tem papel nesta empresa

    // A empresa será resolvida via fallback "primeira empresa ativa"
    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

// ─── branch_manager ───────────────────────────────────────────────────────────

test('branch_manager acessa dashboard (rota compartilhada)', function () {
    [, $user] = makeUserWithRole('branch_manager');

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertOk();
});

test('branch_manager acessa pedidos (rota compartilhada)', function () {
    [, $user] = makeUserWithRole('branch_manager');

    $this->actingAs($user)
        ->get(route('admin.orders.index'))
        ->assertOk();
});

test('branch_manager não acessa settings (somente company_admin)', function () {
    [, $user] = makeUserWithRole('branch_manager');

    $this->actingAs($user)
        ->get(route('admin.settings'))
        ->assertForbidden();
});

test('branch_manager não acessa gerenciamento de papéis (somente company_admin)', function () {
    [, $user] = makeUserWithRole('branch_manager');

    $this->actingAs($user)
        ->get(route('admin.roles.index'))
        ->assertForbidden();
});

test('branch_manager não acessa gerenciamento de usuários (somente company_admin)', function () {
    [, $user] = makeUserWithRole('branch_manager');

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

// ─── cozinha / caixa ──────────────────────────────────────────────────────────

test('cozinha acessa dashboard e pedidos mas não settings', function () {
    [, $user] = makeUserWithRole('cozinha');

    $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
    $this->actingAs($user)->get(route('admin.orders.index'))->assertOk();
    $this->actingAs($user)->get(route('admin.settings'))->assertForbidden();
});

test('caixa acessa dashboard e pedidos mas não settings', function () {
    [, $user] = makeUserWithRole('caixa');

    $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
    $this->actingAs($user)->get(route('admin.orders.index'))->assertOk();
    $this->actingAs($user)->get(route('admin.settings'))->assertForbidden();
});

test('cozinha e caixa têm filial travada no container assim como branch_manager', function () {
    $company = makeCompany();
    $branch = \App\Models\Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Teste',
        'address' => 'Rua A',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    foreach (['cozinha', 'caixa'] as $role) {
        $user = User::factory()->create();
        $user->companies()->attach($company->id, ['role' => $role, 'branch_id' => $branch->id]);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();

        expect($user->isBranchScoped($company))->toBeTrue();
    }
});

// ─── company_admin ────────────────────────────────────────────────────────────

test('company_admin acessa dashboard', function () {
    [, $user] = makeUserWithRole('company_admin');

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertOk();
});

test('company_admin acessa settings', function () {
    [, $user] = makeUserWithRole('company_admin');

    $this->actingAs($user)
        ->get(route('admin.settings'))
        ->assertOk();
});

test('company_admin acessa gerenciamento de papéis', function () {
    [, $user] = makeUserWithRole('company_admin');

    $this->actingAs($user)
        ->get(route('admin.roles.index'))
        ->assertOk();
});

test('company_admin acessa gerenciamento de usuários', function () {
    [, $user] = makeUserWithRole('company_admin');

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertOk();
});

// ─── Super Admin ──────────────────────────────────────────────────────────────

test('super admin acessa rotas somente de company_admin sem ter papel na empresa', function () {
    makeCompany(); // empresa ativa para o middleware

    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    // Super admin não tem papel na empresa, mas bypassa o CheckCompanyRole
    $this->actingAs($superAdmin)
        ->get(route('admin.settings'))
        ->assertOk();
});

test('super admin acessa painel superadmin', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($superAdmin)
        ->get(route('superadmin.companies.index'))
        ->assertOk();
});

test('company_admin não acessa painel superadmin', function () {
    [, $user] = makeUserWithRole('company_admin');

    $this->actingAs($user)
        ->get(route('superadmin.companies.index'))
        ->assertForbidden();
});

test('super admin salva override de permissão com ponto e limpa cache', function () {
    $company = makeCompany();
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => 'viewer']);
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    Permission::firstOrCreate(
        ['name' => 'orders.update'],
        ['group' => 'orders', 'label' => 'Atualizar status de pedidos']
    );

    expect($user->hasPermission('orders.update', $company))->toBeFalse();

    $this->actingAs($superAdmin);

    Livewire::test(UserPermissions::class, ['user' => $user])
        ->set('overrides.orders__update', 'grant')
        ->call('save')
        ->assertHasNoErrors();

    expect(UserPermission::where('user_id', $user->id)
        ->where('company_id', $company->id)
        ->whereHas('permission', fn ($q) => $q->where('name', 'orders.update'))
        ->where('granted', true)
        ->exists())->toBeTrue();

    expect($user->hasPermission('orders.update', $company))->toBeTrue();
});

// ─── Isolamento entre empresas ────────────────────────────────────────────────

test('admin da empresa A não acessa painel superadmin (isolamento)', function () {
    [, $userA] = makeUserWithRole('company_admin');
    makeCompany(); // empresa B sem relação com userA

    $this->actingAs($userA)
        ->get(route('superadmin.companies.index'))
        ->assertForbidden();
});
