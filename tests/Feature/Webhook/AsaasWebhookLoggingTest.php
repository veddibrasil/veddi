<?php

use App\Jobs\ProcessAsaasWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

uses(RefreshDatabase::class);

test('webhook Asaas não loga o payload bruto completo, só o evento', function () {
    Bus::fake();
    config(['logging.channels.webhook' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    config(['services.asaas.webhook_token' => 'tok_test']);

    $this->postJson('/webhooks/asaas', [
        'event' => 'PAYMENT_CONFIRMED',
        'payment' => ['id' => 'pay_123'],
        'customer' => ['cpfCnpj' => '12345678900', 'name' => 'Fulano da Silva'],
    ], ['asaas-access-token' => 'tok_test'])->assertStatus(200);

    Bus::assertDispatched(ProcessAsaasWebhook::class);

    /** @var TestHandler $handler */
    $handler = Log::channel('webhook')->getLogger()->getHandlers()[0];
    $records = $handler->getRecords();

    foreach ($records as $record) {
        expect(json_encode($record['context']))
            ->not->toContain('12345678900')
            ->not->toContain('Fulano da Silva');
    }
});

test('webhook Asaas com token inválido não loga nada sensível', function () {
    config(['logging.channels.webhook' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    config(['services.asaas.webhook_token' => 'tok_test']);

    $this->postJson('/webhooks/asaas', [
        'event' => 'PAYMENT_CONFIRMED',
        'customer' => ['cpfCnpj' => '12345678900'],
    ], ['asaas-access-token' => 'tok_errado'])->assertStatus(401);

    /** @var TestHandler $handler */
    $handler = Log::channel('webhook')->getLogger()->getHandlers()[0];

    foreach ($handler->getRecords() as $record) {
        expect(json_encode($record['context']))->not->toContain('12345678900');
    }
});
