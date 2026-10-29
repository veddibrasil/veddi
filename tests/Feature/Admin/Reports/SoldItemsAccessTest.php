<?php

/*
 * Acesso à tela de itens vendidos (/admin/reports/sold-items): só company_admin, checado na rota
 * (company.role:company_admin) e em toda requisição do componente (RequiresCompanyAdmin), inclusive
 * POST /livewire/update de uma aba aberta antes do rebaixamento.
 */

use App\DTOs\SoldItemsFilters;
use App\Livewire\Admin\Reports\SoldItems;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

require_once __DIR__.'/SoldItemsHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-28 12:00:00');
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

/** Snapshot do componente sold-items extraído do HTML da página renderizada pela rota. */
function sirSnapshotFromPage(string $html): ?string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $all);

    return collect($all[1])
        ->map(fn ($snapshot) => html_entity_decode($snapshot, ENT_QUOTES))
        ->first(fn ($snapshot) => (json_decode($snapshot, true)['memo']['name'] ?? null) === 'admin.reports.sold-items');
}

// ─── Rota ───────────────────────────────────────────────────────────────────

test('company_admin abre a tela de itens vendidos', function () {
    $ctx = sirContext();
    $admin = sirUser($ctx['company']);

    $this->actingAs($admin)
        ->get(route('admin.reports.sold-items'))
        ->assertOk()
        ->assertSee('Itens vendidos');
});

test('a rota fica em /admin/reports/sold-items', function () {
    expect(route('admin.reports.sold-items', absolute: false))->toBe('/admin/reports/sold-items');
});

test('branch_manager recebe 403', function () {
    $ctx = sirContext();
    $manager = sirUser($ctx['company'], 'branch_manager', $ctx['branch']);

    $this->actingAs($manager)
        ->get(route('admin.reports.sold-items'))
        ->assertForbidden();
});

test('papel customizado da empresa recebe 403', function () {
    $ctx = sirContext();
    $role = 'estoquista_'.uniqid();
    Role::create([
        'name' => 'Estoquista',
        'slug' => $role,
        'company_id' => $ctx['company']->id,
        'is_system' => false,
    ]);
    $user = sirUser($ctx['company'], $role, $ctx['branch']);

    $this->actingAs($user)
        ->get(route('admin.reports.sold-items'))
        ->assertForbidden();
});

test('papéis operacionais recebem 403', function (string $role) {
    $ctx = sirContext();
    $user = sirUser($ctx['company'], $role, $ctx['branch']);

    $this->actingAs($user)
        ->get(route('admin.reports.sold-items'))
        ->assertForbidden();
})->with(['cozinha', 'caixa', 'bar', 'entrega', 'garcom']);

test('visitante é redirecionado para o login', function () {
    sirContext();

    $this->get(route('admin.reports.sold-items'))->assertRedirect(route('login'));
});

test('super admin sem vínculo com a empresa recebe 403 (tem painel próprio)', function () {
    sirContext();
    $root = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($root)
        ->get(route('admin.reports.sold-items'))
        ->assertForbidden();
});

// ─── Componente (sem passar pela rota) ──────────────────────────────────────

test('o componente barra quem não é company_admin mesmo sem o middleware da rota', function (string $role) {
    $ctx = sirContext();
    if ($role === 'custom') {
        $role = 'papel_'.uniqid();
        Role::create(['name' => 'Papel', 'slug' => $role, 'company_id' => $ctx['company']->id, 'is_system' => false]);
    }
    $user = sirUser($ctx['company'], $role, $ctx['branch']);

    Livewire::actingAs($user)
        ->test(SoldItems::class)
        ->assertForbidden();
})->with(['branch_manager', 'cozinha', 'custom']);

test('o componente barra super admin sem vínculo', function () {
    sirContext();
    $root = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($root)
        ->test(SoldItems::class)
        ->assertForbidden();
});

// ─── Sidebar ────────────────────────────────────────────────────────────────

test('sidebar mostra o link "Itens vendidos" para o company_admin', function () {
    $ctx = sirContext();
    $admin = sirUser($ctx['company']);

    $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();

    expect($html)->toContain('href="'.route('admin.reports.sold-items').'"');
});

test('sidebar não mostra o link para branch_manager', function () {
    $ctx = sirContext();
    $manager = sirUser($ctx['company'], 'branch_manager', $ctx['branch']);

    $html = $this->actingAs($manager)->get(route('admin.dashboard'))->assertOk()->getContent();

    expect($html)->not->toContain(route('admin.reports.sold-items'));
});

test('sidebar não mostra o link para papel customizado', function () {
    $ctx = sirContext();
    $role = 'papel_'.uniqid();
    Role::create(['name' => 'Papel', 'slug' => $role, 'company_id' => $ctx['company']->id, 'is_system' => false]);
    $user = sirUser($ctx['company'], $role, $ctx['branch']);

    $html = $this->actingAs($user)->get(route('admin.dashboard'))->assertOk()->getContent();

    expect($html)->not->toContain(route('admin.reports.sold-items'));
});

// ─── Rebaixamento com a aba aberta ──────────────────────────────────────────

