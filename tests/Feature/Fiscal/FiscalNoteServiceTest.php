<?php

use App\Contracts\FiscalNoteProviderInterface;
use App\DTOs\FiscalNoteResult;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Fiscal\FiscalNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function fiscalTestContext(): array
{
    $company = Company::create([
        'name' => 'Empresa Fiscal',
        'slug' => 'empresa-fiscal-'.uniqid(),
        'order_prefix' => 'FIS',
        'active' => true,
        'status' => 'ACTIVE',
        'owner_cpf_cnpj' => '12345678000100',
        'fiscal_notes_enabled' => true,
    ]);

    config(['fiscal.focus_nfe.token' => 'token_test']);

    $config = CompanyFiscalConfig::create([
        'company_id' => $company->id,
        'enabled' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua A',
        'number' => '100',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'state' => 'SP',
        'cep' => '01310000',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente',
        'phone' => '11999990001',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 30.00,
        'delivery_fee' => 0,
        'total' => 30.00,
        'fee' => 0,
        'net_value' => 30.00,
        'status' => 'paid',
        'payment_method' => 'PIX',
        'order_type' => 'delivery',
    ]);

    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Salgados',
    ]);

    $product = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Coxinha',
        'price' => 10.00,
        'active' => true,
        'fiscal_data' => ['ncm' => '21069090', 'cfop' => '5102', 'icms_situation' => '400'],
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => 'Coxinha',
        'unit_price' => 10.00,
        'quantity' => 3,
        'subtotal' => 30.00,
    ]);

    return compact('company', 'config', 'order', 'branch');
}

function mockProvider(string $status = 'authorized', ?string $accessKey = null): void
{
    $result = new FiscalNoteResult(
        status: $status,
        providerReference: 'ref_test_001',
        accessKey: $accessKey ?? str_repeat('0', 44),
        xmlUrl: 'https://focus.test/xml',
        danfeUrl: 'https://focus.test/danfe',
    );

    app()->bind(FiscalNoteProviderInterface::class, function () use ($result) {
        return new class($result) implements FiscalNoteProviderInterface
        {
            public function __construct(private FiscalNoteResult $r) {}

            public function issue(\App\DTOs\FiscalNoteDTO $dto): FiscalNoteResult
            {
                return $this->r;
            }

            public function cancel(string $ref, string $justification): FiscalNoteResult
            {
                return $this->r;
            }

            public function query(string $providerReference): FiscalNoteResult
            {
                return $this->r;
            }

            public function createCompany(array $payload): array
            {
                return [];
            }

            public function updateCompany(string $focusCompanyId, array $payload): array
            {
                return [];
            }

            public function listWebhooks(): array
            {
                return [];
            }

            public function createWebhook(array $payload): array
            {
                return [];
            }

            public function deleteWebhook(string $webhookId): void {}
        };
    });
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('FiscalNoteService emite nota e persiste com status authorized', function () {
    ['order' => $order] = fiscalTestContext();
    mockProvider('authorized');

    $note = app(FiscalNoteService::class)->issue($order);

    expect($note->status)->toBe('authorized');
    expect($note->provider_reference)->toBe('ref_test_001');
    expect($note->access_key)->toHaveLength(44);
    expect($note->data['danfe_url'])->toBe('https://focus.test/danfe');
});

test('FiscalNoteService persiste access_key com prefixo NFe (47 chars) sem truncar', function () {
    // Focus NFe às vezes retorna chave_nfe com prefixo "NFe" além dos 44 dígitos
    // (visto em produção, fiscal-2026-07-15.log) — char(44) truncava e quebrava o update.
    ['order' => $order] = fiscalTestContext();
    mockProvider('authorized', 'NFe'.str_repeat('1', 44));

    $note = app(FiscalNoteService::class)->issue($order);

    expect($note->status)->toBe('authorized');
    expect($note->access_key)->toBe('NFe'.str_repeat('1', 44));
    expect($note->access_key)->toHaveLength(47);
});

test('FiscalNoteService lança exceção quando fiscal_notes_enabled = false', function () {
    ['order' => $order, 'company' => $company] = fiscalTestContext();
    $company->update(['fiscal_notes_enabled' => false]);
    mockProvider();

    expect(fn () => app(FiscalNoteService::class)->issue($order))
        ->toThrow(\RuntimeException::class, 'não habilitado');
});

test('FiscalNoteService lança exceção quando config disabled', function () {
    ['order' => $order, 'config' => $config] = fiscalTestContext();
    $config->update(['enabled' => false]);
    mockProvider();

    expect(fn () => app(FiscalNoteService::class)->issue($order))
        ->toThrow(\RuntimeException::class, 'Configuração fiscal não habilitada');
});

test('canUseFiscalNotes retorna true somente com fiscal_notes_enabled = true', function () {
    ['company' => $company] = fiscalTestContext();

    expect($company->canUseFiscalNotes())->toBeTrue();

    $company->update(['fiscal_notes_enabled' => false]);
    $company->refresh();

    expect($company->canUseFiscalNotes())->toBeFalse();
});

test('FiscalNoteService usa token de homologação da empresa mesmo fora de produção (URL continua homologação por segurança)', function () {
    ['order' => $order, 'config' => $config] = fiscalTestContext();

    $config->update([
        'token_producao' => 'token-producao-empresa',
        'token_homologacao' => 'token-homologacao-empresa',
        'environment' => 'producao',
        'crt' => 3,
        'nfce_serie' => 2,
        'inscricao_estadual' => '111222333',
    ]);

    config(['fiscal.focus_nfe.base_url_producao' => 'https://api.focusnfe.com.br']);
    config(['fiscal.focus_nfe.base_url_homologacao' => 'https://homologacao.focusnfe.com.br']);

    Http::fake([
        '*' => Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('1', 44)], 200),
    ]);

    app(FiscalNoteService::class)->issue($order);

    // APP_ENV de teste não é produção — mesmo com a empresa configurada como "produção",
    // a chamada real tem que ir pra homologação (guarda contra emitir nota fiscal real fora de prod),
    // usando o token de homologação da empresa, não o de produção.
    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), 'homologacao.focusnfe.com.br')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('token-homologacao-empresa:'))
            && ($request['cnpj_emitente'] ?? null) === '12345678000100';
    });
});

