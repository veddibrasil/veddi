<?php

use App\Livewire\Chat\OrderChat;
use App\Models\Company;
use App\Models\Customer;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Empresa com chat público. Por padrão o WhatsApp está ativo (notificações ligadas + conexão ativa),
 * que é quando o chat pede o consentimento do cliente.
 */
function whatsappChatCompany(bool $whatsappActive = true, string $slugPrefix = 'chat-optin'): Company
{
    $company = Company::create([
        'name' => 'Chat OptIn '.uniqid(),
        'slug' => $slugPrefix.'-'.uniqid(),
        'order_prefix' => 'OPT',
        'active' => true,
        'status' => 'ACTIVE',
        'email' => 'contato@empresa.com',
    ]);

    if ($whatsappActive) {
        WhatsAppSetting::create(['company_id' => $company->id, 'enabled' => true]);
        WhatsAppConnection::factory()->forCompany($company)->create();
    }

    return $company;
}

/** O mount() do OrderChat lê a rota, então (como nos outros testes do chat) o componente é instanciado direto. */
function whatsappChatComponent(Company $company, string $phone = '11999990001'): OrderChat
{
    app()->instance('current.company', $company);

    $component = new OrderChat;
    $component->companyId = $company->id;
    $component->phone = $phone;

    return $component;
}

/** Abre o chat já na tela do telefone (o chat só chega nela ao iniciar o checkout). */
function whatsappChatAtPhoneStep(\Illuminate\Foundation\Testing\TestCase $test, Company $company): \Illuminate\Testing\TestResponse
{
    return $test->withSession(['chat_state' => ['step' => 'IDENTIFY_PHONE', 'companyId' => $company->id]])
        ->get('/'.$company->slug);
}

const WHATSAPP_OPT_IN_TEXT = 'Quero receber atualizações do meu pedido pelo WhatsApp';

// ── Exibição do checkbox ──────────────────────────────────────────────────────

test('o chat mostra o checkbox de WhatsApp, desmarcado, quando a empresa notifica por WhatsApp', function () {
    $company = whatsappChatCompany();

    whatsappChatAtPhoneStep($this, $company)
        ->assertOk()
        ->assertSee('Telefone com DDD')
        ->assertSee(WHATSAPP_OPT_IN_TEXT)
        ->assertSee('respondendo PARAR');

    expect((new OrderChat)->whatsappOptIn)->toBeFalse();
});

test('o chat esconde o checkbox quando a empresa não tem WhatsApp ativo', function (string $cenario) {
    $company = whatsappChatCompany(false);

    match ($cenario) {
        'sem conexão' => WhatsAppSetting::create(['company_id' => $company->id, 'enabled' => true]),
        'conexão com erro' => (function () use ($company) {
            WhatsAppSetting::create(['company_id' => $company->id, 'enabled' => true]);
            WhatsAppConnection::factory()->forCompany($company)->status(WhatsAppConnection::STATUS_ERROR)->create();
        })(),
        'notificações desligadas' => (function () use ($company) {
            WhatsAppSetting::create(['company_id' => $company->id, 'enabled' => false]);
            WhatsAppConnection::factory()->forCompany($company)->create();
        })(),
        'sem configuração' => null,
    };

    whatsappChatAtPhoneStep($this, $company)
        ->assertOk()
        ->assertSee('Telefone com DDD')
        ->assertDontSee(WHATSAPP_OPT_IN_TEXT);
})->with(['sem conexão', 'conexão com erro', 'notificações desligadas', 'sem configuração']);

test('com o fallback da plataforma ativo o chat também pede o consentimento', function () {
    config([
        'services.whatsapp.fallback_to_platform' => true,
        'services.whatsapp.platform.phone_number_id' => '999000111',
        'services.whatsapp.platform.token' => 'token-da-plataforma',
    ]);
    $company = whatsappChatCompany(false);
    WhatsAppSetting::create(['company_id' => $company->id, 'enabled' => true]);

    whatsappChatAtPhoneStep($this, $company)->assertSee(WHATSAPP_OPT_IN_TEXT);
});

test('a conexão de outra empresa não faz o checkbox aparecer', function () {
    whatsappChatCompany(); // outra empresa, com tudo ativo
    $company = whatsappChatCompany(false);

    whatsappChatAtPhoneStep($this, $company)->assertSee('Telefone com DDD')->assertDontSee(WHATSAPP_OPT_IN_TEXT);
});

// ── Gravação do consentimento ─────────────────────────────────────────────────

test('cliente existente que marca o checkbox passa a ter opt-in', function () {
    $company = whatsappChatCompany();
    $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Maria', 'phone' => '11999990001']);

    $chat = whatsappChatComponent($company);
    $chat->whatsappOptIn = true;
    $chat->submitPhone();

    $customer->refresh();

    expect($customer->whatsapp_opt_in_at)->not->toBeNull()
        ->and($customer->whatsapp_opt_out_at)->toBeNull()
        ->and($customer->canReceiveWhatsApp())->toBeTrue();
});

test('cliente existente que não marca o checkbox continua sem opt-in', function () {
    $company = whatsappChatCompany();
    $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Maria', 'phone' => '11999990001']);

    $chat = whatsappChatComponent($company);
    $chat->submitPhone();

    expect($customer->fresh()->whatsapp_opt_in_at)->toBeNull();
});

