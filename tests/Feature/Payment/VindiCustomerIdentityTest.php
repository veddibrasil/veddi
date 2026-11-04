<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Chat\ChatAccess;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    Http::preventStrayRequests();
    Http::fake([
        '*/transactions/payment' => Http::response([
            'data_response' => ['transaction' => [
                'token_transaction' => 'tok_identity',
                'status_name' => 'Em análise',
                'payment' => ['qrcode_original_path' => '000201test'],
            ]],
        ]),
    ]);

    $this->company = Company::create([
        'name' => 'Empresa Identidade', 'slug' => 'empresa-identidade',
        'order_prefix' => 'VID', 'active' => true,
    ]);
    app()->instance('current.company', $this->company);
    $this->branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'name' => 'Filial', 'active' => true,
        'address' => 'Rua Teste', 'number' => '10', 'neighborhood' => 'Centro',
        'city' => 'São Paulo', 'state' => 'SP', 'cep' => '01310-100',
    ]);
    $this->customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'name' => 'Nome do primeiro cadastro',
        'phone' => '11999990000', 'email' => 'primeiro@example.com', 'tax_id' => '11144477735',
    ]);
    $this->cardData = [
        'holderName' => 'Nome impresso no cartão', 'number' => '4111111111111111',
        'expiryMonth' => '12', 'expiryYear' => '2030', 'ccv' => '123',
    ];
});

test('cobranças consecutivas enviam a identidade de cada pedido no PIX e cartão', function (string $method, bool $withEmail) {
    if ($method === 'saved_card') {
        app(ChatAccess::class)->verify($this->company->id, $this->customer->phone);
        $card = $this->customer->cards()->create([
            'vindi_card_token' => 'tok_saved', 'last_four' => '1111', 'brand' => 'visa',
        ]);
        $this->cardData = ['saved_card_id' => $card->id, 'ccv' => '123'];
    }

    $buyers = [
        ['name' => 'Ana Silva', 'tax_id' => '123.456.789-09', 'phone' => '11999990001', 'email' => $withEmail ? ' ana@example.com ' : null],
        ['name' => 'Bruno Souza', 'tax_id' => '98765432100', 'phone' => '11999990002', 'email' => $withEmail ? 'bruno@example.com' : '   '],
        ['name' => 'Ana Silva', 'tax_id' => '12345678909', 'phone' => '11999990001', 'email' => $withEmail ? 'ana@example.com' : ''],
    ];

    foreach ($buyers as $index => $buyer) {
        $order = Order::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'customer_id' => $method === 'saved_card' ? $this->customer->id : null,
            'subtotal' => 50, 'total' => 50, 'status' => 'pending',
            'payment_method' => $method === 'pix' ? 'pix' : 'credit_card',
            'order_type' => 'pickup', 'buyer_data' => $buyer,
        ]);

        // Mesmo que o chamador tenha um cadastro anterior em memória, o contato
        // confirmado neste pedido precisa ser o enviado ao gateway.
        $orchestrator = app(PaymentOrchestrator::class);
        if ($method === 'pix') {
            $orchestrator->processPix($order, $this->customer, $this->company);
        } else {
            $orchestrator->processCreditCard($order, $this->customer, $this->company, $this->cardData);
        }

        $request = Http::recorded()[$index][0];
        expect($request['customer']['name'])->toBe($buyer['name'])
            ->and($request['customer']['cpf'])->toBe(preg_replace('/\D/', '', $buyer['tax_id']))
            ->and($request['customer']['contacts'][0]['number_contact'])->toBe($buyer['phone']);
        if ($withEmail) {
            expect($request['customer']['email'])->toBe(trim($buyer['email']));
        } else {
            expect(filter_var($request['customer']['email'], FILTER_VALIDATE_EMAIL))->not->toBeFalse()
                ->and($request['customer']['email'])->toEndWith('@sememail.veddi.com.br');
        }
        if ($method === 'saved_card') {
            expect($request['payment']['card_token'])->toBe('tok_saved')
                ->and($request['payment'])->not->toHaveKey('card_number');
        } elseif ($method !== 'pix') {
            expect($request['payment']['card_name'])->toBe('Nome impresso no cartão');
        }
    }

    $requests = Http::recorded();
    expect($requests[0][0]['customer']['email'])->not->toBe($requests[1][0]['customer']['email'])
        ->and($requests[0][0]['customer']['email'])->toBe($requests[2][0]['customer']['email'])
        ->and($this->customer->fresh()->name)->toBe('Nome do primeiro cadastro')
        ->and($this->customer->fresh()->email)->toBe('primeiro@example.com');
    Http::assertSentCount(3);
})->with(['pix', 'new_card', 'saved_card'])->with([false, true]);

test('comprador sem email mantém a mesma identificação no PIX e cartão', function (?string $taxId, ?string $phone) {
    foreach (['pix', 'credit_card'] as $method) {
        $order = Order::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'subtotal' => 50, 'total' => 50, 'status' => 'pending',
            'payment_method' => $method, 'order_type' => 'pickup',
            'buyer_data' => ['name' => 'Ana Silva', 'tax_id' => $taxId, 'phone' => $phone],
        ]);
        app(PaymentOrchestrator::class)->process($order, $order->customer, $this->company, $method, $this->cardData);
    }

    $requests = Http::recorded();
    $pixEmail = $requests[0][0]['customer']['email'];
    $cardEmail = $requests[1][0]['customer']['email'];
    expect(filter_var($pixEmail, FILTER_VALIDATE_EMAIL))->not->toBeFalse()
        ->and(filter_var($cardEmail, FILTER_VALIDATE_EMAIL))->not->toBeFalse();
    if ($taxId || $phone) {
        expect($pixEmail)->toBe($cardEmail);
    } else {
        // Sem documento nem telefone, a referência exclusiva do pedido evita
        // voltar a enviar um e-mail genérico para compradores não identificados.
        expect($pixEmail)->not->toBe($cardEmail);
    }
})->with([
    'CPF' => ['12345678909', '11999990001'],
    'CNPJ' => ['12.345.678/0001-95', '11999990001'],
    'telefone' => [null, '11999990001'],
    'sem documento nem telefone' => [null, null],
]);
