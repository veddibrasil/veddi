<?php

use App\Livewire\Admin\Roles\Index as RolesIndex;
use App\Livewire\SuperAdmin\Permissions\Index as SuperAdminPermissions;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Company\UserPermissionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // orders.report, pdv.sell e fiscal.* só existem pelo seeder (as migrations de seed são mais antigas).
    $this->seed(PermissionSeeder::class);
});

function rolesCompany(): Company
{
    $company = Company::create([
        'name' => 'Empresa '.uniqid(),
        'slug' => 'empresa-'.uniqid(),
        'order_prefix' => 'TST',
        'active' => true,
        'pdv_module_enabled' => true,
        'waiter_module_enabled' => true,
        'fiscal_notes_enabled' => true,
    ]);

    Branch::create(['company_id' => $company->id, 'name' => 'Filial Centro', 'active' => true]);

    return $company;
}

function rolesAdmin(Company $company): User
{
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    return $admin;
}

/** Cria/edita o tipo pela tela, como o administrador faz. */
function saveRoleViaScreen(Company $company, User $admin, string $name, array $permissions, ?Role $editing = null): Role
{
    app()->instance('current.company', $company);

    $component = Livewire::actingAs($admin)->test(RolesIndex::class);

    if ($editing) {
        $component->call('edit', $editing->id);
    }

    $component->set('name', $name)
        ->set('selectedPermissions', $permissions)
        ->call('save')
        ->assertHasNoErrors();

    return Role::where('company_id', $company->id)->where('name', $name)->firstOrFail();
}

/** Usuário entra no tipo pela tela (copia as permissões para user_permissions, como em produção). */
function memberOf(Company $company, Role $role): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => $role->slug]);
    UserPermissionService::assignRolePermissions($user, $company, $role->slug);

    return $user;
}

/** Link exato (com aspas): /admin/pdv não pode casar com /admin/pdv/comandas. */
function sidebarHref(string $routeName): string
{
    return 'href="'.route($routeName).'"';
}

// ─── Criação: cada permissão marcada libera a tela ───────────────────────────

dataset('telas por permissao', [
    'pedidos' => ['orders.view', 'admin.orders.index'],
    'relatório de pedidos' => ['orders.report', 'admin.orders.report'],
    'produtos' => ['products.view', 'admin.products.index'],
    'categorias' => ['categories.view', 'admin.categories.index'],
    'filiais' => ['branches.view', 'admin.branches.index'],
    'estoque' => ['stock.view', 'admin.stock.index'],
    'cupons' => ['coupons.view', 'admin.coupons.index'],
    'PDV completo' => ['pdv.operate', 'admin.pdv'],
    'PDV venda direta' => ['pdv.sell', 'admin.pdv'],
    'PDV garçom' => ['pdv.waiter_operate', 'admin.pdv'],
    'controle de notas' => ['fiscal.view', 'admin.fiscal.control'],
    'notas fiscais (só emitir)' => ['fiscal.issue', 'admin.fiscal.notes'],
    'dados fiscais' => ['fiscal.settings', 'admin.fiscal.config'],
]);

test('tipo novo libera no menu e na rota a tela de cada permissão marcada', function (string $permission, string $routeName) {
    $company = rolesCompany();
    $role = saveRoleViaScreen($company, rolesAdmin($company), 'Atendente', [$permission]);
    $user = memberOf($company, $role);

    expect($role->permissions()->pluck('name')->all())->toBe([$permission]);

    // Menu: o link da tela aparece.
    $this->actingAs($user)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(sidebarHref($routeName), false);

    // Rota: a tela abre (PDV redireciona para o terminal certo).
    $response = $this->actingAs($user)->get(route($routeName));
    expect($response->status())->toBeIn([200, 302]);
    if ($response->isRedirect()) {
        $this->actingAs($user)->get($response->headers->get('Location'))->assertOk();
    }
})->with('telas por permissao');

