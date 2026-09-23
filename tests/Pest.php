<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| PDV helpers
|--------------------------------------------------------------------------
|
| Compartilhados entre TerminalTest (venda direta) e TabTerminalTest
| (mesa/comanda) — cada arquivo precisa rodar isoladamente, então os
| helpers ficam aqui em vez de redeclarados em cada um.
|
*/

function pdvContext(): array
{
    $company = \App\Models\Company::create([
        'name' => 'PDV Teste',
        'slug' => 'pdv-teste-'.uniqid(),
        'order_prefix' => 'PDV',
        'active' => true,
        'plan' => 'pro',
        'pdv_module_enabled' => true,
        'waiter_module_enabled' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = \App\Models\Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Balcão',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $category = \App\Models\ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Salgados',
        'active' => true,
        'sort_order' => 1,
    ]);

    $product = \App\Models\Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Coxinha',
        'price' => 8.00,
        'active' => true,
        'sort_order' => 1,
    ]);

    \Illuminate\Support\Facades\DB::table('branch_product')->insert([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available' => 1,
    ]);

    $admin = \App\Models\User::factory()->create(['is_super_admin' => true]);

    \App\Models\PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'opening_amount' => 0,
    ]);

    return compact('company', 'branch', 'category', 'product', 'admin');
}

/** Usuário com papel garçom: só `pdv.waiter_operate`, sem `pdv.operate`, restrito à filial informada. */
function makeWaiter(\App\Models\Company $company, \App\Models\Branch $branch): \App\Models\User
{
    $permission = \App\Models\Permission::firstOrCreate(
        ['name' => 'pdv.waiter_operate'],
        ['group' => 'pdv', 'label' => 'Operar PDV (garçom — mesas e comandas)']
    );

    $waiter = \App\Models\User::factory()->create();

    $waiter->companies()->attach($company->id, [
        'role' => 'garcom',
        'branch_id' => $branch->id,
    ]);

    \App\Models\UserPermission::create([
        'user_id' => $waiter->id,
        'company_id' => $company->id,
        'permission_id' => $permission->id,
        'granted' => true,
    ]);

    return $waiter;
}

/** Mesa registrada de antemão — abrir comanda no PDV agora exige mesa cadastrada. */
function openTable(\App\Models\Company $company, \App\Models\Branch $branch, int $number = 5): \App\Models\RestaurantTable
{
    return \App\Models\RestaurantTable::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'number' => $number,
        'active' => true,
    ]);
}

/*
|--------------------------------------------------------------------------
| iFood helpers
|--------------------------------------------------------------------------
|
| Compartilhados entre os testes de webhook/polling/mapper/job da integração
| iFood — empresa + filial + produto já mapeado (branch_product.ifood_item_id)
| + IfoodIntegration prontos para uso.
|
*/

function ifoodContext(string $suffix = 'A'): array
{
    $company = \App\Models\Company::create([
        'name' => "Empresa iFood {$suffix}",
        'slug' => "empresa-ifood-ctx-{$suffix}-".uniqid(),
        'order_prefix' => 'IFC'.strtoupper(substr($suffix, 0, 1)),
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = \App\Models\Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => "Filial {$suffix}",
        'address' => 'Rua X, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $category = \App\Models\ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Salgados',
        'active' => true,
        'sort_order' => 1,
    ]);

    $product = \App\Models\Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Coxinha',
        'price' => 8.00,
        'active' => true,
        'available_in_ifood' => true,
        'sort_order' => 1,
    ]);

    \Illuminate\Support\Facades\DB::table('branch_product')->insert([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available' => 1,
        'ifood_item_id' => "ifood-item-coxinha-{$suffix}",
    ]);

    $integration = \App\Models\IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'merchant_id' => "merchant-ctx-{$suffix}",
        'access_token' => "access-ctx-{$suffix}",
        'refresh_token' => "refresh-ctx-{$suffix}",
        'token_expires_at' => now()->addHours(6),
        'status' => 'active',
    ]);

    return compact('company', 'branch', 'category', 'product', 'integration');
}

