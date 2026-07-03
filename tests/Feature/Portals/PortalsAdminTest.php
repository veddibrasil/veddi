<?php

use App\Livewire\Admin\Portals\Index;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Portal;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPortalMapping;
use App\Models\User;
use App\Services\Company\UserPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function portalsAdminContext(bool $portalsModuleEnabled = true): array
{
    $company = Company::create([
        'name' => 'Empresa Portais',
        'slug' => 'empresa-portais-'.uniqid(),
        'order_prefix' => 'PRT',
        'active' => true,
        'plan' => 'pro',
        'portals_module_enabled' => $portalsModuleEnabled,
    ]);

    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin', 'branch_id' => null]);
    UserPermissionService::assignRolePermissions($admin, $company, 'company_admin');
    test()->actingAs($admin);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Central',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Lanches',
        'active' => true,
        'sort_order' => 1,
    ]);

    $product = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'X-Burguer',
        'price' => 25.00,
        'active' => true,
        'sort_order' => 1,
    ]);

    return compact('company', 'branch', 'product');
}

test('sem módulo Portais contratado não permite conectar portal', function () {
    portalsAdminContext(false);

    Livewire::test(Index::class)
        ->assertSet('portalsEnabled', false);
});

test('usuário sem permissão portals.manage recebe 403', function () {
    $company = Company::create([
        'name' => 'Empresa Sem Permissão',
        'slug' => 'empresa-sem-permissao-'.uniqid(),
        'order_prefix' => 'NOP',
        'active' => true,
        'plan' => 'pro',
        'portals_module_enabled' => true,
    ]);

    app()->instance('current.company', $company);

    $branchManager = User::factory()->create();
    $branchManager->companies()->attach($company->id, ['role' => 'branch_manager', 'branch_id' => null]);
    UserPermissionService::assignRolePermissions($branchManager, $company, 'branch_manager');
    test()->actingAs($branchManager);

    Livewire::test(Index::class)->assertForbidden();
});

test('com módulo contratado inicia fluxo de conexão e recebe userCode do iFood', function () {
    ['branch' => $branch] = portalsAdminContext();

    Http::fake([
        'merchant-api.ifood.com.br/authentication/v1.0/oauth/userCode' => Http::response([
            'userCode' => 'ABC-123',
            'verificationUrlComplete' => 'https://portal.ifood.com.br/authorize?x=1',
            'authorizationCodeVerifier' => 'verifier-xyz',
        ], 200),
    ]);

    Livewire::test(Index::class)
        ->call('startConnect', $branch->id)
        ->assertSet('pendingUserCode', 'ABC-123')
        ->assertSet('pendingVerifier', 'verifier-xyz');
});

test('confirmConnect troca código por token e cria Portal conectado', function () {
    ['branch' => $branch] = portalsAdminContext();

    Http::fake([
        'merchant-api.ifood.com.br/authentication/v1.0/oauth/token' => Http::response([
            'accessToken' => 'access-abc',
            'refreshToken' => 'refresh-abc',
            'expiresIn' => 21600,
        ], 200),
    ]);

    Livewire::test(Index::class)
        ->set('connectingBranchId', $branch->id)
        ->set('pendingVerifier', 'verifier-xyz')
        ->set('authorizationCode', 'auth-code-1')
        ->set('externalMerchantId', 'merchant-999')
        ->call('confirmConnect')
        ->assertSet('portal.status', 'connected');

    expect(Portal::where('external_merchant_id', 'merchant-999')->exists())->toBeTrue();
});

test('saveMapping cria e remove mapeamento de produto', function () {
    ['company' => $company, 'branch' => $branch, 'product' => $product] = portalsAdminContext();

    $portal = Portal::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'channel' => 'ifood',
        'external_merchant_id' => 'merchant-1',
        'status' => 'connected',
    ]);

    $component = Livewire::test(Index::class)
        ->set("mappingInputs.{$product->id}", 'item-externo-1')
        ->call('saveMapping', $product->id);

    expect(ProductPortalMapping::where('portal_id', $portal->id)->where('product_id', $product->id)->value('external_item_id'))
        ->toBe('item-externo-1');

    $component->set("mappingInputs.{$product->id}", '')
        ->call('saveMapping', $product->id);

    expect(ProductPortalMapping::where('portal_id', $portal->id)->where('product_id', $product->id)->exists())->toBeFalse();
});

test('pauseOrders cria interrupção no iFood e resumeOrders remove', function () {
    ['company' => $company, 'branch' => $branch] = portalsAdminContext();

    $portal = Portal::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'channel' => 'ifood',
        'external_merchant_id' => 'merchant-1',
        'credentials' => [
            'access_token' => 'token-abc',
            'refresh_token' => 'refresh-abc',
            'expires_at' => now()->addHours(5)->toIso8601String(),
        ],
        'status' => 'connected',
    ]);

    Http::fake([
        'merchant-api.ifood.com.br/merchant/v1.0/merchants/merchant-1/interruptions' => Http::response(['id' => 'interruption-1'], 200),
        'merchant-api.ifood.com.br/merchant/v1.0/merchants/merchant-1/interruptions/interruption-1' => Http::response('', 200),
    ]);

    Livewire::test(Index::class)
        ->call('pauseOrders', 30)
        ->assertSet('portal.active_interruption_id', 'interruption-1');

    expect($portal->fresh()->paused_until)->not->toBeNull();

    Livewire::test(Index::class)
        ->call('resumeOrders')
        ->assertSet('portal.active_interruption_id', null);

    expect($portal->fresh()->paused_until)->toBeNull();
});
