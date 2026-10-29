<?php

use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessOrder;
use App\Models\Order;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * A fila retenta o ProcessOrder (retryUntil). Cancelar a cada falha fazia o cliente receber
 * "cancelado" e, numa tentativa seguinte bem-sucedida, "aguardando pagamento" no mesmo pedido:
 * agora só o failed() (tentativas esgotadas) cancela, e só pedido que ainda espera cobrança.
 */
beforeEach(function () {
    Event::fake([OrderStatusUpdated::class]);
});

function processOrderJob(Order $order): ProcessOrder
{
    return new ProcessOrder($order->fresh(), $order->customer, $order->company, 'pix');
}

function failingOrchestrator(): PaymentOrchestrator
{
    $orchestrator = Mockery::mock(PaymentOrchestrator::class);
    $orchestrator->shouldReceive('process')->andThrow(new RuntimeException('Vindi fora do ar'));

    return $orchestrator;
}

test('falha numa tentativa relança para a fila sem cancelar nem avisar o cliente', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'pending']);

    expect(fn () => processOrderJob($order)->handle(failingOrchestrator()))
        ->toThrow(RuntimeException::class, 'Vindi fora do ar');

    expect($order->fresh()->status)->toBe('pending');
    Event::assertNotDispatched(OrderStatusUpdated::class);
});

test('tentativa bem-sucedida deixa o pedido aguardando pagamento', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'pending']);

    $orchestrator = Mockery::mock(PaymentOrchestrator::class);
    $orchestrator->shouldReceive('process')->once()->andReturn(['status' => 'pending']);

    processOrderJob($order)->handle($orchestrator);

    expect($order->fresh()->status)->toBe('awaiting_payment');
    Event::assertDispatched(OrderStatusUpdated::class, fn ($e) => $e->order->status === 'awaiting_payment');
});

test('retentativa de pedido que saiu do fluxo de cobrança não gera cobrança nem reabre o pedido', function (string $status) {
    ['order' => $order] = whatsappOrderContext(['status' => $status]);

    $orchestrator = Mockery::mock(PaymentOrchestrator::class);
    $orchestrator->shouldNotReceive('process');

    processOrderJob($order)->handle($orchestrator);

    expect($order->fresh()->status)->toBe($status);
    Event::assertNotDispatched(OrderStatusUpdated::class);
})->with(['cancelled', 'paid', 'scheduled']);

test('tentativas esgotadas cancelam o pedido que ainda espera cobrança e avisam o chat', function (string $status) {
    ['order' => $order] = whatsappOrderContext(['status' => $status]);

    processOrderJob($order)->failed(new RuntimeException('Vindi fora do ar'));

    expect($order->fresh()->status)->toBe('cancelled');
    Event::assertDispatched(OrderStatusUpdated::class, fn ($e) => $e->order->id === $order->id && $e->order->status === 'cancelled');
})->with(['pending', 'awaiting_payment']);

test('tentativas esgotadas não mexem em pedido já cancelado ou pago', function (string $status) {
    ['order' => $order] = whatsappOrderContext(['status' => 'pending']);
    $job = processOrderJob($order);

    // Cliente trocou a forma de pagamento (ou o pedido foi pago) enquanto a fila retentava.
    $order->update(['status' => $status]);

    $job->failed(new RuntimeException('Vindi fora do ar'));

    expect($order->fresh()->status)->toBe($status);
    Event::assertNotDispatched(OrderStatusUpdated::class);
})->with(['cancelled', 'paid']);

test('falha seguida de tentativa bem-sucedida nunca passa por cancelado', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'pending']);

    $orchestrator = Mockery::mock(PaymentOrchestrator::class);
    $orchestrator->shouldReceive('process')->once()->andThrow(new RuntimeException('timeout'));
    $orchestrator->shouldReceive('process')->once()->andReturn(['status' => 'pending']);

    expect(fn () => processOrderJob($order)->handle($orchestrator))->toThrow(RuntimeException::class);
    processOrderJob($order)->handle($orchestrator);

    expect($order->fresh()->status)->toBe('awaiting_payment');
    Event::assertDispatchedTimes(OrderStatusUpdated::class, 1);
    Event::assertNotDispatched(OrderStatusUpdated::class, fn ($e) => $e->order->status === 'cancelled');
});

test('no ciclo real da fila o failed() cancela quando as tentativas acabam', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'pending']);
    app()->instance(PaymentOrchestrator::class, failingOrchestrator());

    // Fila sync não retenta: a falha esgota as tentativas e o failed() roda com o pedido
    // restaurado do banco (SerializesModels), como no worker.
    expect(fn () => ProcessOrder::dispatchSync($order->fresh(), $order->customer, $order->company, 'pix'))
        ->toThrow(RuntimeException::class);

    expect($order->fresh()->status)->toBe('cancelled');
    Event::assertDispatchedTimes(OrderStatusUpdated::class, 1);
});

test('retentativas cabem na espera do checkout: o cliente vê a falha em minutos, não em horas', function () {
    $job = (new ReflectionClass(\App\Jobs\ProcessOrder::class))->newInstanceWithoutConstructor();

    expect($job->retryUntil()->getTimestamp())->toBeLessThanOrEqual(now()->addMinutes(10)->getTimestamp())
        ->and(array_sum($job->backoff))->toBeLessThan(600);
});

test('cobrança recusada pela Vindi por dado inválido falha o job de vez, sem nova tentativa', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'pending']);
    $orchestrator = Mockery::mock(PaymentOrchestrator::class);
    $orchestrator->shouldReceive('process')->once()
        ->andThrow(new \App\Exceptions\VindiValidationException('Vindi API error 422: CEP em branco'));
    $job = processOrderJob($order)->withFakeQueueInteractions();

    $job->handle($orchestrator);

    $job->assertFailedWith(\App\Exceptions\VindiValidationException::class);
    expect($order->fresh()->status)->toBe('pending'); // quem cancela é o failed(), chamado pela fila
});
