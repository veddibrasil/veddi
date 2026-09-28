<?php

use App\Jobs\CompleteWhatsAppOnboarding;
use App\Livewire\Admin\Settings\WhatsAppSettings;
use App\Models\Company;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use App\Services\Messaging\WhatsAppOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array{0: Company, 1: User} */
function whatsappUiCompany(string $role = 'company_admin'): array
{
    $company = Company::create([
        'name' => 'Empresa UI WhatsApp',
        'slug' => 'ui-whatsapp-'.uniqid(),
        'order_prefix' => 'UIW',
        'active' => true,
        'status' => 'ACTIVE',
    ]);

    app()->instance('current.company', $company);

    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => $role]);

    config([
        'services.meta.app_id' => '111222333',
        'services.whatsapp.es_config_id' => '444555666',
        'services.meta.graph_version' => 'v25.0',
    ]);

    return [$company, $user];
}

function whatsappUiConnection(Company $company, string $status = WhatsAppConnection::STATUS_ACTIVE, array $attributes = []): WhatsAppConnection
{
    return WhatsAppConnection::factory()->forCompany($company)->status($status)->create($attributes);
}

// ── Estados do card de conexão ────────────────────────────────────────────────

test('sem conexão mostra a explicação e o botão Conectar WhatsApp', function () {
    [, $admin] = whatsappUiCompany();

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->assertSee('Conectar WhatsApp')
        ->assertSee('cartão cadastrado no WhatsApp Manager')
        ->assertSee('Não conectado')
        ->assertDontSee('Enviar teste')
        ->assertDontSeeHtml('wire:poll');
});

test('o botão Conectar leva só ids públicos do app da Meta para o navegador', function () {
    [, $admin] = whatsappUiCompany();
    config(['services.meta.app_secret' => 'segredo-do-app', 'services.whatsapp.webhook_verify_token' => 'verify-secreto']);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->assertSeeHtml('whatsappSignup(')
        ->assertSeeHtml('111222333')
        ->assertSeeHtml('444555666')
        ->assertDontSeeHtml('segredo-do-app')
        ->assertDontSeeHtml('verify-secreto');
});

test('sem app da Meta configurado a tela avisa em vez de mostrar um botão que não funciona', function () {
    [, $admin] = whatsappUiCompany();
    config(['services.whatsapp.es_config_id' => null]);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->assertSee('ainda não foi configurada na plataforma')
        ->assertDontSee('Conectar WhatsApp');
});

test('conexão pendente mostra progresso e atualiza sozinha a cada 5 segundos', function () {
    [$company, $admin] = whatsappUiCompany();
    whatsappUiConnection($company, WhatsAppConnection::STATUS_PENDING);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->assertSee('Conectando com a Meta')
        ->assertSee('Cancelar conexão')
        ->assertSeeHtml('wire:poll.5s')
        ->assertDontSee('Conectar WhatsApp');
});

test('aguardando templates lista o status de cada template e atualiza a cada 30 segundos', function () {
    [$company, $admin] = whatsappUiCompany();
    $connection = whatsappUiConnection($company, WhatsAppConnection::STATUS_TEMPLATES_PENDING);

    WhatsAppTemplate::factory()->forEvent('new_order')->create(['whatsapp_connection_id' => $connection->id]);
    WhatsAppTemplate::factory()->forEvent('paid')->status(WhatsAppTemplate::STATUS_PENDING)->create(['whatsapp_connection_id' => $connection->id]);
    WhatsAppTemplate::factory()->forEvent('scheduled')->status(WhatsAppTemplate::STATUS_REJECTED)->create([
        'whatsapp_connection_id' => $connection->id,
        'rejection_reason' => 'INVALID_FORMAT',
    ]);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->assertSee('Aguardando a Meta aprovar as mensagens')
        ->assertSee('Pedido recebido')
        ->assertSee('Aprovado')
        ->assertSee('Em análise')
        ->assertSee('Rejeitado')
        ->assertSee('Motivo: INVALID_FORMAT')
        ->assertSee('Aguardando envio') // templates ainda não criados na Meta
        ->assertSeeHtml('wire:poll.30s');
});

