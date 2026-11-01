<?php

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

use App\Jobs\ProcessVindiWebhook;
use App\Services\Payment\VindiAuthService;
use App\Services\Refund\VindiRefundGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;

uses(RefreshDatabase::class);

test('webhook e estorno não registram tokens nem corpo de resposta com dados do pagador', function () {
    Queue::fake([ProcessVindiWebhook::class]);
    config(['payments.vindi_token_account' => 'account-sensitive']);
    $logs = new TestHandler;
    foreach (['webhook', 'payments'] as $channel) {
        Log::channel($channel)->getLogger()->pushHandler($logs);
    }
    $this->post('/webhooks/vindi', ['transaction' => ['seller_token' => 'account-sensitive', 'transaction_token' => 'transaction-sensitive', 'status_name' => 'Aprovada']])->assertOk();
    $this->post('/webhooks/vindi', ['transaction' => ['seller_token' => 'wrong-sensitive']])->assertUnauthorized();
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx);
    $payment = $order->payments()->create(['amount' => $order->total, 'status' => 'pending', 'payment_gateway' => 'vindi', 'payment_token' => 'local-sensitive', 'vindi_transaction_id' => 99, 'vindi_transaction_token' => 'transaction-sensitive']);
    $auth = Mockery::mock(VindiAuthService::class);
    $auth->shouldReceive('getAccessToken')->andReturn('access-sensitive');
    Http::fake(['*' => Http::response(['data_response' => ['transaction' => ['status_name' => 'Estornada', 'customer' => ['tax_id' => 'cpf-sensitive', 'phone' => 'phone-sensitive']]]])]);
    expect((new VindiRefundGateway($auth))->requestRefund($payment, 60)['status'])->toBe('succeeded');
    $logged = json_encode(array_map(fn ($record) => [$record->message, $record->context], $logs->getRecords()));
    foreach (['account-sensitive', 'transaction-sensitive', 'wrong-sensitive', 'access-sensitive', 'cpf-sensitive', 'phone-sensitive'] as $secret) {
        expect($logged)->not->toContain($secret);
    }
});

test('VindiService não registra corpo, tokens ou dados do pagador na geração e consulta', function () {
    $ctx = flowAuditContext();
    $logs = new TestHandler;
    foreach (['payments', 'discord', 'discord_pix'] as $channel) {
        Log::channel($channel)->getLogger()->pushHandler($logs);
    }
    config(['payments.vindi_token_account' => 'sensitive-account']);
    $failure = false;
    Http::fake(function () use (&$failure) {
        if ($failure) {
            return Http::response(['customer' => ['cpf' => 'sensitive-cpf'], 'token' => 'sensitive-token'], 500);
        }

        return Http::response(['data_response' => ['transaction' => [
            'token_transaction' => 'sensitive-transaction', 'status_name' => 'Aprovada',
            'customer' => ['cpf' => 'sensitive-cpf', 'phone' => 'sensitive-phone'],
            'payment' => ['card_token' => 'sensitive-card-token', 'card_number' => 'sensitive-card-number', 'qrcode_original_path' => 'fake-pix'],
        ]]]);
    });
    $service = app(\App\Services\Payment\VindiService::class);
    $service->createPixCharge(20, 'ref-1', $ctx['customer']);
    $service->createCreditCardCharge(20, 'ref-2',
        new \App\DTOs\CreditCardDTO('Cliente', '4111111111111111', '12', '2030', '123'),
        new \App\DTOs\CreditCardHolderDTO('Cliente', 'fake@example.test', '52998224725', '01001000', '1'),
        \App\Enums\CardBrand::Visa);
    $service->getTransactionStatus('sensitive-transaction');
    $failure = true;
    expect($service->getTransactionStatus('sensitive-transaction'))->toBe('unknown');
    expect(fn () => $service->createPixCharge(20, 'ref-error', $ctx['customer']))->toThrow(\RuntimeException::class, 'Vindi API error 500');
    $logged = json_encode(array_map(fn ($record) => [$record->message, $record->context], $logs->getRecords()));
    foreach (['sensitive-', '52998224725', '11977770001', '4111111111111111'] as $secret) {
        expect($logged)->not->toContain($secret);
    }
});