/** Pedido iFood mínimo pra testar ações de admin (accept/reject/cancel) sobre um ifoodContext(). */
function ifoodKanbanOrder(array $ctx, string $status = 'paid', string $externalOrderId = 'ifood-kanban-1'): \App\Models\Order
{
    return \App\Models\Order::create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'customer_id' => \App\Models\Customer::withoutGlobalScopes()->create([
            'company_id' => $ctx['company']->id,
            'name' => 'Cliente iFood',
            'phone' => '11999990000',
        ])->id,
        'subtotal' => 20.00,
        'delivery_fee' => 5.00,
        'total' => 25.00,
        'discount' => 0,
        'fee' => 0,
        'net_value' => 25.00,
        'status' => $status,
        'payment_method' => 'ifood',
        'order_type' => 'delivery',
        'channel' => 'ifood',
        'external_order_id' => $externalOrderId,
    ]);
}

/** Payload de detalhes de pedido (GET /order/v1.0/orders/{id}) — 1 item, sem complemento. */
function ifoodOrderDetailsPayload(string $ifoodOrderId, string $merchantId, string $ifoodItemId, int $quantity = 2): array
{
    return [
        'id' => $ifoodOrderId,
        'displayId' => '1234',
        'merchant' => ['id' => $merchantId],
        'orderType' => 'DELIVERY',
        'createdAt' => now()->toIso8601String(),
        'customer' => [
            'name' => 'Cliente iFood',
            'phone' => ['number' => '11988887777'],
        ],
        'delivery' => [
            'deliveryAddress' => [
                'streetName' => 'Rua das Coxinhas',
                'streetNumber' => '100',
                'neighborhood' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
                'postalCode' => '01000-000',
                'coordinates' => ['latitude' => -23.55, 'longitude' => -46.63],
            ],
        ],
        'items' => [
            [
                'id' => $ifoodItemId,
                'name' => 'Coxinha',
                'quantity' => $quantity,
                'unitPrice' => 8.00,
                'options' => [],
            ],
        ],
        'payments' => ['methods' => [['type' => 'PREPAID']]],
        'total' => [
            'subTotal' => 8.00 * $quantity,
            'deliveryFee' => 5.00,
            'discount' => 0.00,
            'orderAmount' => (8.00 * $quantity) + 5.00,
        ],
    ];
}

/*
|--------------------------------------------------------------------------
| WhatsApp helpers
|--------------------------------------------------------------------------
|
| Compartilhados pelos testes de tests/Feature/WhatsApp — cada arquivo precisa
| rodar isoladamente, então os helpers ficam aqui.
|
*/

/**
 * Empresa com WhatsApp ligado, cliente com opt-in e um pedido de entrega do chat.
 * Por padrão a empresa tem conexão ativa com todos os templates aprovados.
 *
 * @param  array<string, mixed>  $orderAttributes
 * @return array{company: \App\Models\Company, branch: \App\Models\Branch, customer: \App\Models\Customer, order: \App\Models\Order, connection: ?\App\Models\WhatsAppConnection, settings: \App\Models\WhatsAppSetting}
 */
