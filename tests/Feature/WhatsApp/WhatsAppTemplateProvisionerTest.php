<?php

use App\Exceptions\WhatsAppRetryableException;
use App\Jobs\ProvisionWhatsAppTemplates;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use App\Services\Messaging\WhatsAppTemplateProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function provisioner(): WhatsAppTemplateProvisioner
{
    return app(WhatsAppTemplateProvisioner::class);
}

/** Conexão com token e WABA, pronta para provisionar. */
function provisioningConnection(string $status = 'provisioning', array $attributes = []): WhatsAppConnection
{
    return WhatsAppConnection::factory()->create(array_merge([
        'waba_id' => '1000000000001',
        'phone_number_id' => '2000000000001',
        'access_token' => 'tok-restaurante',
        'status' => $status,
        'connected_at' => null,
    ], $attributes));
}

/** Templates que já existem na WABA (formato de GET message_templates). */
function remoteTemplates(array $statusByName = [], ?array $onlyNames = null): array
{
    $data = [];

    foreach (config('whatsapp_templates.templates') as $definition) {
        if ($onlyNames !== null && ! in_array($definition['name'], $onlyNames, true)) {
            continue;
        }

        $data[] = [
            'id' => 'remote-'.$definition['name'],
            'name' => $definition['name'],
            'language' => 'pt_BR',
            'status' => $statusByName[$definition['name']] ?? 'APPROVED',
            'category' => 'UTILITY',
        ];
    }

    return ['data' => $data];
}

// ── criação ───────────────────────────────────────────────────────────────────

test('cria todos os templates que faltam na WABA e grava o status devolvido pela Meta', function () {
    whatsappFakeMeta();
    $connection = provisioningConnection();

    $result = provisioner()->provision($connection);

    expect($result)->toMatchArray(['created' => 10, 'synced' => 0, 'failed' => [], 'status' => 'templates_pending'])
        ->and($connection->templates()->count())->toBe(10)
        ->and($connection->templates()->where('status', 'PENDING')->count())->toBe(10)
        ->and($connection->fresh()->status)->toBe('templates_pending')
        ->and($connection->fresh()->connected_at)->toBeNull();

    $sent = whatsappMetaRequests('POST message_templates');

    expect($sent)->toHaveCount(10);

    $byName = $sent->keyBy(fn (Request $request) => $request->data()['name']);

    foreach (config('whatsapp_templates.templates') as $event => $definition) {
        $body = $byName[$definition['name']]->data();

        expect($body['language'])->toBe('pt_BR')
            ->and($body['category'])->toBe('UTILITY')
            ->and($body['components'][0]['type'])->toBe('BODY')
            ->and($body['components'][0]['text'])->toBe($definition['body'])
            ->and($body['components'][0]['example']['body_text'])->toBe([$definition['example']])
            ->and($connection->templates()->where('event', $event)->where('name', $definition['name'])->exists())->toBeTrue();
    }
});

test('usa o token e o appsecret_proof da conexão nas chamadas de template', function () {
    whatsappFakeMeta();
    $connection = provisioningConnection();

    provisioner()->provision($connection);

    $proof = hash_hmac('sha256', 'tok-restaurante', 'app-secret-teste');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/1000000000001/message_templates')
        && $request->hasHeader('Authorization', 'Bearer tok-restaurante')
        && str_contains($request->url(), 'appsecret_proof='.$proof));
});

test('só cria os que faltam e sincroniza o status dos que já existem', function () {
    whatsappFakeMeta(['GET message_templates' => remoteTemplates(
        ['pedido_cancelado' => 'REJECTED', 'pedido_entregue' => 'PENDING'],
        ['pedido_recebido', 'pedido_cancelado', 'pedido_entregue'],
    )]);
    $connection = provisioningConnection();

    $result = provisioner()->provision($connection);

    expect($result['created'])->toBe(7)
        ->and($result['synced'])->toBe(3)
        ->and($connection->templates()->count())->toBe(10);

    $names = whatsappMetaRequests('POST message_templates')->map(fn (Request $request) => $request->data()['name']);

    expect($names)->not->toContain('pedido_recebido')
        ->and($names)->not->toContain('pedido_cancelado')
        ->and($names)->not->toContain('pedido_entregue');

    $recebido = $connection->templates()->where('name', 'pedido_recebido')->first();

    expect($recebido->status)->toBe('APPROVED')
        ->and($recebido->meta_template_id)->toBe('remote-pedido_recebido')
        ->and($connection->templates()->where('name', 'pedido_cancelado')->first()->status)->toBe('REJECTED')
        ->and($connection->templates()->where('name', 'pedido_entregue')->first()->status)->toBe('PENDING');
});

