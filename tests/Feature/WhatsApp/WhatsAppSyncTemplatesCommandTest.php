<?php

use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function syncConnection(string $status = 'templates_pending', array $attributes = []): WhatsAppConnection
{
    return WhatsAppConnection::factory()->create(array_merge([
        'waba_id' => '1000000000001',
        'phone_number_id' => '2000000000001',
        'access_token' => 'tok-restaurante',
        'status' => $status,
        'quality_rating' => 'GREEN',
        'connected_at' => null,
    ], $attributes));
}

/** Todos os templates do config já aprovados na WABA. */
function allApprovedRemotely(): array
{
    return ['data' => collect(config('whatsapp_templates.templates'))->map(fn ($definition) => [
        'id' => 'r-'.$definition['name'], 'name' => $definition['name'], 'language' => 'pt_BR', 'status' => 'APPROVED',
    ])->values()->all()];
}

test('com --company cria os templates, sincroniza o status e mostra o resumo', function () {
    whatsappFakeMeta();
    $connection = syncConnection('provisioning');

    $this->artisan('whatsapp:sync-templates', ['--company' => (string) $connection->company_id])
        ->expectsOutputToContain("Conexão #{$connection->id} (empresa {$connection->company_id}): 10 criado(s), 0 sincronizado(s), status templates_pending")
        ->assertSuccessful();

    expect($connection->templates()->count())->toBe(10);
});

test('sincroniza o status dos templates aprovados e ativa a conexão', function () {
    whatsappFakeMeta(['GET message_templates' => allApprovedRemotely()]);
    $connection = syncConnection();

    $this->artisan('whatsapp:sync-templates', ['--company' => $connection->company->slug])
        ->expectsOutputToContain('0 criado(s), 10 sincronizado(s), status active')
        ->assertSuccessful();

    expect($connection->fresh()->status)->toBe('active')
        ->and($connection->fresh()->connected_at)->not->toBeNull()
        ->and($connection->templates()->where('status', 'APPROVED')->count())->toBe(10);
});

test('rodar de novo não duplica nada', function () {
    whatsappFakeMeta(['GET message_templates' => allApprovedRemotely()]);
    $connection = syncConnection();

    $this->artisan('whatsapp:sync-templates', ['--company' => (string) $connection->company_id])->assertSuccessful();
    $this->artisan('whatsapp:sync-templates', ['--company' => (string) $connection->company_id])->assertSuccessful();

    expect($connection->templates()->count())->toBe(10)
        ->and(whatsappMetaRequests('POST message_templates'))->toHaveCount(0);
});

test('também atualiza qualidade e limite do número a partir da Graph API', function () {
    whatsappFakeMeta([
        'GET message_templates' => allApprovedRemotely(),
        'GET phone' => ['id' => '2000000000001', 'quality_rating' => 'RED', 'messaging_limit_tier' => 'TIER_1K'],
    ]);
    $connection = syncConnection('active');

    $this->artisan('whatsapp:sync-templates', ['--company' => (string) $connection->company_id])->assertSuccessful();

    expect($connection->fresh()->quality_rating)->toBe('RED')
        ->and($connection->fresh()->messaging_limit_tier)->toBe('TIER_1K');
});

test('falha ao ler os dados do número não impede a sincronização dos templates', function () {
    whatsappFakeMeta([
        'GET message_templates' => allApprovedRemotely(),
        'GET phone' => whatsappGraphError(100, 400, 'Unsupported get request'),
    ]);
    $connection = syncConnection();

    $this->artisan('whatsapp:sync-templates', ['--company' => (string) $connection->company_id])
        ->expectsOutputToContain('não foi possível atualizar os dados do número')
        ->assertSuccessful();

    expect($connection->fresh()->status)->toBe('active');
});

