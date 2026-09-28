<?php

use App\Contracts\IfoodGatewayContract;
use App\Livewire\Admin\Orders\Show as OrdersShow;
use App\Models\CompanyNotification;
use App\Models\IfoodDispute;
use App\Models\IfoodOrderEvent;
use App\Models\User;
use App\Services\Ifood\IfoodGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Evento HANDSHAKE_DISPUTE no formato da Plataforma de Negociação. */
function ifoodDisputeEvent(array $ctx, string $orderId, array $metadata = []): IfoodOrderEvent
{
    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-hsd-'.$orderId,
        'event_type' => 'HSD',
        'source' => 'polling',
        'ifood_integration_id' => $ctx['integration']->id,
        'payload' => [
            'orderId' => $orderId,
            'code' => 'HSD',
            'fullCode' => 'HANDSHAKE_DISPUTE',
            'metadata' => array_merge([
                'disputeId' => 'dispute-'.$orderId,
                'action' => 'CANCELLATION',
                'message' => 'Pedido chegou frio',
                'timeoutAction' => 'ACCEPT_CANCELLATION',
                'handshakeType' => 'AFTER_DELIVERY',
                'expiresAt' => now()->addMinutes(5)->utc()->toIso8601String(),
                'alternatives' => [
                    ['id' => 'alt-refund', 'type' => 'REFUND', 'metadata' => ['maxAmount' => ['value' => '1000', 'currency' => 'BRL']]],
                    ['id' => 'alt-time', 'type' => 'ADDITIONAL_TIME', 'metadata' => ['allowedsAdditionalTimeInMinutes' => [10, 20], 'allowedsAdditionalTimeReasons' => ['HIGH_STORE_DEMAND']]],
                ],
            ], $metadata),
        ],
        'status' => 'pending',
    ]);

    runIfoodOrderJob($event->id);

    return $event->fresh();
}

function disputeGatewayMock(): Mockery\MockInterface
{
    $gateway = Mockery::mock(IfoodGatewayContract::class);
    app()->instance(IfoodGatewayContract::class, $gateway);

    return $gateway;
}

test('negociação aberta pelo cliente é registrada, avisa a loja e aparece no pedido', function () {
    $ctx = ifoodContext('disp1');
    $order = ifoodKanbanOrder($ctx, 'delivered', 'order-disp1');

    expect(ifoodDisputeEvent($ctx, 'order-disp1')->status)->toBe('processed');

    $dispute = IfoodDispute::withoutGlobalScopes()->where('dispute_id', 'dispute-order-disp1')->first();
    expect($dispute->order_id)->toBe($order->id)
        ->and($dispute->company_id)->toBe($ctx['company']->id)
        ->and($dispute->isOpen())->toBeTrue()
        ->and($dispute->actionLabel())->toBe('Cliente pediu o cancelamento do pedido');

    expect(CompanyNotification::where('company_id', $ctx['company']->id)->where('type', 'ifood')->first()->title)
        ->toContain('Negociação iFood');

    $this->actingAs(User::factory()->create(['is_super_admin' => true]));
    Livewire::test(OrdersShow::class, ['order' => $order])
        ->assertSee('Negociação iFood')
        ->assertSee('Pedido chegou frio')
        ->assertSee('Propor reembolso')
        ->assertSee('Propor mais tempo')
        ->assertSee('Sem resposta no prazo, o iFood aceita o pedido do cliente.');
});

test('aceitar negociação chama o iFood e registra a resposta', function () {
    $ctx = ifoodContext('disp2');
    $order = ifoodKanbanOrder($ctx, 'delivered', 'order-disp2');
    ifoodDisputeEvent($ctx, 'order-disp2');
    $dispute = IfoodDispute::withoutGlobalScopes()->firstOrFail();
    $admin = User::factory()->create(['is_super_admin' => true]);

    $gateway = disputeGatewayMock();
    $gateway->shouldReceive('acceptDispute')->once()->with(Mockery::on(fn ($i) => $i->id === $ctx['integration']->id), 'dispute-order-disp2', null);

    $this->actingAs($admin);
    Livewire::test(OrdersShow::class, ['order' => $order])
        ->call('openDisputeResponse', $dispute->id, 'accept')
        ->call('submitDisputeResponse')
        ->assertSee('Resposta enviada ao iFood.');

    expect($dispute->fresh())->status->toBe('responded')->response->toBe('ACCEPTED')->responded_by->toBe($admin->id);
});

test('recusar negociação exige justificativa', function () {
    $ctx = ifoodContext('disp3');
    $order = ifoodKanbanOrder($ctx, 'delivered', 'order-disp3');
    ifoodDisputeEvent($ctx, 'order-disp3');
    $dispute = IfoodDispute::withoutGlobalScopes()->firstOrFail();

    $gateway = disputeGatewayMock();
    $gateway->shouldReceive('rejectDispute')->once()->with(Mockery::any(), 'dispute-order-disp3', 'Pedido saiu quente, temos o registro');

    $this->actingAs(User::factory()->create(['is_super_admin' => true]));
    Livewire::test(OrdersShow::class, ['order' => $order])
        ->call('openDisputeResponse', $dispute->id, 'reject')
        ->set('disputeReason', 'não')
        ->call('submitDisputeResponse')
        ->assertHasErrors('disputeReason')
        ->set('disputeReason', 'Pedido saiu quente, temos o registro')
        ->call('submitDisputeResponse')
        ->assertHasNoErrors();

    expect($dispute->fresh()->response)->toBe('REJECTED');
});

