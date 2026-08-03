<?php

use App\Livewire\Admin\Fiscal\Config;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fiscalConfigTestCompany(): Company
{
    $company = Company::create([
        'name' => 'Empresa Config Fiscal',
        'slug' => 'empresa-config-fiscal-'.uniqid(),
        'order_prefix' => 'CFG',
        'active' => true,
        'status' => 'ACTIVE',
        'fiscal_notes_enabled' => true,
        'owner_cpf_cnpj' => '12345678000199',
        'email' => 'empresa@test.com',
    ]);

    // Filial nasce sem endereço no onboarding real (preenchimento é feito depois em
    // /admin/filiais) — Config::save() agora exige endereço completo antes de
    // registrar na Focus NFe, então os testes precisam de uma filial já preenchida.
    \App\Models\Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Principal',
        'address' => 'Rua A',
        'number' => '100',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'state' => 'SP',
        'cep' => '01310-000',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    return $company;
}

function fakeFocusNfeEmpresaCreated(): void
{
    Http::fake([
        '*/v2/empresas' => Http::response([
            'id' => 'focus-empresa-1',
            'token_producao' => 'tok-prod-1',
            'token_homologacao' => 'tok-homolog-1',
        ], 201),
    ]);
}

function fakeFiscalCertificate(string $password = 'senha123'): UploadedFile
{
    // Config::save() agora valida o .pfx localmente com openssl_pkcs12_read antes de
    // mandar pra Focus (proteção contra senha errada só ser descoberta no round-trip
    // com a API) — um arquivo com bytes aleatórios (UploadedFile::fake()->create())
    // não é mais aceito, então os testes precisam de um PKCS12 de verdade.
    $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'Empresa Teste Fiscal'], $privateKey);
    $cert = openssl_csr_sign($csr, null, $privateKey, 365);

    openssl_pkcs12_export($cert, $pkcs12, $privateKey, $password);

    return UploadedFile::fake()->createWithContent('certificado.pfx', $pkcs12);
}

function grantFiscalSettingsPermission(User $user, Company $company): void
{
    $permission = Permission::firstOrCreate(
        ['name' => 'fiscal.settings'],
        ['group' => 'fiscal', 'label' => 'Configurações fiscais'],
    );

    UserPermission::create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'permission_id' => $permission->id,
        'granted' => true,
    ]);
}

test('usuário com permissão fiscal.settings salva configuração fiscal', function () {
    fakeFocusNfeEmpresaCreated();

    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->assertSet('canManage', true)
        ->set('enabled', true)
        ->set('crt', 3)
        ->set('inscricaoEstadual', '123456789')
        ->set('environment', 'producao')
        ->set('nfceSerie', '2')
        ->set('providerToken', 'meu-token-secreto')
        ->call('save')
        ->assertHasNoErrors();

    $config = CompanyFiscalConfig::where('company_id', $company->id)->first();

    expect($config)->not->toBeNull();
    expect($config->enabled)->toBeTrue();
    expect($config->crt)->toBe(3);
    expect($config->inscricao_estadual)->toBe('123456789');
    expect($config->environment)->toBe('producao');
    expect($config->nfce_serie)->toBe(2);
    expect($config->provider_token)->toBe('meu-token-secreto');
});

test('token não é sobrescrito quando campo fica em branco no reenvio', function () {
    fakeFocusNfeEmpresaCreated();

    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    CompanyFiscalConfig::create([
        'company_id' => $company->id,
        'enabled' => true,
        'provider_token' => 'token-existente',
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->assertSet('hasProviderToken', true)
        ->assertSet('providerToken', '')
        ->set('crt', 2)
        ->call('save')
        ->assertHasNoErrors();

    expect(CompanyFiscalConfig::where('company_id', $company->id)->first()->provider_token)
        ->toBe('token-existente');
});

test('salvar com enabled=true registra empresa na Focus NFe (POST) e persiste tokens retornados', function () {
    fakeFocusNfeEmpresaCreated();

    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('enabled', true)
        ->set('certificateFile', fakeFiscalCertificate('senha123'))
        ->set('certificatePassword', 'senha123')
        ->call('save')
        ->assertHasNoErrors();

    $config = CompanyFiscalConfig::where('company_id', $company->id)->first();

    expect($config->focus_nfe_company_id)->toBe('focus-empresa-1');
    expect($config->token_producao)->toBe('tok-prod-1');
    expect($config->token_homologacao)->toBe('tok-homolog-1');
    expect($config->focus_nfe_registered_at)->not->toBeNull();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($company) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/v2/empresas')
            && ($request['cnpj'] ?? null) === '12345678000199'
            && ($request['nome'] ?? null) === $company->name
            && isset($request['arquivo_certificado_base64'])
            && ($request['senha_certificado'] ?? null) === 'senha123';
    });
});

