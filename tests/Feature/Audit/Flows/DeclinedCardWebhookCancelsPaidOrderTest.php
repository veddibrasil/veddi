<?php

require_once __DIR__.'/flows_helpers.php';

use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessVindiWebhook;
use App\Models\CompanyWalletEntry;
use App\Models\Payment;
use App\Services\Payment\PaymentOrchestrator;
use App\Services\Payment\VindiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
 * AUDITORIA FLUXOS — cartão recusado na hora fica gravado como Payment 'pending'
 * (PaymentOrchestrator::processCreditCard só distingue paid/pending). O chat deixa o
 * cliente tentar outro cartão no MESMO pedido. Quando a Vindi manda o webhook de status
 * da 1ª transação ('Não Aprovada'), ProcessVindiWebhook::handlePaymentFailed acha esse
 * Payment ainda 'pending' e cancela o pedido sem olhar o status dele — um pedido já pago
 * pela 2ª tentativa vira 'cancelado' (cozinha não prepara, carteira continua creditada,
 * nenhum estorno).
 */

beforeEach(function () {
    Event::fake([OrderStatusUpdated::class]);
});

test('webhook de recusa da 1ª tentativa de cartão não cancela o pedido já pago pela 2ª tentativa', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, qty: 1, status: 'pending', paymentMethod: 'CARD');

    config()->set('payments.vindi_token_account', 'tok_conta_teste');

    $vindi = Mockery::mock(VindiService::class);
    $vindi->shouldReceive('createCreditCardCharge')->twice()->andReturn(
        ['transaction_token' => 'tok_recusada', 'transaction_id' => '1', 'status_name' => 'Não Aprovada', 'payment_response' => 'Saldo insuficiente'],
        ['transaction_token' => 'tok_aprovada', 'transaction_id' => '2', 'status_name' => 'Aprovada'],
    );
    app()->instance(VindiService::class, $vindi);

    $card = [
        'holderName' => 'CLIENTE FLUXO', 'number' => '4111111111111111', 'expiryMonth' => '12',
        'expiryYear' => '2030', 'ccv' => '123', 'cpfCnpj' => '52998224725', 'postalCode' => '01001000',
        'addressNumber' => '1',
    ];

    // 1ª tentativa: recusa síncrona (o chat mostra o erro e deixa tentar outro cartão).
    $first = app(PaymentOrchestrator::class)->processCreditCard($order, $ctx['customer'], $ctx['company'], $card);
    expect($first['declined'])->toBeTrue();

    // 2ª tentativa no mesmo pedido: aprovada → pedido pago e carteira creditada.
    $second = app(PaymentOrchestrator::class)->processCreditCard($order->fresh(), $ctx['customer'], $ctx['company'], $card);
    expect($second['approved'])->toBeTrue();
    expect($order->fresh()->status)->toBe('paid');

    // A 1ª transação ficou gravada como 'pending' mesmo tendo sido recusada.
    expect(Payment::where('vindi_transaction_token', 'tok_recusada')->value('status'))->toBe('pending');

    // Minutos depois chega o webhook de status da transação recusada.
    (new ProcessVindiWebhook('tok_recusada', 'Não Aprovada', []))->handle();

    expect($order->fresh()->status)->toBe('paid');
    expect(CompanyWalletEntry::where('order_id', $order->id)->where('type', 'credit')->count())->toBe(1);
});