test('guarda o motivo de rejeição informado pela Meta', function () {
    whatsappFakeMeta(['GET message_templates' => ['data' => [[
        'id' => '55', 'name' => 'pedido_cancelado', 'language' => 'pt_BR', 'status' => 'REJECTED', 'rejected_reason' => 'INCORRECT_CATEGORY',
    ]]]]);
    $connection = provisioningConnection();

    provisioner()->provision($connection);

    $template = $connection->templates()->where('name', 'pedido_cancelado')->first();

    expect($template->status)->toBe('REJECTED')->and($template->rejection_reason)->toBe('INCORRECT_CATEGORY');
});

test('template do mesmo nome em outro idioma não conta como existente', function () {
    whatsappFakeMeta(['GET message_templates' => ['data' => [[
        'id' => '77', 'name' => 'pedido_recebido', 'language' => 'en_US', 'status' => 'APPROVED',
    ]]]]);
    $connection = provisioningConnection();

    $result = provisioner()->provision($connection);

    expect($result['created'])->toBe(10)
        ->and($connection->templates()->where('name', 'pedido_recebido')->first()->language)->toBe('pt_BR');
});

test('a Meta pode aprovar o template na hora da criação', function () {
    whatsappFakeMeta(['POST message_templates' => ['id' => '9001', 'status' => 'APPROVED', 'category' => 'UTILITY']]);
    $connection = provisioningConnection();

    $result = provisioner()->provision($connection);

    expect($result['status'])->toBe('active')
        ->and($connection->fresh()->status)->toBe('active')
        ->and($connection->fresh()->connected_at)->not->toBeNull();
});

test('todos os templates já aprovados na WABA deixam a conexão active sem criar nada', function () {
    whatsappFakeMeta(['GET message_templates' => remoteTemplates()]);
    $connection = provisioningConnection();

    $result = provisioner()->provision($connection);

    expect($result)->toMatchArray(['created' => 0, 'synced' => 10, 'status' => 'active'])
        ->and($connection->fresh()->status)->toBe('active')
        ->and($connection->fresh()->last_error)->toBeNull()
        ->and(whatsappMetaRequests('POST message_templates'))->toHaveCount(0);
});

// ── idempotência ──────────────────────────────────────────────────────────────

test('provisionar duas vezes não duplica templates nem recria os existentes', function () {
    // Simula a WABA: o que foi criado passa a aparecer na listagem seguinte.
    $created = [];

    whatsappFakeMeta([
        'GET message_templates' => function () use (&$created) {
            return ['data' => array_values($created)];
        },
        'POST message_templates' => function (Request $request) use (&$created) {
            $body = $request->data();
            $created[$body['name']] = ['id' => 'id-'.$body['name'], 'name' => $body['name'], 'language' => $body['language'], 'status' => 'PENDING'];

            return ['id' => 'id-'.$body['name'], 'status' => 'PENDING', 'category' => 'UTILITY'];
        },
    ]);

    $connection = provisioningConnection();

    $first = provisioner()->provision($connection);
    $second = provisioner()->provision($connection);
    $third = provisioner()->provision($connection);

    expect($first['created'])->toBe(10)
        ->and($second)->toMatchArray(['created' => 0, 'synced' => 10])
        ->and($third)->toMatchArray(['created' => 0, 'synced' => 10])
        ->and($connection->templates()->count())->toBe(10)
        ->and(whatsappMetaRequests('POST message_templates'))->toHaveCount(10);
});

test('reprovisionar atualiza o status quando a Meta aprovou os templates entretanto', function () {
    whatsappFakeMeta();
    $connection = provisioningConnection();
    provisioner()->provision($connection);
    expect($connection->fresh()->status)->toBe('templates_pending');

    whatsappFakeMeta(['GET message_templates' => remoteTemplates()]);

    $result = provisioner()->provision($connection);

    expect($result['status'])->toBe('active')
        ->and($connection->templates()->where('status', 'APPROVED')->count())->toBe(10)
        ->and($connection->templates()->count())->toBe(10);
});

test('conexão já ativa nunca é rebaixada por um template pausado', function () {
    whatsappFakeMeta(['GET message_templates' => remoteTemplates(['pedido_em_preparo' => 'PAUSED'])]);
    $connection = provisioningConnection('active', ['connected_at' => now()->subDay()]);

    $result = provisioner()->provision($connection);

    expect($result['status'])->toBe('active')
        ->and($connection->fresh()->status)->toBe('active')
        ->and($connection->templates()->where('event', 'preparing')->first()->status)->toBe('PAUSED');
});