test('FiscalNoteService usa token legado (provider_token) quando dual token não está configurado', function () {
    ['order' => $order, 'config' => $config] = fiscalTestContext();

    $config->update([
        'provider_token' => 'token-legado',
        'environment' => 'homologacao',
    ]);

    Http::fake([
        '*' => Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('1', 44)], 200),
    ]);

    app(FiscalNoteService::class)->issue($order);

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('token-legado:')));
});

test('FiscalNoteService usa API de produção da Focus NFe quando APP_ENV é production e empresa está em produção', function () {
    ['order' => $order, 'config' => $config] = fiscalTestContext();

    $config->update([
        'token_producao' => 'token-producao-empresa',
        'token_homologacao' => 'token-homologacao-empresa',
        'environment' => 'producao',
    ]);

    config(['fiscal.focus_nfe.base_url_producao' => 'https://api.focusnfe.com.br']);

    app()->instance('env', 'production');

    Http::fake([
        '*' => Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('1', 44)], 200),
    ]);

    app(FiscalNoteService::class)->issue($order);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), 'api.focusnfe.com.br')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('token-producao-empresa:'));
    });

    app()->instance('env', 'testing');
});

test('FocusNfeService monta payload conforme especificação da API (sem objeto emitente, campos obrigatórios presentes)', function () {
    ['order' => $order] = fiscalTestContext();

    Http::fake([
        '*' => Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('1', 44)], 200),
    ]);

    app(FiscalNoteService::class)->issue($order);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $data = $request->data();

        return $request->hasHeader('Authorization', 'Basic '.base64_encode('token_test:'))
            && ! array_key_exists('emitente', $data)
            && ! array_key_exists('forma_pagamento', $data)
            && ($data['cnpj_emitente'] ?? null) === '12345678000100'
            && isset($data['data_emissao'])
            && ($data['modalidade_frete'] ?? null) === '9'
            && ($data['local_destino'] ?? null) === '1'
            && ($data['presenca_comprador'] ?? null) === '4';
    });
});

test('CompanyFiscalConfig::tokenFor retorna o token do ambiente certo e cai para o legado quando ausente', function () {
    ['config' => $config] = fiscalTestContext();

    $config->update([
        'token_producao' => 'tok-prod',
        'token_homologacao' => 'tok-homolog',
        'provider_token' => 'tok-legado',
    ]);

    expect($config->tokenFor('producao'))->toBe('tok-prod');
    expect($config->tokenFor('homologacao'))->toBe('tok-homolog');

    $config->update(['token_producao' => null, 'token_homologacao' => null]);
    $config->refresh();

    expect($config->tokenFor('producao'))->toBe('tok-legado');
    expect($config->tokenFor('homologacao'))->toBe('tok-legado');
});
