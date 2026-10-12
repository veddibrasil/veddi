<?php

use App\Jobs\PollIfoodEventsJob;
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
    Bus::fake([SyncIfoodCatalogJob::class, PollIfoodEventsJob::class]);

    $admin = ifoodSettingsAdmin($company);

    Livewire::actingAs($admin)
        ->test(IfoodIntegrationSettings::class)
        ->set('branchId', $branch->id)
        ->set('authorizationCode', 'HTLM-KWVR')
        ->call('confirmAuthorization')
        ->assertSet('connectionState', 'connected')
        ->assertSet('merchantId', 'merchant-abc')
        ->assertSet('merchantName', 'Loja Teste')
        ->assertSet('tab', 'cardapio')
        ->assertSee('Publicar cardápio no iFood');

    // Loja nova: o cardápio só vai pro iFood depois que o restaurante revisa e publica
    // (evita duplicar o cardápio de quem já vendia no iFood). A presença começa na hora.
    Bus::assertNotDispatched(SyncIfoodCatalogJob::class);
    Bus::assertDispatched(PollIfoodEventsJob::class);
});

test('reconectar loja com cardápio já publicado volta a sincronizar na hora', function () {
    $company = ifoodSettingsTestCompany();
    $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();
    app()->instance('current.company', $company);

    IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'status' => 'disconnected',
        'user_code' => 'ABCD-1234',
        'authorization_code_verifier' => 'verifier-xyz',
        'user_code_expires_at' => now()->addMinutes(5),
        'catalog_id' => 'catalog-1',
        'catalog_merchant_id' => 'merchant-abc',
        'catalog_published_at' => now()->subDay(),
    ]);

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response(['accessToken' => 'a', 'refreshToken' => 'r', 'expiresIn' => 21600]),
        '*/merchant/v1.0/merchants' => Http::response([['id' => 'merchant-abc', 'name' => 'Loja Teste']]),
    ]);
    Bus::fake([SyncIfoodCatalogJob::class, PollIfoodEventsJob::class]);

    Livewire::actingAs(ifoodSettingsAdmin($company))
        ->test(IfoodIntegrationSettings::class)
        ->set('authorizationCode', 'HTLM-KWVR')
        ->call('confirmAuthorization')
        ->assertSet('connectionState', 'connected');

    Bus::assertDispatched(SyncIfoodCatalogJob::class, fn ($job) => $job->branchId === $branch->id);
    expect(IfoodIntegration::first()->catalog_id)->toBe('catalog-1');
});

test('trocar a loja da filial descarta o mapeamento do cardápio da loja anterior', function () {
    $ctx = ifoodContext('troca-loja');
    \Illuminate\Support\Facades\DB::table('ifood_categories')->insert([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
        'product_category_id' => $ctx['category']->id, 'ifood_category_id' => 'cat-old',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $ctx['integration']->update([
        'merchant_id' => null, 'status' => 'disconnected', 'access_token' => 'a', 'refresh_token' => 'r',
        'token_expires_at' => now()->addHour(), 'catalog_id' => 'catalog-old', 'catalog_merchant_id' => 'merchant-old',
        'available_merchants' => [['id' => 'merchant-new', 'name' => 'Loja real'], ['id' => 'merchant-x', 'name' => 'Outra']],
    ]);

    app(\App\Services\Ifood\IfoodAuthService::class)->selectMerchant($ctx['integration']->fresh(), 'merchant-new');

    $integration = $ctx['integration']->fresh();
    expect($integration->merchant_id)->toBe('merchant-new')
        ->and($integration->merchant_name)->toBe('Loja real')
        ->and($integration->catalog_id)->toBeNull()
        ->and($integration->catalog_published_at)->toBeNull()
        ->and(\Illuminate\Support\Facades\DB::table('ifood_categories')->where('branch_id', $ctx['branch']->id)->count())->toBe(0)
        ->and(\Illuminate\Support\Facades\DB::table('branch_product')->where('branch_id', $ctx['branch']->id)->value('ifood_item_id'))->toBeNull();
});

test('falha na autorização mostra a mensagem correspondente uma vez e mantém pendente', function (int $status, array $response, string $message) {
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
        '*/authentication/v1.0/oauth/token' => Http::response($response, $status),
    ]);

    $admin = ifoodSettingsAdmin($company);

    Livewire::actingAs($admin)
        ->test(IfoodIntegrationSettings::class)
        ->set('branchId', $branch->id)
        ->set('authorizationCode', 'CODIGO-ERRADO')
        ->call('confirmAuthorization')
        ->assertHasErrors(['authorizationCode'])
        ->assertSeeText($message)
        ->assertSeeHtml('aria-invalid="true"')
        ->tap(fn ($component) => expect(substr_count($component->html(), $message))->toBe(1))
        ->assertSet('connectionState', 'pending_authorization');

    $integration = IfoodIntegration::where('company_id', $company->id)->where('branch_id', $branch->id)->first();
    expect($integration->merchant_id)->toBeNull();
})->with([
    'código rejeitado' => [401, ['error' => ['code' => 'Unauthorized', 'message' => 'Invalid authorization code']], 'Código inválido ou expirado. Confira o que o iFood mostrou e tente de novo.'],
    'credenciais rejeitadas' => [401, ['error' => ['message' => 'Invalid client credentials']], 'Não foi possível concluir a conexão com o iFood. Tente novamente em instantes. Se o problema persistir, reinicie a conexão.'],
    'serviço indisponível' => [503, [], 'Não foi possível concluir a conexão com o iFood. Tente novamente em instantes. Se o problema persistir, reinicie a conexão.'],
]);