function whatsappOrderContext(array $orderAttributes = [], bool $withConnection = true, bool $optedIn = true, string $phone = '11999990001'): array
{
    $company = \App\Models\Company::create([
        'name' => 'Restaurante '.uniqid(),
        'slug' => 'restaurante-'.uniqid(),
        'order_prefix' => 'WPP',
        'active' => true,
        'status' => 'ACTIVE',
    ]);

    app()->instance('current.company', $company);

    $branch = \App\Models\Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Central',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = \App\Models\Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Maria Silva',
        'phone' => $phone,
        'whatsapp_opt_in_at' => $optedIn ? now() : null,
    ]);

    $settings = \App\Models\WhatsAppSetting::create(['company_id' => $company->id, 'enabled' => true]);

    $connection = null;
    if ($withConnection) {
        $connection = \App\Models\WhatsAppConnection::factory()->forCompany($company)->create();

        foreach (array_keys(config('whatsapp_templates.templates')) as $event) {
            \App\Models\WhatsAppTemplate::factory()->forEvent($event)->create(['whatsapp_connection_id' => $connection->id]);
        }
    }

    $order = \App\Models\Order::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 50.00,
        'total' => 50.00,
        'status' => 'preparing',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
    ], $orderAttributes));

    return compact('company', 'branch', 'customer', 'order', 'connection', 'settings');
}

/** Resposta de sucesso da Graph API para POST /{phone_number_id}/messages. */
function whatsappSendSuccess(string $wamid = 'wamid.HBgLNTUxMTk5OTk5MDAwMRUCABEYEjk'): \GuzzleHttp\Promise\PromiseInterface
{
    return \Illuminate\Support\Facades\Http::response([
        'messaging_product' => 'whatsapp',
        'contacts' => [['input' => '5511999990001', 'wa_id' => '5511999990001']],
        'messages' => [['id' => $wamid]],
    ], 200);
}

/** Resposta de erro da Graph API. */
function whatsappGraphError(int $code, int $status = 400, string $message = 'Erro de teste'): \GuzzleHttp\Promise\PromiseInterface
{
    return \Illuminate\Support\Facades\Http::response([
        'error' => [
            'message' => $message,
            'type' => 'OAuthException',
            'code' => $code,
            'fbtrace_id' => 'AbCdEf123',
        ],
    ], $status);
}

/*
| Webhook WhatsApp: builders de payload no formato da Cloud API e POST assinado.
*/

/** @param  array<int, array<string, mixed>>  $changes */
function whatsappWebhookPayload(string $wabaId, array $changes): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [['id' => $wabaId, 'changes' => $changes]],
    ];
}

function whatsappMetadata(string $phoneNumberId): array
{
    return ['display_phone_number' => '551199990001', 'phone_number_id' => $phoneNumberId];
}

/** @param  array<string, mixed>  $extra */
function whatsappStatusChange(string $phoneNumberId, string $wamid, string $status, ?int $timestamp = null, array $extra = []): array
{
    return [
        'field' => 'messages',
        'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => whatsappMetadata($phoneNumberId),
            'statuses' => [array_merge([
                'id' => $wamid,
                'status' => $status,
                'timestamp' => (string) ($timestamp ?? time()),
                'recipient_id' => '5511999990001',
            ], $extra)],
        ],
    ];
}

function whatsappIncomingChange(string $phoneNumberId, string $from, string $body, string $type = 'text'): array
{
    $message = ['from' => $from, 'id' => 'wamid.IN'.uniqid(), 'timestamp' => (string) time(), 'type' => $type];

    if ($type === 'text') {
        $message['text'] = ['body' => $body];
    }

    return [
        'field' => 'messages',
        'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => whatsappMetadata($phoneNumberId),
            'contacts' => [['profile' => ['name' => 'Cliente'], 'wa_id' => $from]],
            'messages' => [$message],
        ],
    ];
}

function whatsappTemplateChange(string $event, string $name, string $language = 'pt_BR', string $reason = 'NONE', int $templateId = 990001): array
{
    return [
        'field' => 'message_template_status_update',
        'value' => [
            'event' => $event,
            'message_template_id' => $templateId,
            'message_template_name' => $name,
            'message_template_language' => $language,
            'reason' => $reason,
        ],
    ];
}

/** Evento sem metadata (nível WABA), como account_update e phone_number_quality_update. */
function whatsappWabaChange(string $field, array $value): array
{
    return ['field' => $field, 'value' => $value];
}

