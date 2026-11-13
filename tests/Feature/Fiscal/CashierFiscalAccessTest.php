<?php

use App\Livewire\Admin\Fiscal\Notes;
use App\Livewire\Admin\Users\Permissions;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FiscalExport;
use App\Models\FiscalNote;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Http::preventStrayRequests();
    Storage::fake('local');

    $this->company = Company::create([
        'name' => 'Empresa Caixa', 'slug' => 'caixa-fiscal', 'order_prefix' => 'CXF',
        'active' => true, 'status' => 'ACTIVE', 'fiscal_notes_enabled' => true,
    ]);
    app()->instance('current.company', $this->company);
    $this->branch = Branch::create([
        'company_id' => $this->company->id, 'name' => 'Filial Caixa',
        'address' => 'Rua A', 'city' => 'São Paulo', 'active' => true,
    ]);
    $this->cashier = User::factory()->create();
    $this->cashier->companies()->attach($this->company->id, ['role' => 'caixa', 'branch_id' => $this->branch->id]);
    $this->admin = User::factory()->create();
    $this->admin->companies()->attach($this->company->id, ['role' => 'company_admin']);
});

function saveCashierFiscalPermissions($test, array $overrides): void
{
    Livewire::actingAs($test->admin)->test(Permissions::class, ['user' => $test->cashier])
        ->set('overrides', $overrides)->call('save')->assertHasNoErrors();
}

test('caixa abre a rota fiscal depois de receber a permissão pelo painel', function (string $route, string $permission) {
    // A consulta anterior também aquece o cache para verificar a liberação imediata.
    expect($this->cashier->hasPermission($permission, $this->company))->toBeFalse();
    saveCashierFiscalPermissions($this, [str_replace('.', '__', $permission) => 'grant']);

    $this->actingAs($this->cashier)->get(route($route))->assertOk();
})->with([
    'notas' => ['admin.fiscal.notes', 'fiscal.view'],
    'controle' => ['admin.fiscal.control', 'fiscal.view'],
    'configurações' => ['admin.fiscal.config', 'fiscal.settings'],
]);

test('caixa sem permissão ou com módulo desativado continua bloqueado', function (string $route) {
    $this->actingAs($this->cashier)->get(route($route))->assertForbidden();
    saveCashierFiscalPermissions($this, ['fiscal__view' => 'grant']);
    $this->company->update(['fiscal_notes_enabled' => false]);

    $this->actingAs($this->cashier)->get(route($route))->assertForbidden();
})->with(['admin.fiscal.notes', 'admin.fiscal.control']);

test('revogar a permissão do caixa bloqueia a próxima requisição', function () {
    saveCashierFiscalPermissions($this, ['fiscal__view' => 'grant']);
    $this->actingAs($this->cashier)->get(route('admin.fiscal.notes'))->assertOk();
    saveCashierFiscalPermissions($this, ['fiscal__view' => 'deny']);

    $this->actingAs($this->cashier)->get(route('admin.fiscal.notes'))->assertForbidden();
});

test('visualizar notas não permite ao caixa cancelar ou reemitir', function () {
    saveCashierFiscalPermissions($this, ['fiscal__view' => 'grant']);

    Livewire::actingAs($this->cashier)->test(Notes::class)->call('openCancelModal', 1)->assertForbidden();
    Livewire::actingAs($this->cashier)->test(Notes::class)->call('openReissueModal', 1)->assertForbidden();
});

test('caixa baixa documentos e lotes apenas da própria filial e empresa', function () {
    saveCashierFiscalPermissions($this, ['fiscal__view' => 'grant']);
    $note = FiscalNote::create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'model' => '65', 'status' => 'authorized',
    ]);
    Storage::disk('local')->put("fiscal/{$this->company->id}/{$note->id}/xml.xml", '<nfeProc/>');
    $export = FiscalExport::create([
        'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'user_id' => $this->cashier->id, 'month' => '2026-10', 'environment' => 'producao',
        'note_ids' => [$note->id], 'status' => 'ready', 'path' => 'cashier.zip',
    ]);
    Storage::disk('local')->put('cashier.zip', 'archive');
    $documentUrl = route('admin.fiscal.document', ['note' => $note->id, 'type' => 'xml']);
    $exportUrl = route('admin.fiscal.export', $export->id);

    $this->actingAs($this->cashier)->get($documentUrl)->assertOk()->assertDownload();
    $this->get($exportUrl)->assertOk()->assertDownload();

    $otherBranch = Branch::create([
        'company_id' => $this->company->id, 'name' => 'Outra filial',
        'address' => 'Rua B', 'city' => 'São Paulo', 'active' => true,
    ]);
    $note->update(['branch_id' => $otherBranch->id]);
    $export->update(['branch_id' => $otherBranch->id]);
    $this->get($documentUrl)->assertForbidden();
    $this->get($exportUrl)->assertForbidden();
    $export->update(['branch_id' => null]);
    $this->get($exportUrl)->assertForbidden();

    $otherCompany = Company::create([
        'name' => 'Outra empresa', 'slug' => 'outra-fiscal', 'order_prefix' => 'OTR', 'active' => true,
    ]);
    $note->update(['company_id' => $otherCompany->id]);
    $export->update(['company_id' => $otherCompany->id]);
    $this->get($documentUrl)->assertNotFound();
    $this->get($exportUrl)->assertNotFound();
    Http::assertNothingSent();
});
