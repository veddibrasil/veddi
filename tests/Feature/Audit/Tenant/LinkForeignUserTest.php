<?php

/*
 * Auditoria 15/10/2026 — Admin/Roles/Index::assignUser() vincula QUALQUER usuário da
 * plataforma à empresa atual só pelo e-mail, sem convite nem consentimento (o lead já
 * travou o caminho equivalente em Users/Index::linkUser(); assignUser ficou aberto). IdentifyCompany resolve o tenant do usuário logado como
 * companies()->orderBy('id')->first() e não há seletor de empresa: se a empresa do
 * atacante for mais antiga (id menor), o dono da outra empresa passa a cair no painel
 * do atacante e perde acesso ao próprio. O teste afirma o comportamento seguro e deve
 * FALHAR hoje.
 */

use App\Livewire\Admin\Roles\Index as RolesIndex;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/TenantAuditHelpers.php';

uses(RefreshDatabase::class);

test('Roles/assignUser: company_admin não vincula à própria empresa o dono de outra empresa (sequestro do tenant resolvido)', function () {
    $this->seed(PermissionSeeder::class);

    $companyA = atnCompany('LinkA'); // mais antiga (id menor)
    $companyB = atnCompany('LinkB');
    atnBranch($companyB, 'Matriz B');
    $ownerB = atnUser($companyB, 'company_admin');

    app()->instance('current.company', $companyA);
    atnBranch($companyA, 'Matriz A');
    $adminA = atnUser($companyA, 'company_admin');

    $cozinhaRole = Role::whereNull('company_id')->where('slug', 'cozinha')->firstOrFail();

    atnAttempt(fn () => Livewire::actingAs($adminA)
        ->test(RolesIndex::class)
        ->call('openAssign', $cozinhaRole->id)
        ->set('assignUserEmail', $ownerB->email)
        ->call('assignUser'));

    // Próxima requisição do dono da empresa B: qual tenant o IdentifyCompany resolve?
    app()->forgetInstance('current.company');
    $this->actingAs($ownerB->fresh())->get(route('admin.orders.index'));
    $resolvedCompanyId = app()->bound('current.company') ? app('current.company')->id : null;

    expect($ownerB->companies()->where('companies.id', $companyA->id)->exists())->toBeFalse()
        ->and($resolvedCompanyId)->toBe($companyB->id);
});
