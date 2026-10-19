<?php

use App\Events\OrderStatusUpdated;
use App\Jobs\SendWhatsAppOrderNotificationJob;
use App\Livewire\Chat\OrderChat;
use App\Models\Payment;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Cartão aprovado na hora segue a mesma regra dos webhooks Vindi/Asaas: pedido agendado para o
 * futuro fica 'scheduled' (sem aviso de "pago, na fila de preparo" nem nota fiscal antes da hora).
 */
beforeEach(function () {
    // Modo simulação: sem credenciais Vindi o cartão é aprovado direto.
    config([
        'payments.vindi_token_account' => null,
        'services.whatsapp.fallback_to_platform' => false,
    ]);
});

function approveCardFor(\App\Models\Order $order, \App\Models\Customer $customer, \App\Models\Company $company): array
{
    return app(PaymentOrchestrator::class)->processCreditCard($order, $customer, $company, [
        'holderName' => 'Maria Silva',
        'number' => '4111111111111111',
        'expiryMonth' => '12',
        'expiryYear' => '2030',
        'ccv' => '123',
        'cpfCnpj' => '98765432100',
        'postalCode' => '01310-100',
        'addressNumber' => '1',
    ]);
}

test('cartão aprovado mantém pedido agendado para o futuro como scheduled', function () {
    ['order' => $order, 'customer' => $customer, 'company' => $company] = whatsappOrderContext([
        'status' => 'pending',
        'payment_method' => 'credit_card',
        'scheduled_at' => now()->addDay(),
    ]);

    $result = approveCardFor($order, $customer, $company);

    expect($result['approved'])->toBeTrue()
        ->and($order->fresh()->status)->toBe('scheduled')
        ->and(Payment::where('order_id', $order->id)->value('status'))->toBe('paid');
});

test('cartão aprovado marca paid quando o pedido não é agendado ou a hora já passou', function (?string $scheduledAt) {
    ['order' => $order, 'customer' => $customer, 'company' => $company] = whatsappOrderContext([
        'status' => 'pending',
        'payment_method' => 'credit_card',
        'scheduled_at' => $scheduledAt ? now()->modify($scheduledAt) : null,
    ]);

    approveCardFor($order, $customer, $company);

    expect($order->fresh()->status)->toBe('paid');
})->with([
    'sem agendamento' => [null],
    'agendamento vencido' => ['-1 hour'],
]);

test('pedido agendado pago no cartão pelo chat recebe o aviso de agendado, não o de pago', function () {
    Queue::fake();

    ['order' => $order, 'customer' => $customer, 'company' => $company] = whatsappOrderContext([
        'status' => 'pending',
        'payment_method' => 'credit_card',
        'scheduled_at' => now()->addDay(),
    ]);

    $chat = new OrderChat;
    $chat->companyId = $company->id;
    $chat->customerId = $customer->id;
    verifiedChatFixture($chat, $customer);
    $chat->orderId = $order->id;
    $chat->paymentMethod = 'CARD';
    $chat->useNewCard = true;
    $chat->cardNumber = '4111111111111111';
    $chat->cardExpiry = '12/30';
    $chat->cardCvv = '123';
    $chat->cardHolderName = 'Maria Silva';

    $chat->submitCardPayment();

    expect($chat->step)->toBe('ORDER_CONFIRMED')
        ->and($order->fresh()->status)->toBe('scheduled');

    Queue::assertPushed(SendWhatsAppOrderNotificationJob::class, fn ($job) => $job->orderId === $order->id && $job->event === 'scheduled');
    Queue::assertNotPushed(SendWhatsAppOrderNotificationJob::class, fn ($job) => $job->event === 'paid');
});

test('pagamento simulado (debug) respeita o agendamento como o webhook', function () {
    config(['app.debug' => true]);
    Event::fake([OrderStatusUpdated::class]);

    ['order' => $order] = whatsappOrderContext([
        'status' => 'awaiting_payment',
        'scheduled_at' => now()->addDay(),
    ]);

    Payment::create([
        'order_id' => $order->id,
        'payment_gateway' => 'vindi',
        'amount' => 50,
        'status' => 'pending',
        'payment_token' => 'token-simulado',
    ]);

    app(\App\Services\Payment\PaymentService::class)->simulatePayment($order->id);

    expect($order->fresh()->status)->toBe('scheduled');
    Event::assertDispatched(OrderStatusUpdated::class, fn ($e) => $e->order->status === 'scheduled');
});
