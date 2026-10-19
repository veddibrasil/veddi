<?php

use App\Contracts\FiscalNoteProviderInterface;
use App\DTOs\FiscalNoteDTO;
use App\DTOs\FiscalNoteResult;
use App\Events\OrderStatusUpdated;
use App\Jobs\IssueFiscalNote;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\Customer;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Services\Fiscal\FiscalNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function manualIssueTestContext(bool $planEnabled = true, bool $configEnabled = true, string $orderStatus = 'paid', string $orderType = 'delivery', string $channel = 'chat'): array
{
    $company = Company::create([
        'name' => 'Empresa Fiscal Auto',
        'slug' => 'empresa-fiscal-auto-'.uniqid(),
        'order_prefix' => 'AUT',
        'active' => true,
        'status' => 'ACTIVE',
        'owner_cpf_cnpj' => '12345678000100',
        'fiscal_notes_enabled' => $planEnabled,
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

    CompanyFiscalConfig::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'is_default' => true,
        'enabled' => $configEnabled,
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
        'status' => $orderStatus,
        'payment_method' => 'PIX',
        'order_type' => $orderType,
        'channel' => $channel,
    ]);

    return compact('company', 'order');
}

test('pedido que vira pago não emite nota sozinho, em nenhum canal', function (string $orderType, string $channel) {
    Bus::fake();
    ['order' => $order] = manualIssueTestContext(orderType: $orderType, channel: $channel);

    event(new OrderStatusUpdated($order->fresh()));

    Bus::assertNotDispatched(IssueFiscalNote::class);
    expect(FiscalNote::where('order_id', $order->id)->exists())->toBeFalse();
})->with([
    'chat' => ['delivery', 'chat'],
    'pdv' => ['pdv', 'chat'],
    'ifood' => ['delivery', 'ifood'],
]);

test('rodar o job de emissão duas vezes para o mesmo pedido (corrida real) só cria uma nota fiscal', function () {
    // Sem Bus::fake(): executa o job de verdade duas vezes, simulando dois disparos
    // concorrentes (evento de pagamento duplicado, retry) chegando quase juntos.
    ['order' => $order, 'company' => $company] = manualIssueTestContext();
    $company->update(['owner_cpf_cnpj' => '12345678000100']);

    app()->bind(FiscalNoteProviderInterface::class, function () {
        return new class implements FiscalNoteProviderInterface
        {
            public function issue(FiscalNoteDTO $dto): FiscalNoteResult
            {
                return new FiscalNoteResult(status: 'authorized', providerReference: 'ref_race', accessKey: str_repeat('1', 44));
            }

            public function cancel(string $providerReference, string $justification): FiscalNoteResult
            {
                return new FiscalNoteResult(status: 'cancelled', providerReference: $providerReference);
            }

            public function query(string $providerReference): FiscalNoteResult
            {
                return new FiscalNoteResult(status: 'authorized', providerReference: $providerReference);
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

    $service = app(FiscalNoteService::class);
    (new IssueFiscalNote($order->id))->handle($service);
    (new IssueFiscalNote($order->id))->handle($service);

    expect(FiscalNote::where('order_id', $order->id)->count())->toBe(1);
    expect(FiscalNote::where('order_id', $order->id)->first()->status)->toBe('authorized');
});