test('tipo sem a permissão não vê o item nem abre a tela pela URL', function () {
    $company = rolesCompany();
    $role = saveRoleViaScreen($company, rolesAdmin($company), 'Só Cupons', ['coupons.view']);
    $user = memberOf($company, $role);

    $this->actingAs($user)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee(sidebarHref('admin.products.index'), false)
        ->assertDontSee(sidebarHref('admin.orders.index'), false)
        ->assertDontSee(sidebarHref('admin.stock.index'), false);

    foreach (['admin.products.index', 'admin.categories.index', 'admin.stock.index', 'admin.orders.index', 'admin.users.index', 'admin.roles.index', 'admin.settings'] as $routeName) {
        $this->actingAs($user)->get(route($routeName))->assertForbidden();
    }
});

test('tipo com garçom e outras telas mostra todas no menu, não só mesas', function () {
    $company = rolesCompany();
    $role = saveRoleViaScreen($company, rolesAdmin($company), 'Chefe de Salão', ['pdv.waiter_operate', 'orders.view', 'products.view']);
    $user = memberOf($company, $role);

    // Fora do dashboard (que tem atalhos próprios): só o menu lateral leva esses links.
    $this->actingAs($user)->get(route('admin.coupons.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.orders.index'))
        ->assertOk()
        ->assertSee(sidebarHref('admin.products.index'), false)
        ->assertSee(sidebarHref('admin.pdv'), false);
});

test('tipo sem ver pedidos não recebe sino nem alarme de pedidos (cada clique daria 403)', function () {
    $company = rolesCompany();
    $admin = rolesAdmin($company);
    $waiterLike = memberOf($company, saveRoleViaScreen($company, $admin, 'Garçom Extra', ['pdv.waiter_operate', 'products.view']));
    $orders = memberOf($company, saveRoleViaScreen($company, $admin, 'Atendente', ['orders.view', 'orders.update']));

    $this->actingAs($waiterLike)->get(route('admin.products.index'))
        ->assertOk()
        ->assertDontSeeLivewire('admin.notification-bell')
        ->assertDontSeeLivewire('admin.order-acceptance-alarm');

    $this->actingAs($orders)->get(route('admin.orders.index'))
        ->assertOk()
        ->assertSeeLivewire('admin.notification-bell')
        ->assertSeeLivewire('admin.order-acceptance-alarm');
});

test('garçom do sistema segue só com mesas e comandas no menu', function () {
    $company = rolesCompany();
    $waiter = User::factory()->create();
    $waiter->companies()->attach($company->id, ['role' => 'garcom']);
    UserPermissionService::assignRolePermissions($waiter, $company, 'garcom');

    $this->actingAs($waiter)->get(route('admin.pdv.tabs'))
        ->assertOk()
        ->assertSee(sidebarHref('admin.pdv.tabs'), false)
        ->assertDontSee(sidebarHref('admin.dashboard'), false);
});

// ─── Permissões só do administrador ───────────────────────────────────────────

test('permissão de tela só do administrador não entra no tipo, mesmo vinda da requisição', function () {
    $company = rolesCompany();
    $admin = rolesAdmin($company);

    $role = saveRoleViaScreen($company, $admin, 'Gerente Geral', ['orders.view', 'users.view', 'users.manage', 'roles.manage', 'company.settings']);

    expect($role->permissions()->pluck('name')->all())->toBe(['orders.view']);

    app()->instance('current.company', $company);
    Livewire::actingAs($admin)->test(RolesIndex::class)
        ->assertSeeHtml('data-test="admin-only-permission"')
        ->assertSee('Somente administrador');
});

test('tipo antigo com permissão só do administrador perde ela ao ser salvo, e os usuários também', function () {
    $company = rolesCompany();
    $admin = rolesAdmin($company);

    // Tipo criado antes da trava.
    $role = Role::create(['name' => 'Legado', 'slug' => 'legado', 'company_id' => $company->id, 'is_system' => false]);
    $role->permissions()->sync(Permission::whereIn('name', ['orders.view', 'users.manage'])->pluck('id'));
    $user = memberOf($company, $role);
    expect($user->hasPermission('users.manage', $company))->toBeTrue();

    app()->instance('current.company', $company);
    $component = Livewire::actingAs($admin)->test(RolesIndex::class)->call('edit', $role->id);
    expect($component->get('selectedPermissions'))->toBe(['orders.view']);
    $component->call('save')->assertHasNoErrors();

    expect($role->permissions()->pluck('name')->all())->toBe(['orders.view'])
        ->and($user->hasPermission('users.manage', $company))->toBeFalse()
        ->and($user->hasPermission('orders.view', $company))->toBeTrue();
});

