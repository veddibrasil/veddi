<?php

use App\Livewire\Admin\Orders\Show as OrdersShow;
use App\Models\CompanyNotification;
use App\Models\Food99Request;
use App\Models\User;
use App\Services\Food99\Food99RequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function food99CancelApply(\Illuminate\Foundation\Testing\TestCase $test, array $ctx, array $info, string $applyId = '880000000000000001'): void
{
    postFood99Webhook($test, food99Callback($ctx['integration'], 'orderCancelApply', [
        'order_id' => $info['order_id'],
        'apply_id' => $applyId,
        'apply_reason' => 'Pedi errado',
        'reason_list' => [['reason_id' => 1, 'reason' => 'Mudei de ideia']],
    ]))->assertOk()->assertJson(['errno' => 0]);
}

it('registra o pedido de cancelamento do cliente com prazo de 5 min e avisa a loja', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);

    food99CancelApply($this, $ctx, $info);
    food99CancelApply($this, $ctx, $info);

    $request = Food99Request::withoutGlobalScopes()->sole();
    expect($request->kind)->toBe('cancel')
        ->and($request->order_id)->toBe($order->id)
        ->and($request->apply_id)->toBe('880000000000000001')
        ->and($request->isOpen())->toBeTrue()
        ->and($request->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(4.9)
        ->and(CompanyNotification::withoutGlobalScopes()->where('type', 'food99_request')->sole()->title)
        ->toBe('Cliente pediu cancelamento — 99Food #12');
});

it('aceita o cancelamento e só cancela o pedido quando a 99 confirmar', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api(array_merge($info, ['status' => 901]));
    $order = receiveFood99Order($this, $ctx, $info);
    food99CancelApply($this, $ctx, $info);

    app(Food99RequestService::class)->accept(Food99Request::withoutGlobalScopes()->sole());

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/order/apply/cancel') && $r['agree'] === true && $r['apply_id'] === 880000000000000001);
    expect(Food99Request::withoutGlobalScopes()->sole()->status)->toBe('accepted')
        ->and($order->fresh()->status)->toBe('pending');

    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderCancel', ['order_id' => $info['order_id']]))->assertOk();
    expect($order->fresh()->status)->toBe('cancelled');
});

it('recusa o reembolso com um dos motivos da 99', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    receiveFood99Order($this, $ctx, $info);

    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderRefundApply', [
        'order_id' => $info['order_id'],
        'apply_id' => '880000000000000002',
        'apply_reason' => 'Veio frio',
        'images' => ['https://img.99food.test/a.jpg'],
        'base_reason_list' => [['base_reason_id' => 31, 'base_reason' => 'Pedido entregue conforme']],
    ]))->assertOk();
    $request = Food99Request::withoutGlobalScopes()->sole();
    expect($request->kind)->toBe('refund')
        ->and($request->images)->toBe(['https://img.99food.test/a.jpg'])
        ->and($request->expires_at->greaterThan(now()->addHours(23)))->toBeTrue();

    $service = app(Food99RequestService::class);
    expect(fn () => $service->reject($request, 'Saiu quente da loja', '99'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->reject($request, 'curto', '31'))->not->toThrow(InvalidArgumentException::class);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/order/apply/refund')
        && $r['agree'] === false && $r['base_reason_id'] === 31 && $r['base_reason'] === 'Pedido entregue conforme');
    expect($request->fresh()->status)->toBe('rejected');
});

it('expira solicitações sem resposta com o desfecho da 99', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    receiveFood99Order($this, $ctx, $info);
    food99CancelApply($this, $ctx, $info);

    $this->travel(6)->minutes();
    expect(app(Food99RequestService::class)->expireOverdue())->toBe(1);

    $request = Food99Request::withoutGlobalScopes()->sole();
    expect($request->status)->toBe('expired')
        ->and($request->statusLabel())->toContain('recusada automaticamente')
        ->and(fn () => app(Food99RequestService::class)->accept($request))->toThrow(RuntimeException::class);
});

it('mostra o pedido 99Food e responde a solicitação pela tela do pedido', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);
    food99CancelApply($this, $ctx, $info);
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    Livewire::test(OrdersShow::class, ['order' => $order])
        ->assertSee('#12')
        ->assertSee('ID 99Food 5764607523034934815')
        ->assertSee('Cancelar o pedido')
        ->assertSee('Pedi errado')
        ->call('openFood99RequestReject', Food99Request::withoutGlobalScopes()->sole()->id)
        ->set('food99RejectReason', 'Já saiu para entrega')
        ->call('confirmFood99RequestReject')
        ->assertSet('food99RejectRequestId', null);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/order/apply/cancel') && $r['agree'] === false && $r['reason'] === 'Já saiu para entrega');
});

it('não deixa responder solicitação de outro pedido pela tela', function () {
    $a = food99Context('A');
    $infoA = food99OrderInfo($a);
    fakeFood99Api($infoA);
    receiveFood99Order($this, $a, $infoA);
    food99CancelApply($this, $a, $infoA);

    $b = food99Context('B');
    $infoB = food99OrderInfo($b, overrides: ['order_id' => '5764607523034930001']);
    $orderB = receiveFood99Order($this, $b, $infoB);
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    $component = Livewire::test(OrdersShow::class, ['order' => $orderB]);

    expect(fn () => $component->call('acceptFood99Request', Food99Request::withoutGlobalScopes()->sole()->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect(Food99Request::withoutGlobalScopes()->sole()->status)->toBe('open');
});
