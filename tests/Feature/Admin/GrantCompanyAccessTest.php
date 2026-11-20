<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function gcaCompany(string $suffix): Company
{
    return Company::create([
        'name' => 'Empresa GCA '.$suffix,
        'slug' => 'gca-'.$suffix.'-'.uniqid(),
        'order_prefix' => 'G'.strtoupper($suffix),
        'active' => true,
        'plan' => 'pro',
    ]);
}

test('vincula o usuário como admin em todas as empresas e mantém o vínculo existente', function () {
    $a = gcaCompany('a');
    $b = gcaCompany('b');
    $c = gcaCompany('c');

    $user = User::factory()->create(['email' => 'suporte@teste.com']);
    $user->companies()->attach($b->id, ['role' => 'caixa', 'branch_id' => null]);

    $this->artisan('user:grant-company-access', ['email' => 'suporte@teste.com'])->assertSuccessful();

    $user->refresh();
    expect($user->companies()->count())->toBe(3);
    expect($user->roleForCompany($a))->toBe('company_admin');
    expect($user->roleForCompany($c))->toBe('company_admin');
    expect($user->roleForCompany($b))->toBe('caixa');
});

test('dry-run não grava nada', function () {
    gcaCompany('a');

    $user = User::factory()->create(['email' => 'dry@teste.com']);

    $this->artisan('user:grant-company-access', ['email' => 'dry@teste.com', '--dry-run' => true])
        ->assertSuccessful();

    expect($user->companies()->count())->toBe(0);
});

test('e-mail inexistente falha sem criar usuário', function () {
    gcaCompany('a');

    $this->artisan('user:grant-company-access', ['email' => 'ninguem@teste.com'])
        ->assertFailed();

    expect(User::where('email', 'ninguem@teste.com')->exists())->toBeFalse();
});

test('aceita só papéis de administração', function () {
    User::factory()->create(['email' => 'papel@teste.com']);

    $this->artisan('user:grant-company-access', ['email' => 'papel@teste.com', '--role' => 'caixa'])
        ->assertFailed();
});

test('--company limita às empresas informadas', function () {
    $a = gcaCompany('a');
    $b = gcaCompany('b');

    $user = User::factory()->create(['email' => 'limite@teste.com']);

    $this->artisan('user:grant-company-access', [
        'email' => 'limite@teste.com',
        '--company' => [(string) $b->id],
    ])->assertSuccessful();

    expect($user->companies()->pluck('companies.id')->all())->toBe([$b->id]);
});