test('admin rebaixado a gerente não exporta pela aba já montada', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 8.0);
    sirOrder($ctx, '2026-10-20 12:00:00', [[$product, 2]]);
    $admin = sirUser($ctx['company']);

    $component = Livewire::actingAs($admin)->test(SoldItems::class)->assertOk();

    $admin->companies()->updateExistingPivot($ctx['company']->id, ['role' => 'branch_manager', 'branch_id' => $ctx['branch']->id]);

    // No harness do Livewire o usuário não é recarregado a cada request: reautentica.
    Livewire::actingAs($admin->fresh());

    $component->call('exportCsv')->assertForbidden();
});

test('admin rebaixado a gerente não abre detalhe nem recarrega pela aba já montada', function (string $method, array $params) {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 8.0);
    sirOrder($ctx, '2026-10-20 12:00:00', [[$product, 2]]);
    $admin = sirUser($ctx['company']);

    $component = Livewire::actingAs($admin)->test(SoldItems::class)->assertOk();

    $admin->companies()->updateExistingPivot($ctx['company']->id, ['role' => 'branch_manager', 'branch_id' => $ctx['branch']->id]);
    Livewire::actingAs($admin->fresh());

    $component->call($method, ...$params)->assertForbidden();
})->with([
    'openDetail' => ['openDetail', ['p:1']],
    'refresh' => ['refresh', []],
]);

test('fim a fim: POST /livewire/update com o snapshot antigo depois do rebaixamento dá 403', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 8.0);
    sirOrder($ctx, '2026-10-20 12:00:00', [[$product, 2]]);
    $admin = sirUser($ctx['company']);

    // 1) Página real renderizada pela rota enquanto ainda é admin.
    $html = $this->actingAs($admin)->get(route('admin.reports.sold-items'))->assertOk()->getContent();
    $snapshot = sirSnapshotFromPage($html);
    expect($snapshot)->not->toBeNull();

    // Controle: com o papel ainda de admin, o mesmo snapshot exporta.
    $this->actingAs($admin->fresh())
        ->withHeaders(['X-Livewire' => '1'])
        ->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [['method' => 'exportCsv', 'params' => [], 'metadata' => []]],
            ]],
        ])
        ->assertOk();

    // 2) Rebaixado a gerente de filial: a rota passa a dar 403.
    $admin->companies()->updateExistingPivot($ctx['company']->id, ['role' => 'branch_manager', 'branch_id' => $ctx['branch']->id]);
    $this->actingAs($admin->fresh())->get(route('admin.reports.sold-items'))->assertForbidden();

    // 3) A aba antiga tenta exportar pelo endpoint de update do Livewire.
    $response = $this->actingAs($admin->fresh())
        ->withHeaders(['X-Livewire' => '1'])
        ->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [['method' => 'exportCsv', 'params' => [], 'metadata' => []]],
            ]],
        ]);

    $response->assertForbidden();
    expect($response->getContent())->not->toContain('Coxinha');
});

// ─── Filtros vindos da URL ──────────────────────────────────────────────────

test('filial e categoria de outra empresa viram null nos filtros', function () {
    $a = sirContext('A');
    $b = sirContext('B');

    $filters = SoldItemsFilters::fromInput($a['company']->id, '2026-10-01', '2026-10-28', $b['branch']->id, null, $b['category']->id);

    expect($filters->branchId)->toBeNull()
        ->and($filters->categoryId)->toBeNull();

    $own = SoldItemsFilters::fromInput($a['company']->id, '2026-10-01', '2026-10-28', (string) $a['branch']->id, null, (string) $a['category']->id);

    expect($own->branchId)->toBe($a['branch']->id)
        ->and($own->categoryId)->toBe($a['category']->id);
});

test('canal fora da lista e lixo nos ids são ignorados', function () {
    $a = sirContext('A');

    // Ids passam por cast para int e depois são conferidos contra a empresa.
    $filters = SoldItemsFilters::fromInput($a['company']->id, '2026-10-01', '2026-10-28', 'abc', 'telefone', '999999 OR 1=1');

    expect($filters->branchId)->toBeNull()
        ->and($filters->channel)->toBeNull()
        ->and($filters->categoryId)->toBeNull();
});

test('na tela, filial/categoria de outra empresa são ignoradas e nada da outra empresa aparece', function () {
    $b = sirContext('B');
    $foreign = sirProduct($b['company'], $b['category'], 'Produto Secreto B', 99.0);
    sirOrder($b, '2026-10-20 12:00:00', [[$foreign, 5]]);

    $a = sirContext('A');
    $own = sirProduct($a['company'], $a['category'], 'Coxinha A', 8.0);
    sirOrder($a, '2026-10-20 12:00:00', [[$own, 3]]);
    $admin = sirUser($a['company']);

    $component = Livewire::actingAs($admin)
        ->test(SoldItems::class)
        ->set('branchFilter', (string) $b['branch']->id)
        ->set('categoryFilter', (string) $b['category']->id);

    $filters = $component->instance()->filters;
    $report = $component->instance()->report;

    expect($filters->branchId)->toBeNull()
        ->and($filters->categoryId)->toBeNull()
        ->and($report['kpis']['orders'])->toBe(1)
        ->and($report['kpis']['items'])->toBe(3)
        ->and(array_column($report['products'], 'name'))->toBe(['Coxinha A'])
        ->and(array_keys($report['branches']))->toBe([$a['branch']->id]);

    $component->assertDontSee('Produto Secreto B');
});
