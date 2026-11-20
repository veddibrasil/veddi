<?php

use App\Livewire\SuperAdmin\Users\Index as UsersIndex;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function gacCompany(string $suffix): Company
{
    return Company::create([
        'name' => 'Empresa GAC '.$suffix,
        'slug' => 'gac-'.$suffix.'-'.uniqid(),
        'order_prefix' => 'A'.strtoupper($suffix),
        'active' => true,
        'plan' => 'pro',
    ]);
}

test('super admin concede acesso de admin em todas as empresas e mantém vínculos existentes', function () {
    $a = gacCompany('a');
    $b = gacCompany('b');

    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $target = User::factory()->create(['email' => 'suporte@teste.com']);
    $target->companies()->attach($b->id, ['role' => 'caixa', 'branch_id' => null]);

    Livewire::actingAs($superAdmin)
        ->test(UsersIndex::class)
        ->call('openGrantAll', $target->id)
        ->assertSet('grantAllUserId', $target->id)
        ->call('grantAllCompanies')
        ->assertSet('grantAllUserId', null);

    $target->refresh();
    expect($target->roleForCompany($a))->toBe('company_admin');
    expect($target->roleForCompany($b))->toBe('caixa');
    expect($target->companies()->count())->toBe(2);
});

test('cancelar não concede acesso', function () {
    gacCompany('a');

    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $target = User::factory()->create();

    Livewire::actingAs($superAdmin)
        ->test(UsersIndex::class)
        ->call('openGrantAll', $target->id)
        ->call('cancelGrantAll')
        ->assertSet('grantAllUserId', null);

    expect($target->companies()->count())->toBe(0);
});

test('usuário comum não consegue usar a ação', function () {
    gacCompany('a');

    $common = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($common)->get(route('superadmin.users.index'))->assertForbidden();

    // Mesmo chamando o método direto pelo Livewire, o gate do componente barra e nada é gravado.
    try {
        Livewire::actingAs($common)
            ->test(UsersIndex::class)
            ->set('grantAllUserId', $target->id)
            ->call('grantAllCompanies');
    } catch (Throwable) {
        // o gate pode lançar na montagem; o que importa é o efeito no banco
    }

    expect($target->companies()->count())->toBe(0);
});
