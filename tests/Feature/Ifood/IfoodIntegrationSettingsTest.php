<?php

use App\Jobs\SyncIfoodCatalogJob;
use App\Livewire\Admin\Settings\IfoodIntegrationSettings;
use App\Models\Branch;
use App\Models\Company;
use App\Models\IfoodIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ifoodSettingsTestCompany(): Company
{
    $company = Company::create([
        'name' => 'Empresa Settings iFood',
        'slug' => 'empresa-settings-ifood-'.uniqid(),
        'order_prefix' => 'SIF',
        'active' => true,
        'status' => 'ACTIVE',
    ]);

    Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Principal',
        'address' => 'Rua A',
        'city' => 'São Paulo',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    return $company;
}

function ifoodSettingsAdmin(Company $company): User
{
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    return $admin;
}

test('clicar em conectar inicia o fluxo de autorização e mostra o userCode', function () {
    config(['ifood.partner_client_id' => 'platform-client']);

    $company = ifoodSettingsTestCompany();
    $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();
    app()->instance('current.company', $company);

    Http::fake([
        '*/authentication/v1.0/oauth/userCode' => Http::response([
            'userCode' => 'ABCD-1234',
            'authorizationCodeVerifier' => 'verifier-xyz',
            'verificationUrlComplete' => 'https://portal.ifood.com.br/apps/link?c=ABCD-1234',
            'expiresIn' => 600,
        ], 200),
    ]);

    $admin = ifoodSettingsAdmin($company);

    Livewire::actingAs($admin)
        ->test(IfoodIntegrationSettings::class)
        ->set('branchId', $branch->id)
        ->call('connect')
        ->assertSet('connectionState', 'pending_authorization')
        ->assertSet('userCode', 'ABCD-1234')
        ->assertSet('verificationUrl', 'https://portal.ifood.com.br/apps/link?c=ABCD-1234');

    $integration = IfoodIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first();
    expect($integration)->not->toBeNull()
        ->and($integration->user_code)->toBe('ABCD-1234')
        ->and($integration->merchant_id)->toBeNull();
});

test('confirmar autorização com o código colado conclui a conexão', function () {
    $company = ifoodSettingsTestCompany();
    $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();
    app()->instance('current.company', $company);

    IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'merchant_id' => null,
        'status' => 'disconnected',
        'user_code' => 'ABCD-1234',
        'authorization_code_verifier' => 'verifier-xyz',
        'user_code_expires_at' => now()->addMinutes(5),
    ]);

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response([
            'accessToken' => 'access-final',
            'refreshToken' => 'refresh-final',
            'expiresIn' => 21600,
        ], 200),
        '*/merchant/v1.0/merchants' => Http::response([
            ['id' => 'merchant-abc', 'name' => 'Loja Teste'],
        ], 200),
    ]);
    Bus::fake([SyncIfoodCatalogJob::class]);

    $admin = ifoodSettingsAdmin($company);

    Livewire::actingAs($admin)
        ->test(IfoodIntegrationSettings::class)
        ->set('branchId', $branch->id)
        ->set('authorizationCode', 'HTLM-KWVR')
        ->call('confirmAuthorization')
        ->assertSet('connectionState', 'connected')
        ->assertSet('merchantId', 'merchant-abc');

    // Cardápio começa vazio no iFood — conectar precisa disparar o sync na hora
    // em vez de esperar o batch diário.
    Bus::assertDispatched(SyncIfoodCatalogJob::class, fn ($job) => $job->branchId === $branch->id);
});

test('confirmar autorização com código errado mostra erro e mantém pendente', function () {
    $company = ifoodSettingsTestCompany();
    $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();
    app()->instance('current.company', $company);

    IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'merchant_id' => null,
        'status' => 'disconnected',
        'user_code' => 'ABCD-1234',
        'authorization_code_verifier' => 'verifier-xyz',
        'user_code_expires_at' => now()->addMinutes(5),
    ]);

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response(['error' => ['code' => 'Unauthorized', 'message' => 'Invalid authorization code']], 401),
    ]);

    $admin = ifoodSettingsAdmin($company);

    Livewire::actingAs($admin)
        ->test(IfoodIntegrationSettings::class)
        ->set('branchId', $branch->id)
        ->set('authorizationCode', 'CODIGO-ERRADO')
        ->call('confirmAuthorization')
        ->assertHasErrors(['authorizationCode'])
        ->assertSet('connectionState', 'pending_authorization');

    $integration = IfoodIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first();
    expect($integration->merchant_id)->toBeNull();
});

test('pausar e retomar alteram o status sem tocar em token/merchant_id', function () {
    $company = ifoodSettingsTestCompany();
    $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();
    app()->instance('current.company', $company);

    IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'merchant_id' => 'merchant-abc',
        'access_token' => 'access-abc',
        'refresh_token' => 'refresh-abc',
        'status' => 'active',
    ]);

    $admin = ifoodSettingsAdmin($company);

    Livewire::actingAs($admin)
        ->test(IfoodIntegrationSettings::class)
        ->set('branchId', $branch->id)
        ->call('pause')
        ->assertSet('status', 'paused');

    $integration = IfoodIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first();
    expect($integration->status)->toBe('paused')
        ->and($integration->merchant_id)->toBe('merchant-abc')
        ->and($integration->access_token)->toBe('access-abc');

    Livewire::actingAs($admin)
        ->test(IfoodIntegrationSettings::class)
        ->set('branchId', $branch->id)
        ->call('resume')
        ->assertSet('status', 'active');
});

