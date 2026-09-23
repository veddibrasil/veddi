<?php

use App\DTOs\WhatsAppSender;
use App\Models\Company;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.whatsapp.fallback_to_platform' => false]);
});

function whatsappService(): WhatsAppService
{
    return app(WhatsAppService::class);
}

// ── normalizePhone ────────────────────────────────────────────────────────────

test('normalizePhone aceita celular com e sem DDI e rejeita fixo', function (string $input, ?string $expected) {
    expect(whatsappService()->normalizePhone($input))->toBe($expected);
})->with([
    'celular só dígitos' => ['11999990001', '5511999990001'],
    'celular formatado' => ['(11) 99999-0001', '5511999990001'],
    'celular com DDI' => ['5511999990001', '5511999990001'],
    'celular com +DDI' => ['+55 11 99999-0001', '5511999990001'],
    'fixo (10 dígitos)' => ['1133334444', null],
    'curto' => ['99999', null],
    'vazio' => ['', null],
]);

test('phoneCandidates cobre com e sem DDI e com e sem o 9º dígito', function (string $waId, array $expected) {
    expect(whatsappService()->phoneCandidates($waId))->toEqualCanonicalizing($expected);
})->with([
    'wa_id com 9' => ['5511999990001', ['11999990001', '1199990001', '5511999990001', '551199990001']],
    'wa_id sem o 9' => ['551199990001', ['1199990001', '11999990001', '551199990001', '5511999990001']],
    'sem DDI, com 9' => ['11999990001', ['11999990001', '1199990001', '5511999990001', '551199990001']],
    'formatado' => ['+55 (11) 99999-0001', ['11999990001', '1199990001', '5511999990001', '551199990001']],
]);

test('phoneCandidates não devolve duplicados', function () {
    $candidates = whatsappService()->phoneCandidates('5511999990001');

    expect($candidates)->toBe(array_values(array_unique($candidates)));
});

// ── resolveTemplate ───────────────────────────────────────────────────────────

test('resolveTemplate de new_order traz nome, número e total', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'pending', 'total' => 1234.5]);

    $template = whatsappService()->resolveTemplate($order->fresh(), 'new_order');

    expect($template)->toBe([
        'event' => 'new_order',
        'name' => 'pedido_recebido',
        'language' => 'pt_BR',
        'params' => ['Maria Silva', $order->order_number, 'R$ 1.234,50'],
    ]);
});

test('resolveTemplate de ready escolhe retirada ou entrega pelo tipo do pedido', function (string $orderType, string $event, string $name) {
    ['order' => $order] = whatsappOrderContext(['order_type' => $orderType, 'status' => 'ready']);

    $template = whatsappService()->resolveTemplate($order->fresh(), 'ready');

    expect($template['event'])->toBe($event)
        ->and($template['name'])->toBe($name)
        ->and($template['params'])->toBe([$order->order_number]);
})->with([
    'retirada' => ['pickup', 'ready_pickup', 'pedido_pronto_retirada'],
    'entrega' => ['delivery', 'ready_delivery', 'pedido_pronto_entrega'],
]);

test('resolveTemplate de scheduled formata a data no fuso da aplicação', function () {
    config(['app.timezone' => 'America/Sao_Paulo']);

    ['order' => $order] = whatsappOrderContext([
        'status' => 'scheduled',
        'scheduled_at' => '2026-09-25 22:30:00', // UTC-agnóstico: o cast usa o fuso configurado
    ]);

    $template = whatsappService()->resolveTemplate($order->fresh(), 'scheduled');

    expect($template['name'])->toBe('pedido_agendado')
        ->and($template['params'][0])->toBe($order->order_number)
        ->and($template['params'][1])->toMatch('/^25\/09\/2026 às \d{2}:\d{2}$/');
});

test('resolveTemplate devolve null para evento sem template', function (string $event) {
    ['order' => $order] = whatsappOrderContext();

    expect(whatsappService()->resolveTemplate($order, $event))->toBeNull();
})->with(['awaiting_payment', 'admin_message', 'inexistente']);

test('resolveTemplate devolve null quando falta dado para uma variável', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'scheduled', 'scheduled_at' => null]);

    expect(whatsappService()->resolveTemplate($order, 'scheduled'))->toBeNull();
});

test('cliente sem nome recebe "Cliente" no template', function () {
    ['order' => $order, 'customer' => $customer] = whatsappOrderContext(['status' => 'pending']);
    DB::table('customers')->where('id', $customer->id)->update(['name' => '   ']);

    $template = whatsappService()->resolveTemplate($order->fresh(), 'new_order');

    expect($template['params'][0])->toBe('Cliente');
});