test('salvar novamente com empresa já registrada dispara PUT em vez de POST', function () {
    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    CompanyFiscalConfig::create([
        'company_id' => $company->id,
        'enabled' => true,
        'focus_nfe_company_id' => 'focus-empresa-1',
    ]);

    Http::fake([
        '*/v2/empresas/focus-empresa-1' => Http::response([
            'id' => 'focus-empresa-1',
            'token_producao' => 'tok-prod-2',
            'token_homologacao' => 'tok-homolog-2',
        ], 200),
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('crt', 3)
        ->call('save')
        ->assertHasNoErrors();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'PUT' && str_contains($request->url(), '/v2/empresas/focus-empresa-1');
    });

    $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
    expect($config->token_producao)->toBe('tok-prod-2');
});

test('erro 422 da Focus NFe (certificado inválido) impede salvar e mostra erro no formulário', function () {
    Http::fake([
        '*/v2/empresas' => Http::response(['mensagem' => 'Certificado inválido ou senha incorreta'], 422),
    ]);

    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('enabled', true)
        // Senha bate com o certificado (passa na validação local) — o 422 simulado
        // representa uma rejeição do lado da Focus (ex.: cert revogado/expirado),
        // não senha incorreta, que já é barrada antes de qualquer chamada à API.
        ->set('certificateFile', fakeFiscalCertificate('senha123'))
        ->set('certificatePassword', 'senha123')
        ->call('save')
        ->assertHasErrors(['certificateFile']);

    expect(CompanyFiscalConfig::where('company_id', $company->id)->first())->toBeNull();
});

test('falha de conexão com Focus NFe não persiste config quando enabled=true', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('timeout');
    });

    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('enabled', true)
        ->call('save')
        ->assertHasErrors(['enabled']);

    expect(CompanyFiscalConfig::where('company_id', $company->id)->first())->toBeNull();
});

test('desabilitar módulo fiscal não chama Focus NFe e sempre salva', function () {
    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    CompanyFiscalConfig::create([
        'company_id' => $company->id,
        'enabled' => true,
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Http::fake();

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('enabled', false)
        ->call('save')
        ->assertHasNoErrors();

    Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request) => str_contains($request->url(), '/v2/empresas'));

    expect(CompanyFiscalConfig::where('company_id', $company->id)->first()->enabled)->toBeFalse();
});

test('usuário sem permissão fiscal.settings não consegue salvar', function () {
    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $manager = User::factory()->create();
    $manager->companies()->attach($company->id, ['role' => 'branch_manager']);

    Livewire::actingAs($manager)
        ->test(Config::class)
        ->assertSet('canManage', false)
        ->call('save')
        ->assertForbidden();
});

test('upload de certificado salva arquivo no disco privado', function () {
    \Illuminate\Support\Facades\Storage::fake('local');

    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('certificateFile', fakeFiscalCertificate('senha123'))
        ->set('certificatePassword', 'senha123')
        ->call('save')
        ->assertHasNoErrors();

    $config = CompanyFiscalConfig::where('company_id', $company->id)->first();

    expect($config->certificate_path)->not->toBeNull();
    expect($config->certificate_password)->toBe('senha123');
    \Illuminate\Support\Facades\Storage::disk('local')->assertExists($config->certificate_path);
});