test('desconectar limpa tokens e merchant_id e volta pro estado não conectado', function () {
    $company = ifoodSettingsTestCompany();
    $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();
    app()->instance('current.company', $company);

    IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'merchant_id' => 'merchant-abc',
        'access_token' => 'access-abc',
        'refresh_token' => 'refresh-abc',
        'status' => 'active',
    ]);

    $admin = ifoodSettingsAdmin($company);

    Livewire::actingAs($admin)
        ->test(IfoodIntegrationSettings::class)
        ->set('branchId', $branch->id)
        ->call('disconnect')
        ->assertSet('connectionState', 'not_connected');

    $integration = IfoodIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first();
    expect($integration->status)->toBe('disconnected')
        ->and($integration->merchant_id)->toBeNull()
        ->and($integration->access_token)->toBeNull()
        ->and($integration->refresh_token)->toBeNull();
});

test('confirmar autorização com mais de um merchant mostra tela de escolha de loja', function () {
    $company = ifoodSettingsTestCompany();
    $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();
    app()->instance('current.company', $company);

    IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'merchant_id' => null,
        'status' => 'disconnected',
        'user_code' => 'ABCD-1234',
        'authorization_code_verifier' => 'verifier-xyz',
        'user_code_expires_at' => now()->addMinutes(5),
    ]);

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response([
            'accessToken' => 'access-final',
            'refreshToken' => 'refresh-final',
            'expiresIn' => 21600,
        ], 200),
        '*/merchant/v1.0/merchants' => Http::response([
            ['id' => 'merchant-a', 'name' => 'Loja Centro'],
            ['id' => 'merchant-b', 'name' => 'Loja Zona Sul'],
        ], 200),
    ]);
    Bus::fake([SyncIfoodCatalogJob::class]);

    $admin = ifoodSettingsAdmin($company);

    Livewire::actingAs($admin)
        ->test(IfoodIntegrationSettings::class)
        ->set('branchId', $branch->id)
        ->set('authorizationCode', 'HTLM-KWVR')
        ->call('confirmAuthorization')
        ->assertSet('connectionState', 'pending_merchant_selection')
        ->assertSet('availableMerchants', [
            ['id' => 'merchant-a', 'name' => 'Loja Centro'],
            ['id' => 'merchant-b', 'name' => 'Loja Zona Sul'],
        ]);

    $integration = IfoodIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first();
    expect($integration->merchant_id)->toBeNull()
        ->and($integration->status)->toBe('disconnected')
        ->and($integration->access_token)->toBe('access-final');

    // Ainda não escolheu a loja — não sincroniza cardápio de propósito nenhum ainda.
    Bus::assertNotDispatched(SyncIfoodCatalogJob::class);
});

test('escolher a loja confirma o merchant e ativa a integração', function () {
    $company = ifoodSettingsTestCompany();
    $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();
    app()->instance('current.company', $company);

    IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'merchant_id' => null,
        'available_merchants' => [
            ['id' => 'merchant-a', 'name' => 'Loja Centro'],
            ['id' => 'merchant-b', 'name' => 'Loja Zona Sul'],
        ],
        'access_token' => 'access-final',
        'refresh_token' => 'refresh-final',
        'status' => 'disconnected',
    ]);

    Bus::fake([SyncIfoodCatalogJob::class]);
    $admin = ifoodSettingsAdmin($company);

    Livewire::actingAs($admin)
        ->test(IfoodIntegrationSettings::class)
        ->set('branchId', $branch->id)
        ->set('selectedMerchantId', 'merchant-b')
        ->call('selectMerchant')
        ->assertSet('connectionState', 'connected')
        ->assertSet('merchantId', 'merchant-b');

    $integration = IfoodIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first();
    expect($integration->merchant_id)->toBe('merchant-b')
        ->and($integration->available_merchants)->toBeNull()
        ->and($integration->status)->toBe('active');

    Bus::assertDispatched(SyncIfoodCatalogJob::class, fn ($job) => $job->branchId === $branch->id);
});

test('sincronizar cardápio agora dispara o job pra filial conectada', function () {
    $company = ifoodSettingsTestCompany();
    $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();
    app()->instance('current.company', $company);

    IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'merchant_id' => 'merchant-abc',
        'access_token' => 'access-abc',
        'refresh_token' => 'refresh-abc',
        'status' => 'active',
    ]);

    Bus::fake([SyncIfoodCatalogJob::class]);
    $admin = ifoodSettingsAdmin($company);

    Livewire::actingAs($admin)
        ->test(IfoodIntegrationSettings::class)
        ->set('branchId', $branch->id)
        ->call('syncCatalogNow');

    Bus::assertDispatched(SyncIfoodCatalogJob::class, fn ($job) => $job->branchId === $branch->id);
});