test('status que não muda o uso (FLAGGED) preserva o atual; template novo começa PENDING', function () {
    whatsappFakeMeta(['GET message_templates' => remoteTemplates(['pedido_recebido' => 'FLAGGED', 'pedido_pago' => 'FLAGGED'], ['pedido_recebido'])]);
    $connection = provisioningConnection();
    WhatsAppTemplate::factory()->forEvent('new_order')->create(['whatsapp_connection_id' => $connection->id]);

    provisioner()->provision($connection);

    expect($connection->templates()->where('name', 'pedido_recebido')->first()->status)->toBe('APPROVED');
});

// ── falhas ────────────────────────────────────────────────────────────────────

test('um template recusado na criação não impede os demais e fica registrado', function () {
    whatsappFakeMeta(['POST message_templates' => function (Request $request) {
        return $request->data()['name'] === 'pedido_reembolsado'
            ? whatsappGraphError(100, 400, 'Invalid parameter')
            : ['id' => 'ok-'.$request->data()['name'], 'status' => 'PENDING', 'category' => 'UTILITY'];
    }]);
    $connection = provisioningConnection();

    $result = provisioner()->provision($connection);

    expect($result['created'])->toBe(9)
        ->and(array_keys($result['failed']))->toBe(['pedido_reembolsado'])
        ->and($connection->templates()->count())->toBe(9)
        ->and($connection->fresh()->status)->toBe('templates_pending')
        ->and($connection->fresh()->last_error)->toContain('pedido_reembolsado');
});

test('depois de corrigido, o mesmo provisionamento cria só o que faltou e limpa o erro', function () {
    whatsappFakeMeta(['POST message_templates' => function (Request $request) {
        return $request->data()['name'] === 'pedido_reembolsado'
            ? whatsappGraphError(100, 400, 'Invalid parameter')
            : ['id' => 'ok-'.$request->data()['name'], 'status' => 'APPROVED', 'category' => 'UTILITY'];
    }]);
    $connection = provisioningConnection();

    provisioner()->provision($connection);
    expect($connection->fresh()->last_error)->not->toBeNull();

    // A WABA já tem os 9 criados (e aprovados); o 10º será criado agora e também aprovado.
    whatsappFakeMeta([
        'GET message_templates' => remoteTemplates(onlyNames: array_map(
            fn ($definition) => $definition['name'],
            array_filter(config('whatsapp_templates.templates'), fn ($definition) => $definition['name'] !== 'pedido_reembolsado'),
        )),
        'POST message_templates' => fn (Request $request) => ['id' => 'ok-'.$request->data()['name'], 'status' => 'APPROVED', 'category' => 'UTILITY'],
    ]);

    $result = provisioner()->provision($connection);

    expect($result)->toMatchArray(['created' => 1, 'synced' => 9, 'failed' => []])
        ->and($connection->templates()->count())->toBe(10)
        ->and($connection->fresh()->last_error)->toBeNull()
        ->and($connection->fresh()->status)->toBe('active');
});

test('falha temporária da Meta é relançada e nada fica pela metade', function () {
    whatsappFakeMeta(['GET message_templates' => whatsappGraphError(2, 503, 'Service unavailable')]);
    $connection = provisioningConnection();

    expect(fn () => provisioner()->provision($connection))->toThrow(WhatsAppRetryableException::class);

    expect($connection->templates()->count())->toBe(0)
        ->and($connection->fresh()->status)->toBe('provisioning');
});

test('falha temporária no meio da criação mantém o que foi criado e o retry termina o serviço', function () {
    $created = [];
    $attempts = 0;

    whatsappFakeMeta([
        'GET message_templates' => function () use (&$created) {
            return ['data' => array_values($created)];
        },
        'POST message_templates' => function (Request $request) use (&$created, &$attempts) {
            $name = $request->data()['name'];

            if (++$attempts === 4) {
                return whatsappGraphError(131000, 500, 'Something went wrong');
            }

            $created[$name] = ['id' => 'id-'.$name, 'name' => $name, 'language' => 'pt_BR', 'status' => 'PENDING'];

            return ['id' => 'id-'.$name, 'status' => 'PENDING', 'category' => 'UTILITY'];
        },
    ]);
    $connection = provisioningConnection();

    expect(fn () => provisioner()->provision($connection))->toThrow(WhatsAppRetryableException::class);
    expect($connection->templates()->count())->toBe(3);

    $result = provisioner()->provision($connection);

    expect($result)->toMatchArray(['created' => 7, 'synced' => 3])
        ->and($connection->templates()->count())->toBe(10)
        ->and($created)->toHaveCount(10);
});