test('menu não mostra Usuários, Tipos nem Configurações para quem não é administrador, mesmo com override', function () {
    $company = rolesCompany();
    $manager = User::factory()->create();
    $manager->companies()->attach($company->id, ['role' => 'branch_manager']);
    UserPermissionService::assignRolePermissions($manager, $company, 'branch_manager');

    foreach (['users.view', 'roles.manage', 'company.settings'] as $name) {
        UserPermission::create([
            'user_id' => $manager->id,
            'company_id' => $company->id,
            'permission_id' => Permission::where('name', $name)->value('id'),
            'granted' => true,
        ]);
    }
    User::clearPermissionCache($manager->id, $company->id);

    $this->actingAs($manager)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee(sidebarHref('admin.users.index'), false)
        ->assertDontSee(sidebarHref('admin.roles.index'), false)
        ->assertDontSee(sidebarHref('admin.settings'), false)
        ->assertDontSee(sidebarHref('admin.billing'), false);

    // Administrador segue vendo.
    $admin = rolesAdmin($company);
    UserPermissionService::assignRolePermissions($admin, $company, 'company_admin');
    $this->actingAs($admin)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(sidebarHref('admin.users.index'), false)
        ->assertSee(sidebarHref('admin.roles.index'), false)
        ->assertSee(sidebarHref('admin.settings'), false);
});

// ─── Edição: propaga para quem já está no tipo ────────────────────────────────

test('editar tipo tira na hora a permissão de quem já está nele', function () {
    $company = rolesCompany();
    $admin = rolesAdmin($company);
    $role = saveRoleViaScreen($company, $admin, 'Estoquista', ['orders.view', 'stock.view']);
    $user = memberOf($company, $role);

    // Esquenta o cache de permissões, como numa sessão já aberta.
    expect($user->hasPermission('stock.view', $company))->toBeTrue();
    $this->actingAs($user)->get(route('admin.stock.index'))->assertOk();

    saveRoleViaScreen($company, $admin, 'Estoquista', ['orders.view'], $role);

    expect($user->hasPermission('stock.view', $company))->toBeFalse()
        ->and($user->hasPermission('orders.view', $company))->toBeTrue();

    $this->actingAs($user)->get(route('admin.stock.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.dashboard'))->assertDontSee(sidebarHref('admin.stock.index'), false);
});

test('editar tipo dá na hora a permissão nova a quem já está nele', function () {
    $company = rolesCompany();
    $admin = rolesAdmin($company);
    $role = saveRoleViaScreen($company, $admin, 'Atendente', ['orders.view']);
    $user = memberOf($company, $role);

    expect($user->hasPermission('coupons.view', $company))->toBeFalse();
    $this->actingAs($user)->get(route('admin.coupons.index'))->assertForbidden();

    saveRoleViaScreen($company, $admin, 'Atendente', ['orders.view', 'coupons.view'], $role);

    expect($user->hasPermission('coupons.view', $company))->toBeTrue();
    $this->actingAs($user)->get(route('admin.coupons.index'))->assertOk();
    $this->actingAs($user)->get(route('admin.dashboard'))->assertSee(sidebarHref('admin.coupons.index'), false);
});