test('CNPJ fica editável e persistido enquanto a empresa não foi registrada na Focus NFe', function () {
    $company = Company::create([
        'name' => 'Empresa Sem CNPJ',
        'slug' => 'empresa-sem-cnpj-'.uniqid(),
        'order_prefix' => 'SCJ',
        'active' => true,
        'status' => 'ACTIVE',
        'fiscal_notes_enabled' => true,
    ]);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->assertSet('hasOwnerCpfCnpj', false)
        ->set('ownerCpfCnpj', '12.345.678/0001-99')
        ->call('save')
        ->assertHasNoErrors()
        // Sem registrar a empresa na Focus NFe (enabled=true não foi acionado), o
        // campo continua editável — um CNPJ digitado errado não deveria travar pra
        // sempre exigindo suporte antes de a Focus sequer conhecer esse emissor.
        ->assertSet('hasOwnerCpfCnpj', false);

    expect($company->fresh()->owner_cpf_cnpj)->toBe('12345678000199');

    // Rebinda a empresa recém-carregada — o objeto anterior guarda em cache a
    // relação fiscalConfig como null (era null no primeiro mount()), e reusá-lo
    // faria o segundo save() tentar criar outro CompanyFiscalConfig pro mesmo company_id.
    app()->instance('current.company', $company->fresh());

    // Corrige o CNPJ numa segunda submissão — segue destravado.
    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('ownerCpfCnpj', '98.765.432/0001-10')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('hasOwnerCpfCnpj', false);

    expect($company->fresh()->owner_cpf_cnpj)->toBe('98765432000110');
});

test('CNPJ trava para edição depois que a empresa é registrada na Focus NFe', function () {
    fakeFocusNfeEmpresaCreated();

    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->assertSet('hasOwnerCpfCnpj', false)
        ->set('enabled', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('hasOwnerCpfCnpj', true);
});

test('CNPJ inválido (menos de 14 dígitos) mostra erro e não salva nada', function () {
    $company = Company::create([
        'name' => 'Empresa CNPJ Inválido',
        'slug' => 'empresa-cnpj-invalido-'.uniqid(),
        'order_prefix' => 'INV',
        'active' => true,
        'status' => 'ACTIVE',
        'fiscal_notes_enabled' => true,
    ]);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('ownerCpfCnpj', '123')
        ->call('save')
        ->assertHasErrors(['ownerCpfCnpj']);

    expect($company->fresh()->owner_cpf_cnpj)->toBeNull();
    expect(CompanyFiscalConfig::where('company_id', $company->id)->first())->toBeNull();
});

test('salvar envia inscrição municipal, CSC e telefone/complemento da filial no registro da Focus NFe', function () {
    fakeFocusNfeEmpresaCreated();

    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    \App\Models\Branch::withoutGlobalScopes()->where('company_id', $company->id)->first()->update([
        'address' => 'Rua B',
        'number' => '200',
        'complement' => 'Sala 3',
        'phone' => '11988887777',
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('enabled', true)
        ->set('inscricaoMunicipal', '987654')
        ->set('cscNfceProducao', 'meu-csc-secreto')
        ->set('idTokenNfceProducao', '000002')
        ->call('save')
        ->assertHasNoErrors();

    $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
    expect($config->inscricao_municipal)->toBe('987654');
    expect($config->csc_nfce_producao)->toBe('meu-csc-secreto');
    expect($config->id_token_nfce_producao)->toBe('000002');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), '/v2/empresas')
            && ($request['inscricao_municipal'] ?? null) === '987654'
            && ($request['csc_nfce_producao'] ?? null) === 'meu-csc-secreto'
            && ($request['id_token_nfce_producao'] ?? null) === '000002'
            && ($request['complemento'] ?? null) === 'Sala 3'
            && ($request['telefone'] ?? null) === '11988887777';
    });
});

test('com mais de uma filial, usuário escolhe qual delas envia o endereço pro registro na Focus NFe', function () {
    fakeFocusNfeEmpresaCreated();

    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $firstBranch = \App\Models\Branch::withoutGlobalScopes()->where('company_id', $company->id)->first();

    $secondBranch = \App\Models\Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Zona Sul',
        'address' => 'Rua da Filial Dois',
        'number' => '300',
        'neighborhood' => 'Vila Mariana',
        'city' => 'São Paulo',
        'state' => 'SP',
        'cep' => '04010-000',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->assertSet('branchId', $firstBranch->id)
        ->set('branchId', $secondBranch->id)
        ->assertSet('branchAddress', 'Rua da Filial Dois')
        ->set('enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(CompanyFiscalConfig::where('company_id', $company->id)->first()->branch_id)->toBe($secondBranch->id);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), '/v2/empresas')
            && ($request['logradouro'] ?? null) === 'Rua da Filial Dois'
            && ($request['municipio'] ?? null) === 'São Paulo';
    });
});