test('desmarcar o checkbox nunca revoga um consentimento anterior', function () {
    $company = whatsappChatCompany();
    $optedIn = now()->subDays(10)->startOfSecond();
    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Maria', 'phone' => '11999990001', 'whatsapp_opt_in_at' => $optedIn,
    ]);

    $chat = whatsappChatComponent($company);
    $chat->whatsappOptIn = false;
    $chat->submitPhone();

    expect($customer->fresh()->whatsapp_opt_in_at->equalTo($optedIn))->toBeTrue()
        ->and($customer->fresh()->canReceiveWhatsApp())->toBeTrue();
});

test('marcar de novo mantém a data do primeiro consentimento', function () {
    $company = whatsappChatCompany();
    $optedIn = now()->subDays(10)->startOfSecond();
    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Maria', 'phone' => '11999990001', 'whatsapp_opt_in_at' => $optedIn,
    ]);

    $chat = whatsappChatComponent($company);
    $chat->whatsappOptIn = true;
    $chat->submitPhone();

    expect($customer->fresh()->whatsapp_opt_in_at->equalTo($optedIn))->toBeTrue();
});

test('cliente que pediu PARAR volta a receber ao consentir de novo', function () {
    $company = whatsappChatCompany();
    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Maria', 'phone' => '11999990001',
        'whatsapp_opt_in_at' => now()->subDays(30), 'whatsapp_opt_out_at' => now()->subDays(5),
    ]);
    expect($customer->canReceiveWhatsApp())->toBeFalse();

    $chat = whatsappChatComponent($company);
    $chat->whatsappOptIn = true;
    $chat->submitPhone();

    $customer->refresh();

    expect($customer->whatsapp_opt_out_at)->toBeNull()
        ->and($customer->whatsapp_opt_in_at->isToday())->toBeTrue()
        ->and($customer->canReceiveWhatsApp())->toBeTrue();
});

test('cliente que pediu PARAR continua bloqueado se não marcar o checkbox', function () {
    $company = whatsappChatCompany();
    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Maria', 'phone' => '11999990001',
        'whatsapp_opt_in_at' => now()->subDays(30), 'whatsapp_opt_out_at' => now()->subDays(5),
    ]);

    $chat = whatsappChatComponent($company);
    $chat->submitPhone();

    expect($customer->fresh()->canReceiveWhatsApp())->toBeFalse();
});

test('cliente novo cadastrado no chat grava o opt-in marcado na tela do telefone', function () {
    $company = whatsappChatCompany();

    $chat = whatsappChatComponent($company);
    $chat->whatsappOptIn = true;
    $chat->submitPhone(); // telefone desconhecido → cadastro

    expect($chat->step)->toBe('REGISTER_NAME')
        ->and($chat->whatsappOptIn)->toBeTrue(); // continua guardado para o fim do cadastro

    $chat->name = 'João da Silva';
    $chat->address = 'Rua das Flores';
    $chat->number = '100';
    $chat->neighborhood = 'Centro';
    $chat->city = 'Maringá';
    $chat->cep = '87000-000';
    $chat->submitAddress();

    $customer = Customer::withoutGlobalScopes()->where('company_id', $company->id)->where('phone', '11999990001')->firstOrFail();

    expect($customer->canReceiveWhatsApp())->toBeTrue();
});

test('cliente novo sem marcar o checkbox é cadastrado sem opt-in', function () {
    $company = whatsappChatCompany();

    $chat = whatsappChatComponent($company);
    $chat->submitPhone();

    $chat->name = 'João da Silva';
    $chat->address = 'Rua das Flores';
    $chat->number = '100';
    $chat->neighborhood = 'Centro';
    $chat->city = 'Maringá';
    $chat->cep = '87000-000';
    $chat->submitAddress();

    $customer = Customer::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();

    expect($customer->whatsapp_opt_in_at)->toBeNull();
});

test('o consentimento é por empresa: importar cliente de outra empresa não herda o opt-in', function () {
    $outra = whatsappChatCompany(true, 'outra');
    Customer::withoutGlobalScopes()->create([
        'company_id' => $outra->id, 'name' => 'Maria', 'phone' => '11999990001', 'whatsapp_opt_in_at' => now(),
    ]);

    $company = whatsappChatCompany();

    $chat = whatsappChatComponent($company);
    $chat->whatsappOptIn = false;
    $chat->submitPhone(); // existe só na outra empresa → importa os dados

    $imported = Customer::withoutGlobalScopes()->where('company_id', $company->id)->where('phone', '11999990001')->firstOrFail();

    expect($imported->whatsapp_opt_in_at)->toBeNull();
});

test('cliente importado de outra empresa que marca o checkbox recebe opt-in só na empresa atual', function () {
    $outra = whatsappChatCompany(true, 'outra');
    $original = Customer::withoutGlobalScopes()->create([
        'company_id' => $outra->id, 'name' => 'Maria', 'phone' => '11999990001',
    ]);

    $company = whatsappChatCompany();

    $chat = whatsappChatComponent($company);
    $chat->whatsappOptIn = true;
    $chat->submitPhone();

    $imported = Customer::withoutGlobalScopes()->where('company_id', $company->id)->where('phone', '11999990001')->firstOrFail();

    expect($imported->canReceiveWhatsApp())->toBeTrue()
        ->and($original->fresh()->whatsapp_opt_in_at)->toBeNull();
});

test('opt-in não é gravado se a empresa não tem WhatsApp ativo, mesmo com o valor forçado no payload', function () {
    $company = whatsappChatCompany(false);
    $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Maria', 'phone' => '11999990001']);

    $chat = whatsappChatComponent($company);
    $chat->whatsappOptIn = true;
    $chat->submitPhone();

    expect($customer->fresh()->whatsapp_opt_in_at)->toBeNull();
});
