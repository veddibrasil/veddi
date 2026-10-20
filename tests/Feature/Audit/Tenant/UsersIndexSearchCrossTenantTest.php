<?php

/*
 * Auditoria 15/10/2026 — Admin/Users/Index::render(): o filtro de busca faz
 *   ->when($this->search, fn ($q) => $q->where('name', 'like', ...)->orWhere('email', 'like', ...))
 * sem agrupar. O SQL vira  (whereHas(empresa atual) AND name LIKE) OR email LIKE  e
 * qualquer company_admin lista nome+e-mail de usuários de TODAS as empresas (inclusive
 * super admins) digitando parte de um e-mail (ex.: "@"). O teste deve FALHAR hoje.
 */

use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/TenantAuditHelpers.php';

uses(RefreshDatabase::class);

test('busca de usuários do painel não lista usuários de outras empresas nem super admins', function () {
    $this->seed(PermissionSeeder::class);

    $companyB = atnCompany('UsrB');
    $foreign = User::factory()->create(['name' => 'Dono Empresa B', 'email' => 'dono.b@concorrente.com']);
    $foreign->companies()->attach($companyB->id, ['role' => 'company_admin']);
    User::factory()->create(['name' => 'Root Plataforma', 'email' => 'root@plataforma.com', 'is_super_admin' => true]);

    $companyA = atnCompany('UsrA');
    app()->instance('current.company', $companyA);
    $adminA = atnUser($companyA, 'company_admin');

    $html = Livewire::actingAs($adminA)
        ->test(UsersIndex::class)
        ->set('search', '@')
        ->html();

    expect($html)
        ->not->toContain('dono.b@concorrente.com')
        ->not->toContain('root@plataforma.com');
});