test('conexão ativa mostra nome verificado, número, qualidade, tipo e as ações', function () {
    [$company, $admin] = whatsappUiCompany();
    whatsappUiConnection($company, WhatsAppConnection::STATUS_ACTIVE, [
        'verified_name' => 'Coxinhas do Zé',
        'display_phone_number' => '+55 44 99999-1234',
        'quality_rating' => 'YELLOW',
        'messaging_limit_tier' => 'TIER_1K',
    ]);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->assertSee('Conectado')
        ->assertSee('Coxinhas do Zé')
        ->assertSee('+55 44 99999-1234')
        ->assertSee('Média')
        ->assertSee('Número novo (API oficial)')
        ->assertSee('1.000 clientes por dia')
        ->assertSee('Enviar teste')
        ->assertSee('Desconectar')
        ->assertDontSee('Mantenha o app WhatsApp Business aberto')
        ->assertDontSeeHtml('wire:poll');
});

test('conexão em coexistência é identificada e avisa sobre o app WhatsApp Business', function () {
    [$company, $admin] = whatsappUiCompany();
    WhatsAppConnection::factory()->forCompany($company)->coexistence()->create();

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->assertSee('Coexistência (com o app WhatsApp Business)')
        ->assertSee('Mantenha o app WhatsApp Business aberto');
});

test('conexão ativa com problema (ex.: cobrança) mostra o aviso sem esconder as ações', function () {
    [$company, $admin] = whatsappUiCompany();
    whatsappUiConnection($company, WhatsAppConnection::STATUS_ACTIVE, ['last_error' => 'Problema de pagamento na conta do WhatsApp Business (código 131042).']);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->assertSee('Problema de pagamento na conta do WhatsApp Business')
        ->assertSee('Desconectar');
});

test('conexão com erro mostra o motivo amigável e o botão Reconectar', function () {
    [$company, $admin] = whatsappUiCompany();
    whatsappUiConnection($company, WhatsAppConnection::STATUS_ERROR, ['last_error' => WhatsAppOnboardingService::MSG_NO_WABA]);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->assertSee('Com problema')
        ->assertSee(WhatsAppOnboardingService::MSG_NO_WABA)
        ->assertSee('Reconectar WhatsApp')
        ->assertDontSee('Enviar teste');
});

test('conexão desconectada oferece Reconectar', function () {
    [$company, $admin] = whatsappUiCompany();
    whatsappUiConnection($company, WhatsAppConnection::STATUS_DISCONNECTED, ['disconnected_at' => now()]);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->assertSee('O WhatsApp foi desconectado')
        ->assertSee('Reconectar WhatsApp');
});

test('a tela nunca leva token nem PIN da conexão ao navegador', function () {
    [$company, $admin] = whatsappUiCompany();
    $connection = whatsappUiConnection($company, WhatsAppConnection::STATUS_ACTIVE, [
        'access_token' => 'EAAB-token-ultra-secreto',
        'registration_pin' => '918273',
    ]);

    $component = Livewire::actingAs($admin)->test(WhatsAppSettings::class);

    $component->assertDontSeeHtml('EAAB-token-ultra-secreto')
        ->assertDontSeeHtml('918273');

    expect(json_encode($component->snapshot))->not->toContain('EAAB-token-ultra-secreto')
        ->and(json_encode($component->snapshot))->not->toContain('918273');
});