// ── resolveSender ─────────────────────────────────────────────────────────────

test('resolveSender usa a conexão ativa da empresa', function () {
    ['company' => $company, 'connection' => $connection] = whatsappOrderContext();

    $sender = whatsappService()->resolveSender($company);

    expect($sender)->toBeInstanceOf(WhatsAppSender::class)
        ->and($sender->connectionId)->toBe($connection->id)
        ->and($sender->phoneNumberId)->toBe($connection->phone_number_id)
        ->and($sender->accessToken)->toBe($connection->access_token)
        ->and($sender->isPlatform())->toBeFalse();
});

test('resolveSender ignora conexão que não está ativa', function (string $status) {
    ['company' => $company, 'connection' => $connection] = whatsappOrderContext();
    $connection->update(['status' => $status]);

    expect(whatsappService()->resolveSender($company))->toBeNull();
})->with(['pending', 'provisioning', 'templates_pending', 'disconnected', 'error']);

test('resolveSender ignora conexão ativa sem token', function () {
    ['company' => $company, 'connection' => $connection] = whatsappOrderContext();
    $connection->update(['access_token' => null]);

    expect(whatsappService()->resolveSender($company))->toBeNull();
});

test('resolveSender cai no número da plataforma só com o fallback ligado', function () {
    config([
        'services.whatsapp.platform.phone_number_id' => '777000111',
        'services.whatsapp.platform.token' => 'token-plataforma',
    ]);

    ['company' => $company] = whatsappOrderContext(withConnection: false);

    expect(whatsappService()->resolveSender($company))->toBeNull();

    config(['services.whatsapp.fallback_to_platform' => true]);

    $sender = whatsappService()->resolveSender($company);

    expect($sender->isPlatform())->toBeTrue()
        ->and($sender->phoneNumberId)->toBe('777000111')
        ->and($sender->accessToken)->toBe('token-plataforma');
});

test('resolveSender não usa a conexão de outra empresa', function () {
    ['company' => $semConexao] = whatsappOrderContext(withConnection: false);
    whatsappOrderContext(); // outra empresa, com conexão ativa

    expect(whatsappService()->resolveSender($semConexao))->toBeNull();
});

test('resolveSender enxerga a conexão mesmo com current.company de outra empresa', function () {
    ['company' => $companyA, 'connection' => $connectionA] = whatsappOrderContext();
    ['company' => $companyB] = whatsappOrderContext();

    app()->instance('current.company', $companyB);

    expect(whatsappService()->resolveSender($companyA)->connectionId)->toBe($connectionA->id);
});

// ── hasApprovedTemplate ───────────────────────────────────────────────────────

test('hasApprovedTemplate depende do status do template na conexão', function () {
    ['company' => $company, 'connection' => $connection] = whatsappOrderContext();

    expect(whatsappService()->hasApprovedTemplate($company, 'preparing'))->toBeTrue();

    $connection->templates()->where('event', 'preparing')->update(['status' => 'PENDING']);

    expect(whatsappService()->hasApprovedTemplate($company, 'preparing'))->toBeFalse();
});

test('no fallback os templates da plataforma contam como aprovados via config', function () {
    config(['services.whatsapp.fallback_to_platform' => true]);

    ['company' => $company] = whatsappOrderContext(withConnection: false);

    expect(whatsappService()->hasApprovedTemplate($company, 'preparing'))->toBeTrue()
        ->and(whatsappService()->hasApprovedTemplate($company, 'awaiting_payment'))->toBeFalse();
});

// ── config/whatsapp_templates.php ─────────────────────────────────────────────

test('templates seguem as regras da Meta e o contrato do config', function () {
    $language = config('whatsapp_templates.language');
    $templates = config('whatsapp_templates.templates');

    expect($language)->toBe('pt_BR')
        ->and(config('whatsapp_templates.category'))->toBe('UTILITY')
        ->and(array_keys($templates))->toEqualCanonicalizing([
            'new_order', 'paid', 'scheduled', 'preparing', 'ready_pickup', 'ready_delivery',
            'out_for_delivery', 'delivered', 'cancelled', 'refunded',
        ]);

    $names = [];

    foreach ($templates as $event => $definition) {
        preg_match_all('/\{\{(\d+)\}\}/', $definition['body'], $matches);
        $placeholders = array_map('intval', $matches[1]);

        expect($definition['body'])->not->toMatch('/^\s*\{\{/', "{$event} começa com variável")
            ->and($definition['body'])->not->toMatch('/\{\{\d+\}\}[\s.!?]*$/', "{$event} termina com variável")
            ->and($definition['name'])->toMatch('/^[a-z0-9_]+$/')
            ->and($placeholders)->toBe(range(1, count($definition['variables'])), "{$event}: variáveis fora de ordem")
            ->and($definition['example'])->toHaveCount(count($definition['variables']), "{$event}: exemplos != variáveis");

        $names[] = $definition['name'];
    }

    expect($names)->toHaveCount(count(array_unique($names)));
});

