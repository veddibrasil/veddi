<?php

use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function userDeletionCompany(string $slug): Company
{
    return Company::create([
        'name' => "Empresa {$slug}",
        'slug' => $slug,
        'order_prefix' => strtoupper($slug),
        'active' => true,
    ]);
}

test('excluir usuário remove ele de todas as empresas e apaga dados relacionados', function () {
    $companyA = userDeletionCompany('empresa-a');
    $companyB = userDeletionCompany('empresa-b');

    $admin = User::factory()->create(['is_super_admin' => true]);

    $target = User::factory()->create();
    $target->companies()->attach($companyA->id, ['role' => 'company_admin']);
    $target->companies()->attach($companyB->id, ['role' => 'branch_manager']);

    $permission = Permission::firstOrCreate(
        ['name' => 'orders.view'],
        ['group' => 'orders', 'label' => 'Ver pedidos']
    );

    UserPermission::create([
        'user_id' => $target->id,
        'company_id' => $companyA->id,
        'permission_id' => $permission->id,
        'granted' => true,
    ]);

    DB::table('sessions')->insert([
        'id' => 'session-target',
        'user_id' => $target->id,
        'payload' => base64_encode('x'),
        'last_activity' => time(),
    ]);

    app()->instance('current.company', $companyA);
    $this->actingAs($admin);

    Livewire::test(UsersIndex::class)
        ->call('confirmRemove', $target->id)
        ->call('removeUser')
        ->assertSet('removingUserId', null);

    expect(User::find($target->id))->toBeNull();
    expect(User::withTrashed()->find($target->id))->not->toBeNull();
    expect($target->companies()->count())->toBe(0);
    expect(UserPermission::where('user_id', $target->id)->count())->toBe(0);
    expect(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);
});

test('usuário não pode excluir a própria conta', function () {
    $companyA = userDeletionCompany('empresa-c');
    $admin = User::factory()->create(['is_super_admin' => true]);
    $admin->companies()->attach($companyA->id, ['role' => 'company_admin']);

    app()->instance('current.company', $companyA);
    $this->actingAs($admin);

    Livewire::test(UsersIndex::class)
        ->call('confirmRemove', $admin->id)
        ->call('removeUser')
        ->assertForbidden();

    expect(User::find($admin->id))->not->toBeNull();
});