test('sem --company sincroniza só conexões provisioning, templates_pending e active com token', function () {
    whatsappFakeMeta();

    $provisioning = syncConnection('provisioning', ['waba_id' => '1000000000001', 'phone_number_id' => '2000000000001']);
    $pending = syncConnection('templates_pending', ['waba_id' => '1000000000002', 'phone_number_id' => '2000000000002']);
    $active = syncConnection('active', ['waba_id' => '1000000000003', 'phone_number_id' => '2000000000003']);
    $disconnected = syncConnection('disconnected', ['waba_id' => '1000000000004', 'phone_number_id' => '2000000000004', 'access_token' => null]);
    $error = syncConnection('error', ['waba_id' => '1000000000005', 'phone_number_id' => '2000000000005']);
    $semToken = syncConnection('active', ['waba_id' => '1000000000006', 'phone_number_id' => '2000000000006', 'access_token' => null]);

    $this->artisan('whatsapp:sync-templates')->assertSuccessful();

    expect($provisioning->templates()->count())->toBe(10)
        ->and($pending->templates()->count())->toBe(10)
        ->and($active->templates()->count())->toBe(10)
        ->and($disconnected->templates()->count())->toBe(0)
        ->and($error->templates()->count())->toBe(0)
        ->and($semToken->templates()->count())->toBe(0);
});

test('--company com conexão em erro ainda sincroniza (decisão explícita do suporte)', function () {
    whatsappFakeMeta();
    $connection = syncConnection('error');

    $this->artisan('whatsapp:sync-templates', ['--company' => (string) $connection->company_id])->assertSuccessful();

    expect($connection->templates()->count())->toBe(10);
});

test('--company não toca nas conexões de outras empresas', function () {
    whatsappFakeMeta();
    $alvo = syncConnection('templates_pending', ['waba_id' => '1000000000001', 'phone_number_id' => '2000000000001']);
    $outra = syncConnection('templates_pending', ['waba_id' => '1000000000002', 'phone_number_id' => '2000000000002']);

    $this->artisan('whatsapp:sync-templates', ['--company' => (string) $alvo->company_id])->assertSuccessful();

    expect($alvo->templates()->count())->toBe(10)
        ->and($outra->templates()->count())->toBe(0);
});

test('empresa inexistente falha', function () {
    whatsappFakeMeta();

    $this->artisan('whatsapp:sync-templates', ['--company' => 'nao-existe'])
        ->expectsOutputToContain('não encontrada')
        ->assertFailed();

    Http::assertNothingSent();
});

test('empresa sem conexão com token falha com aviso', function () {
    whatsappFakeMeta();
    $connection = syncConnection('disconnected', ['access_token' => null]);

    $this->artisan('whatsapp:sync-templates', ['--company' => (string) $connection->company_id])
        ->expectsOutputToContain('Nenhuma conexão')
        ->assertFailed();
});

test('sem nenhuma conexão para sincronizar o comando geral termina com sucesso', function () {
    whatsappFakeMeta();

    $this->artisan('whatsapp:sync-templates')
        ->expectsOutputToContain('Nenhuma conexão')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('template recusado na criação sai no resumo e o comando termina com falha', function () {
    whatsappFakeMeta(['POST message_templates' => function (Request $request) {
        return $request->data()['name'] === 'pedido_reembolsado'
            ? whatsappGraphError(100, 400, 'Invalid parameter')
            : ['id' => 'ok', 'status' => 'PENDING', 'category' => 'UTILITY'];
    }]);
    $connection = syncConnection('provisioning');

    $this->artisan('whatsapp:sync-templates', ['--company' => (string) $connection->company_id])
        ->expectsOutputToContain('pedido_reembolsado')
        ->assertFailed();
});

test('falha temporária em uma conexão não impede as outras', function () {
    $primeira = true;

    whatsappFakeMeta(['GET message_templates' => function (Request $request) use (&$primeira) {
        // A primeira WABA consultada falha; as demais funcionam.
        if (str_contains($request->url(), '/1000000000001/')) {
            return whatsappGraphError(2, 503, 'Service unavailable');
        }

        return ['data' => []];
    }]);

    $ruim = syncConnection('provisioning', ['waba_id' => '1000000000001', 'phone_number_id' => '2000000000001']);
    $boa = syncConnection('provisioning', ['waba_id' => '1000000000002', 'phone_number_id' => '2000000000002']);

    $this->artisan('whatsapp:sync-templates')
        ->expectsOutputToContain('falha temporária')
        ->assertFailed();

    expect($ruim->templates()->count())->toBe(0)
        ->and($boa->templates()->count())->toBe(10);
});

test('token recusado deixa a conexão em erro e mostra isso no resumo', function () {
    whatsappFakeMeta(['GET message_templates' => whatsappGraphError(190, 401, 'Error validating access token')]);
    $connection = syncConnection('active');

    $this->artisan('whatsapp:sync-templates', ['--company' => (string) $connection->company_id])
        ->expectsOutputToContain('status error');

    expect($connection->fresh()->status)->toBe('error');
});