/** Processa o payload de forma síncrona, como o job faria na fila. */
function processWhatsAppWebhook(array $payload): void
{
    (new \App\Jobs\ProcessWhatsAppWebhook($payload))->handle(app(\App\Services\Messaging\WhatsAppWebhookProcessor::class));
}

/** POST assinado no webhook (o corpo enviado é exatamente o que foi assinado). */
function whatsappSignedPost(\Tests\TestCase $test, array $payload, ?string $signature = null, string $secret = 'app-secret-teste'): \Illuminate\Testing\TestResponse
{
    $body = json_encode($payload);
    $signature ??= 'sha256='.hash_hmac('sha256', $body, $secret);

    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if ($signature !== '') {
        $server['HTTP_X_HUB_SIGNATURE_256'] = $signature;
    }

    return $test->call('POST', '/webhooks/whatsapp', [], [], [], $server, $body);
}

function whatsappCustomer(\App\Models\Company $company, string $phone = '11999990001', bool $optedIn = true): \App\Models\Customer
{
    return \App\Models\Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente '.$phone,
        'phone' => $phone,
        'whatsapp_opt_in_at' => $optedIn ? now() : null,
    ]);
}

/** Mensagem já enviada (com wamid) por uma conexão; sem pedido, para não depender de Order. */
function whatsappSentMessage(?\App\Models\WhatsAppConnection $connection, string $wamid, ?int $companyId = null, string $toPhone = '5511999990001'): \App\Models\WhatsAppMessage
{
    return \App\Models\WhatsAppMessage::factory()->sent()->create([
        'company_id' => $connection?->company_id ?? $companyId,
        'whatsapp_connection_id' => $connection?->id,
        'wamid' => $wamid,
        'to_phone' => $toPhone,
    ]);
}

/** Captura o que for logado no canal whatsapp (para provar que segredos e textos de clientes não vazam). */
function whatsappCaptureLogs(): \Monolog\Handler\TestHandler
{
    config(['logging.channels.whatsapp' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\TestHandler::class]]);
    \Illuminate\Support\Facades\Log::forgetChannel('whatsapp');

    return \Illuminate\Support\Facades\Log::channel('whatsapp')->getLogger()->getHandlers()[0];
}

/** Captura o que for logado no canal discord (erros críticos que a equipe da plataforma precisa ver). */
function whatsappCaptureDiscord(): \Monolog\Handler\TestHandler
{
    config(['logging.channels.discord' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\TestHandler::class]]);
    \Illuminate\Support\Facades\Log::forgetChannel('discord');

    return \Illuminate\Support\Facades\Log::channel('discord')->getLogger()->getHandlers()[0];
}

/*
| Onboarding/templates: Graph API falsa com roteador por "MÉTODO rota". Rota não mockada
| (ou chamada inesperada) devolve 404, então o teste falha alto em vez de passar por engano.
*/

/**
 * @param  array<string, mixed>  $handlers  Sobrescreve respostas: array (JSON 200), resposta Http::response() ou Closure(Request).
 *                                          Chaves: 'GET oauth/access_token', 'GET debug_token', 'POST subscribed_apps',
 *                                          'DELETE subscribed_apps', 'GET phone_numbers', 'POST register', 'GET phone',
 *                                          'GET message_templates', 'POST message_templates', 'POST messages'.
 */