test('contraproposta de reembolso respeita o valor máximo e vai em centavos', function () {
    $ctx = ifoodContext('disp4');
    $order = ifoodKanbanOrder($ctx, 'delivered', 'order-disp4');
    ifoodDisputeEvent($ctx, 'order-disp4');
    $dispute = IfoodDispute::withoutGlobalScopes()->firstOrFail();

    $gateway = disputeGatewayMock();
    $gateway->shouldReceive('proposeDisputeAlternative')->once()->with(
        Mockery::any(), 'dispute-order-disp4', 'alt-refund',
        ['type' => 'REFUND', 'metadata' => ['amount' => ['value' => '750', 'currency' => 'BRL']]],
    );

    $this->actingAs(User::factory()->create(['is_super_admin' => true]));
    Livewire::test(OrdersShow::class, ['order' => $order])
        ->call('openDisputeResponse', $dispute->id, 'alternative', 'alt-refund')
        ->set('disputeAmount', '15,00')
        ->call('submitDisputeResponse')
        ->assertHasErrors('disputeReason')
        ->set('disputeAmount', '7,50')
        ->call('submitDisputeResponse')
        ->assertHasNoErrors();
});

test('contraproposta de tempo adicional usa só opções do iFood', function () {
    $ctx = ifoodContext('disp5');
    ifoodKanbanOrder($ctx, 'out_for_delivery', 'order-disp5');
    ifoodDisputeEvent($ctx, 'order-disp5');
    $dispute = IfoodDispute::withoutGlobalScopes()->firstOrFail();

    $gateway = disputeGatewayMock();
    $gateway->shouldReceive('proposeDisputeAlternative')->once()->with(
        Mockery::any(), 'dispute-order-disp5', 'alt-time',
        ['type' => 'ADDITIONAL_TIME', 'metadata' => ['additionalTimeInMinutes' => '20', 'additionalTimeReason' => 'HIGH_STORE_DEMAND']],
    );
    $service = app(\App\Services\Ifood\IfoodOrderActionService::class);

    expect(fn () => $service->proposeAlternative($dispute, 'alt-time', ['minutes' => 15, 'reason' => 'HIGH_STORE_DEMAND']))
        ->toThrow(InvalidArgumentException::class);

    $service->proposeAlternative($dispute, 'alt-time', ['minutes' => 20, 'reason' => 'HIGH_STORE_DEMAND']);
});

test('desfecho da negociação (HSS) encerra a disputa e mostra o resultado', function () {
    $ctx = ifoodContext('disp6');
    $order = ifoodKanbanOrder($ctx, 'delivered', 'order-disp6');
    ifoodDisputeEvent($ctx, 'order-disp6');

    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-hss-disp6', 'event_type' => 'HSS', 'source' => 'polling',
        'ifood_integration_id' => $ctx['integration']->id,
        'payload' => ['orderId' => 'order-disp6', 'metadata' => ['disputeId' => 'dispute-order-disp6', 'status' => 'EXPIRED']],
        'status' => 'pending',
    ]);
    runIfoodOrderJob($event->id);

    $dispute = IfoodDispute::withoutGlobalScopes()->firstOrFail();
    expect($dispute->status)->toBe('settled')->and($dispute->isOpen())->toBeFalse();

    $this->actingAs(User::factory()->create(['is_super_admin' => true]));
    Livewire::test(OrdersShow::class, ['order' => $order])
        ->assertSee('Expirada sem resposta')
        ->assertDontSee('Propor reembolso');
});

test('negociação vencida não aceita resposta', function () {
    $ctx = ifoodContext('disp7');
    ifoodKanbanOrder($ctx, 'delivered', 'order-disp7');
    ifoodDisputeEvent($ctx, 'order-disp7', ['expiresAt' => now()->subMinute()->utc()->toIso8601String()]);
    $dispute = IfoodDispute::withoutGlobalScopes()->firstOrFail();

    $gateway = disputeGatewayMock();
    $gateway->shouldNotReceive('acceptDispute');

    expect(fn () => app(\App\Services\Ifood\IfoodOrderActionService::class)->acceptDispute($dispute))
        ->toThrow(RuntimeException::class, 'já foi respondida ou expirou');
});

test('gateway chama os endpoints da Plataforma de Negociação', function () {
    $ctx = ifoodContext('disp8');
    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response(['accessToken' => 'tok', 'expiresIn' => 3600]),
        '*/order/v1.0/disputes/*' => Http::response(null, 202),
    ]);
    $gateway = app(IfoodGatewayService::class);

    $gateway->acceptDispute($ctx['integration'], 'd-1');
    $gateway->rejectDispute($ctx['integration'], 'd-1', 'Motivo da recusa');
    $gateway->proposeDisputeAlternative($ctx['integration'], 'd-1', 'alt-1', ['type' => 'REFUND', 'metadata' => ['amount' => ['value' => '100', 'currency' => 'BRL']]]);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/order/v1.0/disputes/d-1/accept') && $r->method() === 'POST');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/order/v1.0/disputes/d-1/reject') && $r['reason'] === 'Motivo da recusa');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/order/v1.0/disputes/d-1/alternatives/alt-1') && $r['type'] === 'REFUND');
});
