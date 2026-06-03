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

function mockProvider(string $status = 'authorized'): void
{
    $result = new FiscalNoteResult(
        status: $status,
        providerReference: 'ref_test_001',
        accessKey: str_repeat('0', 44),
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

            public function query(string $key): FiscalNoteResult
            {
                return $this->r;
            }
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