function whatsappFakeMeta(array $handlers = [], string $wabaId = '1000000000001', string $phoneId = '2000000000001'): void
{
    // Fábrica nova a cada chamada: fakes se acumulam (o primeiro que casa vence) e o histórico de
    // requisições também, então chamar de novo no mesmo teste sem isso ignoraria as novas respostas.
    \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory);
    \Illuminate\Support\Facades\Http::preventStrayRequests();

    config([
        'services.meta.app_id' => '111222333',
        'services.meta.app_secret' => 'app-secret-teste',
        'services.meta.graph_version' => 'v25.0',
    ]);

    $defaults = [
        'GET oauth/access_token' => ['access_token' => 'EAAB-token-do-restaurante', 'token_type' => 'bearer'],
        'GET debug_token' => ['data' => [
            'app_id' => '111222333',
            'type' => 'SYSTEM_USER',
            'is_valid' => true,
            'expires_at' => 0,
            'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging'],
            'granular_scopes' => [
                ['scope' => 'whatsapp_business_management', 'target_ids' => [$wabaId]],
                ['scope' => 'whatsapp_business_messaging', 'target_ids' => [$wabaId]],
            ],
        ]],
        'POST subscribed_apps' => ['success' => true],
        'DELETE subscribed_apps' => ['success' => true],
        'GET phone_numbers' => ['data' => [[
            'id' => $phoneId, 'display_phone_number' => '+55 11 99999-0001',
            'verified_name' => 'Restaurante Teste', 'quality_rating' => 'GREEN',
        ]]],
        'POST register' => ['success' => true],
        'GET phone' => [
            'id' => $phoneId, 'verified_name' => 'Restaurante Teste', 'display_phone_number' => '+55 11 99999-0001',
            'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_250',
        ],
        'GET message_templates' => ['data' => []],
        'POST message_templates' => fn () => ['id' => (string) random_int(10 ** 14, 10 ** 15), 'status' => 'PENDING', 'category' => 'UTILITY'],
        'POST messages' => ['messages' => [['id' => 'wamid.TESTE']]],
    ];

    $routes = array_merge($defaults, $handlers);

    \Illuminate\Support\Facades\Http::fake(function (\Illuminate\Http\Client\Request $request) use ($routes, $phoneId) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $method = $request->method();

        $route = match (true) {
            str_ends_with($path, '/oauth/access_token') => 'oauth/access_token',
            str_ends_with($path, '/debug_token') => 'debug_token',
            str_ends_with($path, '/subscribed_apps') => 'subscribed_apps',
            str_ends_with($path, '/phone_numbers') => 'phone_numbers',
            str_ends_with($path, '/register') => 'register',
            str_ends_with($path, '/message_templates') => 'message_templates',
            str_ends_with($path, '/messages') => 'messages',
            str_ends_with($path, '/'.$phoneId) => 'phone',
            default => $path,
        };

        $handler = $routes["{$method} {$route}"] ?? null;

        if ($handler === null) {
            return \Illuminate\Support\Facades\Http::response(['error' => ['message' => "Rota não mockada: {$method} {$path}", 'code' => 1]], 404);
        }

        $response = $handler instanceof \Closure ? $handler($request) : $handler;

        return is_array($response) ? \Illuminate\Support\Facades\Http::response($response, 200) : $response;
    });
}

/** Chamadas feitas à Graph API, na ordem, como "MÉTODO rota" (ex.: "POST register"). */
function whatsappMetaCalls(): array
{
    return \Illuminate\Support\Facades\Http::recorded()
        ->map(function (array $pair) {
            $path = (string) parse_url($pair[0]->url(), PHP_URL_PATH);
            $segments = explode('/', trim($path, '/'));

            // /v25.0/{id}/rota  ou  /v25.0/rota  ou  /v25.0/{id}
            $last = end($segments);

            $route = match (true) {
                count($segments) === 2 && ctype_digit($last) => 'phone',
                $last === 'access_token' => 'oauth/access_token',
                default => $last,
            };

            return $pair[0]->method().' '.$route;
        })
        ->values()
        ->all();
}

/** Corpo/consulta das requisições recorded cujo método+rota casam. */
function whatsappMetaRequests(string $methodAndRoute): \Illuminate\Support\Collection
{
    return \Illuminate\Support\Facades\Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(function (\Illuminate\Http\Client\Request $request) use ($methodAndRoute) {
            [$method, $route] = explode(' ', $methodAndRoute, 2);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return $request->method() === $method && str_ends_with($path, '/'.$route);
        })
        ->values();
}
