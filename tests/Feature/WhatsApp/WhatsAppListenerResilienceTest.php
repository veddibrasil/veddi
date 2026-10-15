<?php

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Jobs\SendWhatsAppOrderNotificationJob;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Os listeners de WhatsApp rodam síncronos, muitas vezes dentro da transação do webhook de
 * pagamento ou do estorno: uma falha ao decidir/despachar o aviso não pode reverter o pagamento
 * nem impedir os outros listeners do evento.
 */
beforeEach(function () {
    Queue::fake();
    Mail::fake();

    config(['services.whatsapp.fallback_to_platform' => false]);
});

function breakWhatsAppService(): void
{
    $broken = Mockery::mock(WhatsAppService::class);
    $broken->shouldReceive('shouldNotify')->andThrow(new RuntimeException('falha inesperada no WhatsApp'));

    app()->instance(WhatsAppService::class, $broken);
}

test('falha no WhatsApp durante a mudança de status não reverte o pagamento da transação', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'awaiting_payment']);
    breakWhatsAppService();

    DB::transaction(function () use ($order) {
        $order->update(['status' => 'paid']);
        OrderStatusUpdated::dispatch($order->fresh());
    });

    expect($order->fresh()->status)->toBe('paid');
    Queue::assertNotPushed(SendWhatsAppOrderNotificationJob::class);
});

test('falha no WhatsApp no pedido novo não propaga para quem criou o pedido', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'pending']);
    breakWhatsAppService();

    NewOrderPlaced::dispatch($order->fresh()->load('customer'));

    Queue::assertNotPushed(SendWhatsAppOrderNotificationJob::class);
});

test('a falha vai para o log do whatsapp só com ids, sem telefone nem mensagem da exceção', function () {
    $logs = whatsappCaptureLogs();
    ['order' => $order, 'customer' => $customer] = whatsappOrderContext(['status' => 'preparing']);
    breakWhatsAppService();

    OrderStatusUpdated::dispatch($order->fresh());

    $record = collect($logs->getRecords())->first(fn ($r) => str_contains($r->message, 'falha ao despachar'));

    expect($record)->not->toBeNull()
        ->and($record->context)->toMatchArray([
            'order_id' => $order->id,
            'company_id' => $order->company_id,
            'event' => 'preparing',
            'exception' => RuntimeException::class,
        ]);

    $serialized = json_encode($record->context);
    expect($serialized)->not->toContain($customer->phone)
        ->and($serialized)->not->toContain('falha inesperada');
});

test('com o WhatsApp saudável o listener continua despachando o aviso', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'preparing']);

    OrderStatusUpdated::dispatch($order->fresh());

    Queue::assertPushed(SendWhatsAppOrderNotificationJob::class, fn ($job) => $job->orderId === $order->id && $job->event === 'preparing');
});
