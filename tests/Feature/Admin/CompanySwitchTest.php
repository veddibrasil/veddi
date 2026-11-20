<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function switchCompany(string $suffix, string $name): Company
{
    return Company::create([
        'name' => $name,
        'slug' => 'switch-'.$suffix.'-'.uniqid(),
        'order_prefix' => 'SW'.strtoupper($suffix),
        'active' => true,
        'plan' => 'pro',
    ]);
}

function switchSwitchRoute(): string
{
    return route('admin.company.switch', absolute: false);
}

test('sem escolha na sessão o acesso cai na empresa de menor id', function () {
    $first = switchCompany('a', 'Restaurante Alfa');
    $second = switchCompany('b', 'Restaurante Beta');

    $user = User::factory()->create();
    $user->companies()->attach($second->id, ['role' => 'company_admin']);
    $user->companies()->attach($first->id, ['role' => 'company_admin']);

    $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();

    expect(app('current.company')->id)->toBe($first->id);
});

test('usuário troca para outra empresa vinculada e o painel passa a operar nela', function () {
    $first = switchCompany('a', 'Restaurante Alfa');
    $second = switchCompany('b', 'Restaurante Beta');

    $user = User::factory()->create();
    $user->companies()->attach($first->id, ['role' => 'company_admin']);
    $user->companies()->attach($second->id, ['role' => 'company_admin']);

    $this->actingAs($user)
        ->post(switchSwitchRoute(), ['company_id' => $second->id])
        ->assertRedirect(route('admin.dashboard', absolute: false));

    $this->get(route('admin.dashboard'))->assertOk();

    expect(app('current.company')->id)->toBe($second->id);
});

test('troca persiste entre requests e volta a valer no próximo login', function () {
    $first = switchCompany('a', 'Restaurante Alfa');
    $second = switchCompany('b', 'Restaurante Beta');

    $user = User::factory()->create();
    $user->companies()->attach($first->id, ['role' => 'company_admin']);
    $user->companies()->attach($second->id, ['role' => 'company_admin']);

    $this->actingAs($user)->post(switchSwitchRoute(), ['company_id' => $second->id]);

    $this->get(route('admin.dashboard'))->assertOk();
    $this->get(route('admin.dashboard'))->assertOk();

    expect(app('current.company')->id)->toBe($second->id);
});

test('não troca para empresa sem vínculo e mantém a empresa atual', function () {
    $mine = switchCompany('a', 'Restaurante Alfa');
    $alien = switchCompany('x', 'Restaurante Alheio');

    $user = User::factory()->create();
    $user->companies()->attach($mine->id, ['role' => 'company_admin']);

    $this->actingAs($user)
        ->post(switchSwitchRoute(), ['company_id' => $alien->id])
        ->assertForbidden();

    $this->get(route('admin.dashboard'))->assertOk();

    expect(app('current.company')->id)->toBe($mine->id);
});

test('vínculo removido derruba a escolha da sessão e volta para a empresa de menor id', function () {
    $first = switchCompany('a', 'Restaurante Alfa');
    $second = switchCompany('b', 'Restaurante Beta');

    $user = User::factory()->create();
    $user->companies()->attach($first->id, ['role' => 'company_admin']);
    $user->companies()->attach($second->id, ['role' => 'company_admin']);

    $this->actingAs($user)->post(switchSwitchRoute(), ['company_id' => $second->id]);

    // Remoção de acesso pela plataforma/empresa enquanto a sessão ainda guarda a escolha.
    $user->companies()->detach($second->id);

    $this->get(route('admin.dashboard'))->assertOk();

    expect(app('current.company')->id)->toBe($first->id);
    expect(session(User::ACTIVE_COMPANY_SESSION_KEY))->toBeNull();
});

test('vínculo removido sem outra empresa não dá acesso ao painel', function () {
    $second = switchCompany('b', 'Restaurante Beta');

    $user = User::factory()->create();
    $user->companies()->attach($second->id, ['role' => 'company_admin']);

    $this->actingAs($user)->post(switchSwitchRoute(), ['company_id' => $second->id]);

    $user->companies()->detach();

    $this->get(route('admin.dashboard'))->assertForbidden();
});

test('troca redireciona para a tela inicial do papel na empresa escolhida', function () {
    $admin = switchCompany('a', 'Restaurante Alfa');
    $cashier = switchCompany('b', 'Restaurante Beta');

    $user = User::factory()->create();
    $user->companies()->attach($admin->id, ['role' => 'company_admin']);
    $user->companies()->attach($cashier->id, ['role' => 'caixa']);

    $this->actingAs($user)
        ->post(switchSwitchRoute(), ['company_id' => $cashier->id])
        ->assertRedirect(route('admin.pdv', absolute: false));
});

test('troca exige company_id válido', function () {
    $mine = switchCompany('a', 'Restaurante Alfa');

    $user = User::factory()->create();
    $user->companies()->attach($mine->id, ['role' => 'company_admin']);

    $this->actingAs($user)
        ->post(switchSwitchRoute(), ['company_id' => 'abc'])
        ->assertSessionHasErrors('company_id');

    $this->actingAs($user)
        ->post(switchSwitchRoute(), [])
        ->assertSessionHasErrors('company_id');
});

test('visitante não consegue trocar de empresa', function () {
    $company = switchCompany('a', 'Restaurante Alfa');

    $this->post(switchSwitchRoute(), ['company_id' => $company->id])
        ->assertRedirect(route('login', absolute: false));
});

test('seletor de empresa aparece só para quem tem mais de uma empresa', function () {
    $first = switchCompany('a', 'Restaurante Alfa');
    $second = switchCompany('b', 'Restaurante Beta');

    $single = User::factory()->create();
    $single->companies()->attach($first->id, ['role' => 'company_admin']);

    $this->actingAs($single)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee('data-test="company-switcher"', false);

    $multi = User::factory()->create();
    $multi->companies()->attach($first->id, ['role' => 'company_admin']);
    $multi->companies()->attach($second->id, ['role' => 'company_admin']);

    $this->actingAs($multi)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('data-test="company-switcher"', false)
        ->assertSee('Restaurante Alfa')
        ->assertSee('Restaurante Beta');
});

test('super admin não recebe o seletor de empresa', function () {
    $company = switchCompany('a', 'Restaurante Alfa');

    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $superAdmin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->actingAs($superAdmin)->get(route('superadmin.dashboard'))
        ->assertOk()
        ->assertDontSee('data-test="company-switcher"', false);
});

test('homeRouteName usa a empresa ativa, não a de menor id', function () {
    $first = switchCompany('a', 'Restaurante Alfa');
    $second = switchCompany('b', 'Restaurante Beta');

    $user = User::factory()->create();
    $user->companies()->attach($first->id, ['role' => 'company_admin']);
    $user->companies()->attach($second->id, ['role' => 'garcom']);

    app()->instance('current.company', $second);

    expect($user->homeRouteName())->toBe('admin.pdv.tabs');
});

test('super admin enxerga o link de Usuários na sidebar', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($superAdmin)->get(route('superadmin.dashboard'))
        ->assertOk()
        ->assertSee(route('superadmin.users.index', absolute: false), false);
});