test('só a conexão da própria empresa aparece na tela', function () {
    [$company, $admin] = whatsappUiCompany();
    whatsappUiConnection($company, WhatsAppConnection::STATUS_DISCONNECTED);

    $outra = Company::create(['name' => 'Outra', 'slug' => 'outra-'.uniqid(), 'order_prefix' => 'OUT', 'active' => true]);
    WhatsAppConnection::factory()->forCompany($outra)->create(['verified_name' => 'Marca da Concorrência']);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->assertDontSee('Marca da Concorrência')
        ->assertSee('Reconectar WhatsApp');
});

test('todo template do config tem rótulo de evento em português para a lista de progresso', function () {
    foreach (array_keys(config('whatsapp_templates.templates')) as $event) {
        expect(WhatsAppTemplate::EVENT_LABELS)->toHaveKey($event);
    }
});

// ── Toggles ───────────────────────────────────────────────────────────────────

test('sem conexão ativa os toggles ficam bloqueados com explicação', function () {
    [$company, $admin] = whatsappUiCompany();
    whatsappUiConnection($company, WhatsAppConnection::STATUS_TEMPLATES_PENDING);

    $component = Livewire::actingAs($admin)->test(WhatsAppSettings::class);

    $component->assertSee('As notificações só podem ser ligadas depois que o WhatsApp estiver conectado e ativo');
    expect($component->instance()->canConfigure)->toBeFalse();
});

test('com conexão ativa os toggles são liberados', function () {
    [$company, $admin] = whatsappUiCompany();
    whatsappUiConnection($company, WhatsAppConnection::STATUS_ACTIVE);

    $component = Livewire::actingAs($admin)->test(WhatsAppSettings::class);

    $component->assertDontSee('As notificações só podem ser ligadas depois');
    expect($component->instance()->canConfigure)->toBeTrue();
});

test('com o fallback da plataforma ativo os toggles são liberados mesmo sem conexão', function () {
    [, $admin] = whatsappUiCompany();
    config([
        'services.whatsapp.fallback_to_platform' => true,
        'services.whatsapp.platform.phone_number_id' => '999000111',
        'services.whatsapp.platform.token' => 'token-da-plataforma',
    ]);

    $component = Livewire::actingAs($admin)->test(WhatsAppSettings::class);

    $component->assertDontSee('As notificações só podem ser ligadas depois');
    expect($component->instance()->canConfigure)->toBeTrue();
});

// ── completeSignup ────────────────────────────────────────────────────────────

test('completeSignup cria a conexão pendente e despacha o job com o code', function () {
    Queue::fake();
    [$company, $admin] = whatsappUiCompany();

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->call('completeSignup', 'code-de-uso-unico', '1000000000001', '2000000000001', 'cloud_api')
        ->assertSet('signupError', null)
        ->assertSee('Conectando com a Meta');

    $connection = WhatsAppConnection::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();

    expect($connection->status)->toBe(WhatsAppConnection::STATUS_PENDING)
        ->and($connection->waba_id)->toBe('1000000000001')
        ->and($connection->phone_number_id)->toBe('2000000000001')
        ->and($connection->onboarding_type)->toBe(WhatsAppConnection::TYPE_CLOUD_API);

    Queue::assertPushed(CompleteWhatsAppOnboarding::class, fn ($job) => $job->connectionId === $connection->id && $job->code === 'code-de-uso-unico');
});

test('completeSignup aceita coexistência sem ids (o serviço descobre pelo token)', function () {
    Queue::fake();
    [$company, $admin] = whatsappUiCompany();

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->call('completeSignup', 'code-coex', null, null, 'coexistence');

    $connection = WhatsAppConnection::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();

    expect($connection->onboarding_type)->toBe(WhatsAppConnection::TYPE_COEXISTENCE)
        ->and($connection->waba_id)->toBeNull();

    Queue::assertPushed(CompleteWhatsAppOnboarding::class);
});