// ── Modelos ───────────────────────────────────────────────────────────────────

test('token e PIN são criptografados no banco e escondidos na serialização', function () {
    $connection = WhatsAppConnection::factory()->create([
        'access_token' => 'token-em-claro',
        'registration_pin' => '123456',
    ]);

    $raw = DB::table('whatsapp_connections')->where('id', $connection->id)->first();

    expect($raw->access_token)->not->toContain('token-em-claro')
        ->and($raw->registration_pin)->not->toContain('123456')
        ->and($connection->fresh()->access_token)->toBe('token-em-claro')
        ->and($connection->fresh()->registration_pin)->toBe('123456')
        ->and($connection->fresh()->toArray())->not->toHaveKeys(['access_token', 'registration_pin'])
        ->and($connection->fresh()->toJson())->not->toContain('token-em-claro');
});

test('uma empresa só pode ter uma conexão e o phone_number_id é único', function () {
    $company = Company::create(['name' => 'Uma', 'slug' => 'uma', 'order_prefix' => 'UMA', 'active' => true]);

    WhatsAppConnection::factory()->forCompany($company)->create(['phone_number_id' => '555']);

    expect(fn () => WhatsAppConnection::factory()->forCompany($company)->create())->toThrow(Illuminate\Database\QueryException::class);

    expect(fn () => WhatsAppConnection::factory()->create(['phone_number_id' => '555']))->toThrow(Illuminate\Database\QueryException::class);
});

test('approvedTemplate só devolve template aprovado do evento', function () {
    $connection = WhatsAppConnection::factory()->create();

    WhatsAppTemplate::factory()->forEvent('preparing')->status('PENDING')->create(['whatsapp_connection_id' => $connection->id]);
    WhatsAppTemplate::factory()->forEvent('delivered')->create(['whatsapp_connection_id' => $connection->id]);

    expect($connection->approvedTemplate('preparing'))->toBeNull()
        ->and($connection->approvedTemplate('delivered')?->name)->toBe('pedido_entregue')
        ->and($connection->approvedTemplate('cancelled'))->toBeNull();
});

test('hasAllTemplatesApproved exige todos os templates do config aprovados no idioma', function () {
    $connection = WhatsAppConnection::factory()->create();

    expect($connection->hasAllTemplatesApproved())->toBeFalse();

    foreach (array_keys(config('whatsapp_templates.templates')) as $event) {
        WhatsAppTemplate::factory()->forEvent($event)->create(['whatsapp_connection_id' => $connection->id]);
    }

    expect($connection->hasAllTemplatesApproved())->toBeTrue();

    $connection->templates()->where('event', 'refunded')->update(['status' => 'PAUSED']);
    expect($connection->hasAllTemplatesApproved())->toBeFalse();

    $connection->templates()->where('event', 'refunded')->update(['status' => 'APPROVED', 'language' => 'en_US']);
    expect($connection->hasAllTemplatesApproved())->toBeFalse();
});

test('template é único por conexão, nome e idioma', function () {
    $connection = WhatsAppConnection::factory()->create();

    WhatsAppTemplate::factory()->forEvent('preparing')->create(['whatsapp_connection_id' => $connection->id]);

    expect(fn () => WhatsAppTemplate::factory()->forEvent('preparing')->create(['whatsapp_connection_id' => $connection->id]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

test('wamid é único entre mensagens', function () {
    WhatsAppMessage::factory()->create(['wamid' => 'wamid.IGUAL']);

    expect(fn () => WhatsAppMessage::factory()->create(['wamid' => 'wamid.IGUAL']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

test('Company expõe a conexão de WhatsApp', function () {
    $connection = WhatsAppConnection::factory()->create();

    app()->instance('current.company', $connection->company);

    expect($connection->company->whatsappConnection->id)->toBe($connection->id);
});