test('token recusado durante o provisionamento coloca a conexão em erro', function () {
    whatsappFakeMeta(['GET message_templates' => whatsappGraphError(190, 401, 'Error validating access token')]);
    $connection = provisioningConnection();

    $result = provisioner()->provision($connection);

    expect($result['status'])->toBe('error')
        ->and($connection->fresh()->status)->toBe('error')
        ->and($connection->fresh()->last_error)->toBe(WhatsAppTemplateProvisioner::MSG_AUTH);
});

test('sem token ou WABA não faz nada', function (array $attributes) {
    whatsappFakeMeta();
    $connection = provisioningConnection('provisioning', $attributes);

    $result = provisioner()->provision($connection);

    Http::assertNothingSent();
    expect($result['created'])->toBe(0);
})->with([
    'sem token' => [['access_token' => null]],
    'sem WABA' => [['waba_id' => null]],
]);

test('isolamento: provisionar a conexão A não cria templates na B', function () {
    whatsappFakeMeta();
    $a = provisioningConnection();
    $b = provisioningConnection('templates_pending', ['waba_id' => '3000000000003', 'phone_number_id' => '4000000000004']);

    provisioner()->provision($a);

    expect($a->templates()->count())->toBe(10)
        ->and($b->templates()->count())->toBe(0)
        ->and($b->fresh()->status)->toBe('templates_pending');
});

// ── job ───────────────────────────────────────────────────────────────────────

test('o job de provisionamento roda na fila whatsapp, é único por conexão e cabe no timeout do worker', function () {
    $job = new ProvisionWhatsAppTemplates(5);

    expect($job->queue)->toBe('whatsapp')
        ->and($job->uniqueId())->toBe('5')
        ->and($job->afterCommit)->toBeTrue()
        ->and($job->timeout)->toBeLessThan(120);
});

test('o job provisiona conexões provisioning, templates_pending e active', function (string $status) {
    whatsappFakeMeta();
    $connection = provisioningConnection($status);

    (new ProvisionWhatsAppTemplates($connection->id))->handle(provisioner());

    expect($connection->templates()->count())->toBe(10);
})->with(['provisioning', 'templates_pending', 'active']);

test('o job ignora conexão desconectada, com erro, pendente ou inexistente', function (string $status) {
    whatsappFakeMeta();
    $connection = provisioningConnection($status);

    (new ProvisionWhatsAppTemplates($connection->id))->handle(provisioner());
    (new ProvisionWhatsAppTemplates(999999))->handle(provisioner());

    Http::assertNothingSent();
})->with(['disconnected', 'error', 'pending']);

test('failed() do job registra o problema sem mudar o status da conexão', function () {
    $connection = provisioningConnection('templates_pending');

    (new ProvisionWhatsAppTemplates($connection->id))->failed(new RuntimeException('boom'));

    expect($connection->fresh()->status)->toBe('templates_pending')
        ->and($connection->fresh()->last_error)->not->toBeEmpty();
});

test('failed() do job não escreve erro em conexão ativa', function () {
    $connection = provisioningConnection('active');

    (new ProvisionWhatsAppTemplates($connection->id))->failed(new RuntimeException('boom'));

    expect($connection->fresh()->last_error)->toBeNull();
});

// ── depois de ativa, o envio funciona com os templates provisionados ──────────

test('conexão provisionada e aprovada envia notificações pelo número do restaurante', function () {
    whatsappFakeMeta(['POST message_templates' => fn (Request $request) => ['id' => 'id-'.$request->data()['name'], 'status' => 'APPROVED', 'category' => 'UTILITY']]);
    ['order' => $order, 'connection' => $existing] = whatsappOrderContext(withConnection: false);
    $connection = WhatsAppConnection::factory()->forCompany($order->company)->create([
        'waba_id' => '1000000000001', 'phone_number_id' => '2000000000001', 'access_token' => 'tok-restaurante', 'status' => 'provisioning',
    ]);

    provisioner()->provision($connection);

    expect($connection->fresh()->status)->toBe('active')
        ->and(app(App\Services\Messaging\WhatsAppService::class)->shouldNotify($order->fresh(), 'preparing'))->toBeTrue();
});
