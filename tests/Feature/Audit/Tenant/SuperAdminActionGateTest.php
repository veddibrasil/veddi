<?php

/*
 * Auditoria 15/10/2026 — componentes SuperAdmin\* não têm nenhum gate dentro dos
 * métodos (toggleSuperAdmin, saveAssign, Companies\Index::delete/toggleActive,
 * Permissions\Index::toggle...). A proteção é só o middleware de rota `super.admin`,
 * que NÃO está na lista de persistent middleware do Livewire (vendor/livewire/.../
 * PersistentMiddleware.php), ou seja, não roda em POST /livewire/update. Quem já tem
 * o snapshot do componente (ex.: super admin rebaixado com a aba aberta) continua
 * executando as ações. O teste afirma o comportamento seguro e deve FALHAR hoje.
 */

use App\Livewire\SuperAdmin\Users\Index as SuperAdminUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/TenantAuditHelpers.php';

uses(RefreshDatabase::class);

test('super admin rebaixado não consegue se promover de novo pela aba já aberta (gate no método)', function () {
    $root = User::factory()->create(['is_super_admin' => true]);

    // Snapshot obtido legitimamente enquanto ainda era super admin.
    $component = Livewire::actingAs($root)->test(SuperAdminUsers::class);

    // Outro super admin revoga o privilégio.
    $root->forceFill(['is_super_admin' => false])->save();
    $this->actingAs($root->fresh());

    // Em HTTP o usuário é recarregado a cada request; no harness do Livewire é preciso reautenticar.
    Livewire::actingAs($root->fresh());

    atnAttempt(fn () => $component
        ->call('confirmToggleSuperAdmin', $root->id)
        ->call('toggleSuperAdmin'));

    expect((bool) $root->fresh()->is_super_admin)->toBeFalse();
});

test('fim a fim: POST /livewire/update não reaplica o middleware super.admin (snapshot antigo ainda age)', function () {
    $root = User::factory()->create(['is_super_admin' => true]);

    // 1) Página real renderizada pela rota protegida (middleware super.admin roda aqui).
    $html = $this->actingAs($root)->get(route('superadmin.users.index'))->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $all);
    $snapshot = collect($all[1])
        ->map(fn ($s) => html_entity_decode($s, ENT_QUOTES))
        ->first(fn ($s) => (json_decode($s, true)['memo']['name'] ?? null) === 'super-admin.users');
    expect($snapshot)->not->toBeNull();

    // 2) Privilégio revogado.
    $root->forceFill(['is_super_admin' => false])->save();

    // 3) Mesma sessão chama a ação pelo endpoint de update do Livewire.
    $response = $this->actingAs($root->fresh())
        ->withHeaders(['X-Livewire' => '1'])
        ->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [
                    ['method' => 'confirmToggleSuperAdmin', 'params' => [$root->id], 'metadata' => []],
                    ['method' => 'toggleSuperAdmin', 'params' => [], 'metadata' => []],
                ],
            ]],
        ]);

    expect((bool) $root->fresh()->is_super_admin)->toBeFalse("status HTTP do update: {$response->status()}");
});

test('fim a fim: company.role também não é reaplicado — admin rebaixado a cozinha ainda cria usuário pela aba aberta', function () {
    $this->seed(\Database\Seeders\PermissionSeeder::class);

    $company = atnCompany('Rbx');
    atnBranch($company, 'Matriz');
    $admin = atnUser($company, 'company_admin');

    $html = $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $all);
    $snapshot = collect($all[1])
        ->map(fn ($s) => html_entity_decode($s, ENT_QUOTES))
        ->first(fn ($s) => (json_decode($s, true)['memo']['name'] ?? null) === 'admin.users');
    expect($snapshot)->not->toBeNull();

    // Rebaixado para cozinha (a rota /admin/users passa a dar 403 para ele).
    $admin->companies()->updateExistingPivot($company->id, ['role' => 'cozinha']);
    \App\Models\UserPermission::where('user_id', $admin->id)->delete();
    \App\Models\User::clearPermissionCache($admin->id, $company->id);
    $this->actingAs($admin->fresh())->get(route('admin.users.index'))->assertForbidden();

    $this->actingAs($admin->fresh())
        ->withHeaders(['X-Livewire' => '1'])
        ->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [
                    'newName' => 'Conta Fantasma',
                    'newEmail' => 'fantasma@teste.com',
                    'newRole' => 'company_admin',
                    'newPassword' => 'senha-forte-123',
                ],
                'calls' => [['method' => 'createUser', 'params' => [], 'metadata' => []]],
            ]],
        ]);

    expect(\App\Models\User::where('email', 'fantasma@teste.com')->exists())->toBeFalse();
});