test('consulta de lojas pode ser repetida sem trocar o código novamente', function (int $status, string $message) {
    $company = ifoodSettingsTestCompany();
    $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();
    app()->instance('current.company', $company);
    $integration = IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'status' => 'disconnected',
        'user_code' => 'ABCD-1234',
        'authorization_code_verifier' => 'verifier-xyz',
        'user_code_expires_at' => now()->addMinutes(5),
    ]);
    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response([
            'accessToken' => 'saved-access', 'refreshToken' => 'saved-refresh', 'expiresIn' => 21600,
        ]),
        '*/merchant/v1.0/merchants' => Http::sequence()->push([], $status)->push([
            ['id' => 'merchant-recovered', 'name' => 'Loja'],
        ]),
    ]);
    Bus::fake([SyncIfoodCatalogJob::class, PollIfoodEventsJob::class]);
    $admin = ifoodSettingsAdmin($company);
    Livewire::actingAs($admin)->test(IfoodIntegrationSettings::class)
        ->set('authorizationCode', 'HTLM-KWVR')
        ->call('confirmAuthorization')
        ->assertSet('connectionState', 'pending_merchant_selection')
        ->assertSeeText($message)
        ->assertSeeText('Consultar lojas novamente');

    expect($integration->refresh()->access_token)->toBe('saved-access')
        ->and($integration->refresh_token)->toBe('saved-refresh')
        ->and($integration->user_code)->toBeNull()
        ->and($integration->merchant_id)->toBeNull();
    Bus::assertNotDispatched(SyncIfoodCatalogJob::class);

    // Uma nova visita também deve recuperar a autorização persistida.
    Livewire::actingAs($admin)->test(IfoodIntegrationSettings::class)
        ->assertSet('connectionState', 'pending_merchant_selection')
        ->call('confirmAuthorization')
        ->assertSet('connectionState', 'connected')
        ->assertSet('merchantId', 'merchant-recovered')
        ->assertDontSeeText($message);
    Http::assertSentCount(3);
    Bus::assertNotDispatched(SyncIfoodCatalogJob::class);
    Bus::assertDispatched(PollIfoodEventsJob::class);
})->with([
    'nenhuma loja autorizada' => [200, 'O iFood aceitou a autorização, mas não retornou nenhuma loja.'],
    'falha na consulta' => [503, 'A autorização foi salva, mas não foi possível consultar as lojas no iFood.'],
]);

/** JWT sem assinatura válida — o serviço só lê o claim merchant_scope. */
function ifoodSettingsTestJwt(array $merchantScope): string
{
    $encode = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

    return $encode(['alg' => 'HS256']).'.'.$encode(['merchant_scope' => $merchantScope]).'.assinatura';
}

test('lista de lojas vazia mas token com merchant_scope ativa a integração pela loja do token', function () {
    $company = ifoodSettingsTestCompany();
    $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();
    app()->instance('current.company', $company);

    $integration = IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'status' => 'disconnected',
        'user_code' => 'ABCD-1234',
        'authorization_code_verifier' => 'verifier-xyz',
        'user_code_expires_at' => now()->addMinutes(5),
    ]);

    // Autorização liberou só order/events: /merchants (módulo merchant) volta vazio.
    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response([
            'accessToken' => ifoodSettingsTestJwt(['merchant-from-token:order', 'merchant-from-token:events']),
            'refreshToken' => 'refresh-final',
            'expiresIn' => 21600,
        ], 200),
        '*/merchant/v1.0/merchants' => Http::response([], 200),
    ]);
    Bus::fake([SyncIfoodCatalogJob::class, PollIfoodEventsJob::class]);

    Livewire::actingAs(ifoodSettingsAdmin($company))
        ->test(IfoodIntegrationSettings::class)
        ->set('authorizationCode', 'HTLM-KWVR')
        ->call('confirmAuthorization')
        ->assertSet('connectionState', 'connected')
        ->assertSet('merchantId', 'merchant-from-token');

    expect($integration->refresh()->merchant_id)->toBe('merchant-from-token')
        ->and($integration->status)->toBe('active')
        ->and($integration->available_merchants)->toBeNull();
    Bus::assertDispatched(PollIfoodEventsJob::class);
});