test('completeSignup recusa quem não tem permissão de configurar a empresa', function () {
    Queue::fake();
    [$company, $manager] = whatsappUiCompany('branch_manager');

    Livewire::actingAs($manager)
        ->test(WhatsAppSettings::class)
        ->call('completeSignup', 'code', '1000000000001', '2000000000001', 'cloud_api')
        ->assertForbidden();

    expect(WhatsAppConnection::withoutGlobalScopes()->where('company_id', $company->id)->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('completeSignup valida code, ids e tipo antes de gravar qualquer coisa', function (string $code, ?string $waba, ?string $phone, string $type) {
    Queue::fake();
    [$company, $admin] = whatsappUiCompany();

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->call('completeSignup', $code, $waba, $phone, $type)
        ->assertSet('signupError', 'Os dados recebidos da Meta são inválidos. Clique em "Conectar WhatsApp" para refazer.');

    expect(WhatsAppConnection::withoutGlobalScopes()->where('company_id', $company->id)->exists())->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'code vazio' => ['', '1000000000001', '2000000000001', 'cloud_api'],
    'waba não numérica' => ['code', '10000; DROP TABLE', '2000000000001', 'cloud_api'],
    'número não numérico' => ['code', '1000000000001', 'abc', 'cloud_api'],
    'tipo desconhecido' => ['code', '1000000000001', '2000000000001', 'embedded'],
]);

test('completeSignup mostra a mensagem do serviço quando a WABA já está em outra empresa', function () {
    Queue::fake();
    [$company, $admin] = whatsappUiCompany();

    $outra = Company::create(['name' => 'Outra', 'slug' => 'outra-'.uniqid(), 'order_prefix' => 'OUT', 'active' => true]);
    $connectionOutra = WhatsAppConnection::factory()->forCompany($outra)->create(['waba_id' => '1000000000001']);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->call('completeSignup', 'code', '1000000000001', '2000000000001', 'cloud_api')
        ->assertSet('signupError', WhatsAppOnboardingService::MSG_ALREADY_LINKED)
        ->assertSee(WhatsAppOnboardingService::MSG_ALREADY_LINKED);

    expect($connectionOutra->fresh()->status)->toBe(WhatsAppConnection::STATUS_ACTIVE)
        ->and(WhatsAppConnection::withoutGlobalScopes()->where('company_id', $company->id)->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('completeSignup não recomeça uma conexão ativa nem em andamento', function (string $status) {
    Queue::fake();
    [$company, $admin] = whatsappUiCompany();
    $connection = whatsappUiConnection($company, $status, ['access_token' => 'token-que-nao-pode-sumir']);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->call('completeSignup', 'code-novo', '1000000000009', '2000000000009', 'cloud_api')
        ->assertSet('signupError', 'Já existe uma conexão de WhatsApp em andamento ou ativa. Desconecte-a antes de conectar outro número.');

    expect($connection->fresh()->status)->toBe($status)
        ->and($connection->fresh()->access_token)->toBe('token-que-nao-pode-sumir');
    Queue::assertNothingPushed();
})->with([
    'ativa' => WhatsAppConnection::STATUS_ACTIVE,
    'pendente' => WhatsAppConnection::STATUS_PENDING,
    'provisionando' => WhatsAppConnection::STATUS_PROVISIONING,
    'aguardando templates' => WhatsAppConnection::STATUS_TEMPLATES_PENDING,
]);

test('completeSignup permite reconectar depois de erro ou desconexão', function (string $status) {
    Queue::fake();
    [$company, $admin] = whatsappUiCompany();
    $connection = whatsappUiConnection($company, $status, ['last_error' => 'erro antigo']);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->call('completeSignup', 'code-novo', '1000000000001', '2000000000001', 'cloud_api')
        ->assertSet('signupError', null);

    expect($connection->fresh()->status)->toBe(WhatsAppConnection::STATUS_PENDING)
        ->and($connection->fresh()->last_error)->toBeNull();
    Queue::assertPushed(CompleteWhatsAppOnboarding::class);
})->with([
    'erro' => WhatsAppConnection::STATUS_ERROR,
    'desconectada' => WhatsAppConnection::STATUS_DISCONNECTED,
]);

// ── Desconectar ───────────────────────────────────────────────────────────────

test('desconectar cancela a inscrição na Meta, descarta o token e mostra o botão Reconectar', function () {
    whatsappFakeMeta();
    [$company, $admin] = whatsappUiCompany();
    $connection = whatsappUiConnection($company);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->call('confirmDisconnect')
        ->assertSet('showDisconnectModal', true)
        ->call('disconnect')
        ->assertSet('showDisconnectModal', false)
        ->assertSee('WhatsApp desconectado')
        ->assertSee('Reconectar WhatsApp');

    $connection->refresh();

    expect($connection->status)->toBe(WhatsAppConnection::STATUS_DISCONNECTED)
        ->and($connection->access_token)->toBeNull()
        ->and(whatsappMetaCalls())->toBe(['DELETE subscribed_apps']);
});

test('desconectar também cancela uma conexão travada em andamento', function () {
    whatsappFakeMeta();
    [$company, $admin] = whatsappUiCompany();
    $connection = whatsappUiConnection($company, WhatsAppConnection::STATUS_TEMPLATES_PENDING);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->call('disconnect');

    expect($connection->fresh()->status)->toBe(WhatsAppConnection::STATUS_DISCONNECTED);
});

test('desconectar é restrito a quem pode configurar a empresa', function () {
    whatsappFakeMeta();
    [$company, $manager] = whatsappUiCompany('branch_manager');
    $connection = whatsappUiConnection($company);

    Livewire::actingAs($manager)
        ->test(WhatsAppSettings::class)
        ->call('disconnect')
        ->assertForbidden();

    Livewire::actingAs($manager)
        ->test(WhatsAppSettings::class)
        ->call('confirmDisconnect')
        ->assertForbidden();

    expect($connection->fresh()->status)->toBe(WhatsAppConnection::STATUS_ACTIVE)
        ->and(whatsappMetaCalls())->toBe([]);
});

test('desconectar não mexe na conexão de outra empresa', function () {
    whatsappFakeMeta();
    [$company, $admin] = whatsappUiCompany();
    whatsappUiConnection($company);

    $outra = Company::create(['name' => 'Outra', 'slug' => 'outra-'.uniqid(), 'order_prefix' => 'OUT', 'active' => true]);
    $connectionOutra = WhatsAppConnection::factory()->forCompany($outra)->create();

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->call('disconnect');

    expect($connectionOutra->fresh()->status)->toBe(WhatsAppConnection::STATUS_ACTIVE)
        ->and($connectionOutra->fresh()->access_token)->not->toBeNull();
});

// ── Enviar teste ──────────────────────────────────────────────────────────────

function whatsappUiActiveWithTemplates(Company $company): WhatsAppConnection
{
    $connection = whatsappUiConnection($company);

    foreach (array_keys(config('whatsapp_templates.templates')) as $event) {
        WhatsAppTemplate::factory()->forEvent($event)->create(['whatsapp_connection_id' => $connection->id]);
    }

    return $connection;
}

test('enviar teste manda o template Em preparo com dados fictícios para o celular informado', function () {
    whatsappFakeMeta();
    [$company, $admin] = whatsappUiCompany();
    whatsappUiActiveWithTemplates($company);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->call('openTestModal')
        ->assertSet('showTestModal', true)
        ->set('testPhone', '(44) 99999-1234')
        ->call('sendTest')
        ->assertSet('testFailed', false)
        ->assertSet('testResult', 'Mensagem de teste enviada. Ela deve chegar no WhatsApp em instantes.');

    $sent = whatsappMetaRequests('POST messages');

    expect($sent)->toHaveCount(1)
        ->and($sent->first()['to'])->toBe('5544999991234')
        ->and($sent->first()['template']['name'])->toBe('pedido_em_preparo')
        ->and($sent->first()['template']['components'][0]['parameters'][0]['text'])->toBe('1042');
});

test('enviar teste recusa celular inválido sem chamar a Meta', function (string $phone) {
    whatsappFakeMeta();
    [$company, $admin] = whatsappUiCompany();
    whatsappUiActiveWithTemplates($company);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->set('testPhone', $phone)
        ->call('sendTest')
        ->assertSet('testFailed', true)
        ->assertSet('testResult', 'Informe um celular válido, com DDD e 9 dígitos.');

    expect(whatsappMetaCalls())->toBe([]);
})->with(['vazio' => [''], 'fixo' => ['(44) 3333-4444'], 'curto' => ['12345']]);

test('enviar teste só funciona com a conexão ativa e o template aprovado', function () {
    whatsappFakeMeta();
    [$company, $admin] = whatsappUiCompany();
    whatsappUiConnection($company, WhatsAppConnection::STATUS_TEMPLATES_PENDING);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->set('testPhone', '44999991234')
        ->call('sendTest')
        ->assertSet('testFailed', true)
        ->assertSet('testResult', 'A conexão ainda não está ativa. Aguarde a aprovação dos modelos de mensagem.');

    expect(whatsappMetaCalls())->toBe([]);
});

test('enviar teste traduz o erro da Meta em vez de mostrar o texto técnico', function () {
    whatsappFakeMeta(['POST messages' => whatsappGraphError(131026, 400, 'Message undeliverable')]);
    [$company, $admin] = whatsappUiCompany();
    whatsappUiActiveWithTemplates($company);

    $component = Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->set('testPhone', '44999991234')
        ->call('sendTest')
        ->assertSet('testFailed', true);

    expect($component->get('testResult'))->toContain('o cliente pode não ter WhatsApp neste número')
        ->and($component->get('testResult'))->not->toContain('undeliverable');
});

test('enviar teste tem limite por empresa para não virar disparo em massa', function () {
    whatsappFakeMeta();
    [$company, $admin] = whatsappUiCompany();
    whatsappUiActiveWithTemplates($company);

    $component = Livewire::actingAs($admin)->test(WhatsAppSettings::class)->set('testPhone', '44999991234');

    foreach (range(1, 5) as $ignored) {
        $component->call('sendTest')->assertSet('testFailed', false);
    }

    $component->call('sendTest')
        ->assertSet('testFailed', true)
        ->assertSet('testResult', 'Muitos testes seguidos. Tente novamente em alguns minutos.');

    expect(whatsappMetaRequests('POST messages'))->toHaveCount(5);
});

test('enviar teste é restrito a quem pode configurar a empresa', function () {
    whatsappFakeMeta();
    [$company, $manager] = whatsappUiCompany('branch_manager');
    whatsappUiActiveWithTemplates($company);

    Livewire::actingAs($manager)
        ->test(WhatsAppSettings::class)
        ->set('testPhone', '44999991234')
        ->call('sendTest')
        ->assertForbidden();

    expect(whatsappMetaCalls())->toBe([]);
});

test('enviar teste não usa a conexão de outra empresa', function () {
    whatsappFakeMeta();
    [$company, $admin] = whatsappUiCompany();
    // A própria empresa está desconectada; a outra tem tudo ativo.
    whatsappUiConnection($company, WhatsAppConnection::STATUS_DISCONNECTED);
    $outra = Company::create(['name' => 'Outra', 'slug' => 'outra-'.uniqid(), 'order_prefix' => 'OUT', 'active' => true]);
    $connectionOutra = WhatsAppConnection::factory()->forCompany($outra)->create();
    WhatsAppTemplate::factory()->forEvent('preparing')->create(['whatsapp_connection_id' => $connectionOutra->id]);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->set('testPhone', '44999991234')
        ->call('sendTest')
        ->assertSet('testFailed', true);

    Http::assertNothingSent();
});