test('branchId de filial de outra empresa é ignorado ao salvar', function () {
    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $otherCompany = Company::create([
        'name' => 'Outra Empresa',
        'slug' => 'outra-empresa-'.uniqid(),
        'order_prefix' => 'OTR',
        'active' => true,
        'status' => 'ACTIVE',
    ]);

    $foreignBranch = \App\Models\Branch::withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Filial de Outra Empresa',
        'address' => 'Rua Estranha',
        'number' => '1',
        'neighborhood' => 'Centro',
        'city' => 'Rio de Janeiro',
        'state' => 'RJ',
        'cep' => '20000-000',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('branchId', $foreignBranch->id)
        ->set('enabled', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(CompanyFiscalConfig::where('company_id', $company->id)->first()->branch_id)->not->toBe($foreignBranch->id);
});

test('registro na Focus NFe sempre bate no domínio de produção, mesmo fora de APP_ENV=production', function () {
    fakeFocusNfeEmpresaCreated();

    config(['fiscal.focus_nfe.base_url_producao' => 'https://api.focusnfe.com.br']);
    config(['fiscal.focus_nfe.base_url_homologacao' => 'https://homologacao.focusnfe.com.br']);

    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('enabled', true)
        ->set('environment', 'homologacao')
        ->call('save')
        ->assertHasNoErrors();

    // /v2/empresas é gestão de conta, só existe no domínio de produção da Focus —
    // mesmo com a empresa configurada pra emitir em homologação, o cadastro tem que ir pra lá.
    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), 'api.focusnfe.com.br/v2/empresas')
            && ! str_contains($request->url(), 'homologacao');
    });
});

test('webhook não é registrado fora de ambiente de produção', function () {
    fakeFocusNfeEmpresaCreated();

    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request) => str_contains($request->url(), '/v2/hooks'));

    expect(CompanyFiscalConfig::where('company_id', $company->id)->first()->focus_nfe_webhook_id)->toBeNull();
});

test('salvar em produção registra webhook (POST /v2/hooks) quando ainda não existe', function () {
    app()->instance('env', 'production');

    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Http::fake([
        '*/v2/empresas' => Http::response([
            'id' => 'focus-empresa-1',
            'token_producao' => 'tok-prod-1',
            'token_homologacao' => 'tok-homolog-1',
        ], 201),
        '*/v2/hooks' => Http::sequence()
            ->push([], 200) // GET /v2/hooks — lista vazia
            ->push(['id' => 'hook-1'], 201), // POST /v2/hooks — criado
    ]);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(CompanyFiscalConfig::where('company_id', $company->id)->first()->focus_nfe_webhook_id)->toBe('hook-1');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/v2/hooks')
            && ($request['event'] ?? null) === 'nfe'
            && ($request['cnpj'] ?? null) === '12345678000199'
            && ($request['url'] ?? null) === route('webhook.fiscal', absolute: true);
    });
});

test('salvar em produção não recria webhook quando já existe um pro cnpj+evento', function () {
    app()->instance('env', 'production');

    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    CompanyFiscalConfig::create([
        'company_id' => $company->id,
        'enabled' => true,
        'focus_nfe_company_id' => 'focus-empresa-1',
    ]);

    Http::fake([
        '*/v2/empresas/focus-empresa-1' => Http::response([
            'id' => 'focus-empresa-1',
            'token_producao' => 'tok-prod-2',
            'token_homologacao' => 'tok-homolog-2',
        ], 200),
        '*/v2/hooks' => Http::response([
            ['id' => 'hook-existente', 'cnpj' => '12345678000199', 'event' => 'nfe'],
        ], 200),
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('crt', 3)
        ->call('save')
        ->assertHasNoErrors();

    expect(CompanyFiscalConfig::where('company_id', $company->id)->first()->focus_nfe_webhook_id)->toBe('hook-existente');

    Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/v2/hooks'));
});

test('falha ao registrar webhook não impede salvar a configuração fiscal', function () {
    app()->instance('env', 'production');

    Http::fake([
        '*/v2/empresas' => Http::response([
            'id' => 'focus-empresa-1',
            'token_producao' => 'tok-prod-1',
            'token_homologacao' => 'tok-homolog-1',
        ], 201),
        '*/v2/hooks' => Http::response(['mensagem' => 'Erro interno'], 500),
    ]);

    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    $config = CompanyFiscalConfig::where('company_id', $company->id)->first();
    expect($config)->not->toBeNull();
    expect($config->focus_nfe_company_id)->toBe('focus-empresa-1');
    expect($config->focus_nfe_webhook_id)->toBeNull();
});
