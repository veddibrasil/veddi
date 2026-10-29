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
    return $test->withSession(['chat_state' => ['securityVersion' => 2, 'step' => 'IDENTIFY_PHONE', 'companyId' => $company->id]])
        ->get('/'.$company->slug);
}

const WHATSAPP_OPT_IN_TEXT = 'Quero receber atualizações do meu pedido pelo WhatsApp';

// ── Exibição do checkbox ──────────────────────────────────────────────────────

const WHATSAPP_CHAT_DISABLED = 'Opt-in de WhatsApp no chat desligado até a integração ser validada em produção';

test('o chat não mostra o checkbox de WhatsApp enquanto o opt-in está desligado, mesmo com a empresa ativa', function () {
    $company = whatsappChatCompany();

    whatsappChatAtPhoneStep($this, $company)
        ->assertOk()
        ->assertSee('Telefone com DDD')
        ->assertDontSee(WHATSAPP_OPT_IN_TEXT);
});

test('o chat mostra o checkbox de WhatsApp, desmarcado, quando a empresa notifica por WhatsApp', function () {
    $company = whatsappChatCompany();

    whatsappChatAtPhoneStep($this, $company)
        ->assertOk()
        ->assertSee('Telefone com DDD')
        ->assertSee(WHATSAPP_OPT_IN_TEXT)
        ->assertSee('respondendo PARAR');

    expect((new OrderChat)->whatsappOptIn)->toBeFalse();
})->skip(WHATSAPP_CHAT_DISABLED);

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
})->skip(WHATSAPP_CHAT_DISABLED);

test('a conexão de outra empresa não faz o checkbox aparecer', function () {
    whatsappChatCompany(); // outra empresa, com tudo ativo
    $company = whatsappChatCompany(false);

    whatsappChatAtPhoneStep($this, $company)->assertSee('Telefone com DDD')->assertDontSee(WHATSAPP_OPT_IN_TEXT);
});

// Phone entry never changes persistent consent, including previous opt-outs.
test('digitar telefone não altera consentimentos mesmo marcando o checkbox', function (bool $optedIn, bool $optedOut) {
    $company = whatsappChatCompany();
    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Maria', 'phone' => '11999990001',
        'whatsapp_opt_in_at' => $optedIn ? now()->subDays(30) : null,
        'whatsapp_opt_out_at' => $optedOut ? now()->subDays(5) : null,
    ])->fresh();
    $original = $customer->getAttributes();
    $chat = whatsappChatComponent($company);
    $chat->whatsappOptIn = true;
    $chat->submitPhone();
    expect($customer->fresh()->getAttributes())->toBe($original)
        ->and($chat->customerId)->toBeNull()
        ->and($chat->step)->toBe('CUSTOMER_OPTIONS');
})->with([[false, false], [true, false], [true, true]]);

test('preencher compra não cria perfil nem opt-in permanente', function () {
    $company = whatsappChatCompany();
    $chat = whatsappChatComponent($company);
    $chat->whatsappOptIn = true;
    $chat->submitPhone();
    $chat->continueAsGuest();
    $chat->name = 'João da Silva';
    $chat->submitName();
    expect($chat->checkoutReady)->toBeTrue()
        ->and($chat->customerId)->toBeNull()
        ->and(Customer::withoutGlobalScopes()->where('company_id', $company->id)->exists())->toBeFalse();
});

test('chat não importa perfil nem consentimento de outra empresa', function (bool $optedIn) {
    $other = whatsappChatCompany(true, 'outra');
    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $other->id, 'name' => 'Maria', 'phone' => '11999990001',
        'whatsapp_opt_in_at' => $optedIn ? now() : null,
    ])->fresh();
    $original = $customer->getAttributes();
    $company = whatsappChatCompany();
    $chat = whatsappChatComponent($company);
    $chat->whatsappOptIn = true;
    $chat->submitPhone();
    expect($chat->name)->toBe('')
        ->and(Customer::withoutGlobalScopes()->where('company_id', $company->id)->exists())->toBeFalse()
        ->and($customer->fresh()->getAttributes())->toBe($original);
})->with([true, false]);