test('lista de lojas vazia com mais de uma loja no merchant_scope pede escolha usando o id como nome', function () {
    $company = ifoodSettingsTestCompany();
    $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();
    app()->instance('current.company', $company);

    IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'status' => 'disconnected',
        'user_code' => 'ABCD-1234',
        'authorization_code_verifier' => 'verifier-xyz',
        'user_code_expires_at' => now()->addMinutes(5),
    ]);

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response([
            'accessToken' => ifoodSettingsTestJwt(['merchant-a:order', 'merchant-a:events', 'merchant-b:order']),
            'refreshToken' => 'refresh-final',
            'expiresIn' => 21600,
        ], 200),
        '*/merchant/v1.0/merchants' => Http::response([], 200),
    ]);
    Bus::fake([SyncIfoodCatalogJob::class, PollIfoodEventsJob::class]);

    Livewire::actingAs(ifoodSettingsAdmin($company))
        ->test(IfoodIntegrationSettings::class)
        ->set('authorizationCode', 'HTLM-KWVR')
        ->call('confirmAuthorization')
        ->assertSet('connectionState', 'pending_merchant_selection')
        ->assertSet('availableMerchants', [
            ['id' => 'merchant-a', 'name' => 'merchant-a'],
            ['id' => 'merchant-b', 'name' => 'merchant-b'],
        ]);

    Bus::assertNotDispatched(SyncIfoodCatalogJob::class);
});

test('lista de lojas do iFood tem prioridade sobre o merchant_scope do token', function () {
    $company = ifoodSettingsTestCompany();
    $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();
    app()->instance('current.company', $company);

    IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'status' => 'disconnected',
        'user_code' => 'ABCD-1234',
        'authorization_code_verifier' => 'verifier-xyz',
        'user_code_expires_at' => now()->addMinutes(5),
    ]);

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response([
            'accessToken' => ifoodSettingsTestJwt(['merchant-from-token:order']),
            'refreshToken' => 'refresh-final',
            'expiresIn' => 21600,
        ], 200),
        '*/merchant/v1.0/merchants' => Http::response([['id' => 'merchant-from-api', 'name' => 'Loja API']], 200),
    ]);
    Bus::fake([SyncIfoodCatalogJob::class, PollIfoodEventsJob::class]);

    Livewire::actingAs(ifoodSettingsAdmin($company))
        ->test(IfoodIntegrationSettings::class)
        ->set('authorizationCode', 'HTLM-KWVR')
        ->call('confirmAuthorization')
        ->assertSet('merchantId', 'merchant-from-api');
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
    Bus::fake([SyncIfoodCatalogJob::class, PollIfoodEventsJob::class]);

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

    Bus::fake([SyncIfoodCatalogJob::class, PollIfoodEventsJob::class]);
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
        ->and($integration->merchant_name)->toBe('Loja Zona Sul')
        ->and($integration->available_merchants)->toBeNull()
        ->and($integration->status)->toBe('active');

    Bus::assertNotDispatched(SyncIfoodCatalogJob::class);
    Bus::assertDispatched(PollIfoodEventsJob::class);
});

test('sincronizar cardápio agora conclui a operação antes de indicar sucesso', function () {
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

    $this->mock(\App\Services\Ifood\IfoodCatalogSyncService::class)->shouldReceive('syncFullCatalog')->once()
        ->andReturn(['synced' => 1, 'failed' => []]);
    $admin = ifoodSettingsAdmin($company);

    // A fila de teste é síncrona: o job roda dentro da chamada.
    Livewire::actingAs($admin)
        ->test(IfoodIntegrationSettings::class)
        ->set('branchId', $branch->id)
        ->call('syncCatalogNow')->assertSee('Publicação do cardápio iniciada')
        ->call('refreshCatalogStatus')->assertSet('catalogSyncStatus', 'done')->assertSet('catalogPublished', true);

    expect(IfoodIntegration::first()->catalog_published_at)->not->toBeNull();
});

test('publicação com produto problemático conclui os demais e mostra a pendência', function () {
    $company = ifoodSettingsTestCompany();
    $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();
    app()->instance('current.company', $company);
    IfoodIntegration::create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'merchant_id' => 'merchant-abc',
        'access_token' => 'access-abc', 'refresh_token' => 'refresh-abc', 'status' => 'active',
    ]);
    $this->mock(\App\Services\Ifood\IfoodCatalogSyncService::class)->shouldReceive('syncFullCatalog')->once()
        ->andReturn(['synced' => 3, 'failed' => [99 => 'Produto sem categoria.']]);

    Livewire::actingAs(ifoodSettingsAdmin($company))
        ->test(IfoodIntegrationSettings::class)
        ->call('syncCatalogNow')
        ->call('refreshCatalogStatus')
        ->assertSet('catalogSyncStatus', 'partial')
        ->assertSee('1 produto não foi enviado.');
});

test('autorização revogada exibe reconexão e não permite retomar sem autorizar', function () {
    $company = ifoodSettingsTestCompany();
    $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();
    app()->instance('current.company', $company);
    $integration = IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'merchant_id' => 'revoked-merchant',
        'status' => 'reauthorization_required',
    ]);

    Livewire::actingAs(ifoodSettingsAdmin($company))
        ->test(IfoodIntegrationSettings::class)
        ->assertSet('connectionState', 'reauthorization_required')
        ->assertSee('Reconectar iFood')
        ->call('resume')
        ->assertSet('connectionState', 'reauthorization_required');
    expect($integration->fresh()->status)->toBe('reauthorization_required');
});