test('editar um tipo não mexe em outros tipos, outras empresas nem em ajustes individuais', function () {
    $company = rolesCompany();
    $admin = rolesAdmin($company);
    $edited = saveRoleViaScreen($company, $admin, 'Atendente', ['orders.view', 'stock.view']);
    $other = saveRoleViaScreen($company, $admin, 'Estoque', ['stock.view']);

    $member = memberOf($company, $edited);
    $otherMember = memberOf($company, $other);

    // Ajustes individuais do membro (tela de permissões do usuário).
    $orderUpdate = Permission::where('name', 'orders.update')->value('id');
    $coupons = Permission::where('name', 'coupons.view')->value('id');
    UserPermission::create(['user_id' => $member->id, 'company_id' => $company->id, 'permission_id' => $orderUpdate, 'granted' => false]);
    UserPermission::create(['user_id' => $member->id, 'company_id' => $company->id, 'permission_id' => $coupons, 'granted' => true]);
    User::clearPermissionCache($member->id, $company->id);

    // Mesmo slug em outra empresa: papel dela, não pode ser tocado.
    $otherCompany = rolesCompany();
    $foreignRole = saveRoleViaScreen($otherCompany, rolesAdmin($otherCompany), 'Atendente', ['orders.view', 'stock.view']);
    $foreignMember = memberOf($otherCompany, $foreignRole);

    // Tira estoque e dá atualizar pedidos.
    saveRoleViaScreen($company, $admin, 'Atendente', ['orders.view', 'orders.update'], $edited);

    expect($member->hasPermission('stock.view', $company))->toBeFalse()
        ->and($member->hasPermission('orders.update', $company))->toBeFalse() // "negar" individual fica
        ->and($member->hasPermission('coupons.view', $company))->toBeTrue()   // "liberar" individual fica
        ->and($otherMember->hasPermission('stock.view', $company))->toBeTrue()
        ->and($foreignMember->hasPermission('stock.view', $otherCompany))->toBeTrue()
        ->and($other->permissions()->pluck('name')->all())->toBe(['stock.view'])
        ->and($foreignRole->permissions()->pluck('name')->sort()->values()->all())->toBe(['orders.view', 'stock.view']);
});

test('salvar avisa quantos usuários receberam a mudança', function () {
    $company = rolesCompany();
    $admin = rolesAdmin($company);
    $role = saveRoleViaScreen($company, $admin, 'Atendente', ['orders.view']);
    memberOf($company, $role);
    memberOf($company, $role);

    app()->instance('current.company', $company);
    Livewire::actingAs($admin)->test(RolesIndex::class)
        ->call('edit', $role->id)
        ->set('selectedPermissions', ['orders.view', 'coupons.view'])
        ->call('save')
        ->assertSee('Tipo de usuário salvo. Acesso atualizado para 2 usuários com este tipo.');
});

test('nome sem letras nem números não cria tipo com identificador vazio', function () {
    $company = rolesCompany();
    app()->instance('current.company', $company);

    Livewire::actingAs(rolesAdmin($company))->test(RolesIndex::class)
        ->set('name', '!!!')
        ->call('save')
        ->assertHasErrors('name');

    expect(Role::where('company_id', $company->id)->count())->toBe(0);
});

// ─── Exclusão ─────────────────────────────────────────────────────────────────

test('tipo com usuários não pode ser removido; sem usuários pode', function () {
    $company = rolesCompany();
    $admin = rolesAdmin($company);
    $role = saveRoleViaScreen($company, $admin, 'Atendente', ['pdv.operate']);
    $user = memberOf($company, $role);

    app()->instance('current.company', $company);
    Livewire::actingAs($admin)->test(RolesIndex::class)
        ->assertSee('1 usuário')
        ->call('confirmDelete', $role->id)
        ->call('delete')
        ->assertSee('Não é possível remover: 1 usuário usa este tipo.');

    expect(Role::whereKey($role->id)->exists())->toBeTrue();

    $user->companies()->updateExistingPivot($company->id, ['role' => 'caixa']);

    Livewire::actingAs($admin)->test(RolesIndex::class)
        ->call('confirmDelete', $role->id)
        ->call('delete');

    expect(Role::whereKey($role->id)->exists())->toBeFalse();
});

// ─── Papéis do sistema (super admin) ──────────────────────────────────────────

test('super admin ao mudar papel do sistema atualiza na hora quem já tem o papel', function () {
    $company = rolesCompany();
    $cashier = User::factory()->create();
    $cashier->companies()->attach($company->id, ['role' => 'caixa']);
    UserPermissionService::assignRolePermissions($cashier, $company, 'caixa');

    $admin = rolesAdmin($company);
    UserPermissionService::assignRolePermissions($admin, $company, 'company_admin');

    expect($cashier->hasPermission('pdv.operate', $company))->toBeTrue();

    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    Livewire::actingAs($superAdmin)->test(SuperAdminPermissions::class)->call('toggle', 'caixa', 'pdv.operate');

    expect($cashier->hasPermission('pdv.operate', $company))->toBeFalse()
        ->and($admin->hasPermission('pdv.operate', $company))->toBeTrue();

    Livewire::actingAs($superAdmin)->test(SuperAdminPermissions::class)->call('toggle', 'caixa', 'pdv.operate');

    expect($cashier->hasPermission('pdv.operate', $company))->toBeTrue();
});
