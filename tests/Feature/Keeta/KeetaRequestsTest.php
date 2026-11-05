<?php

use App\Models\KeetaRequest;
use App\Services\Keeta\KeetaRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function refundMetadata(int $cents = 8500): string
{
    return json_encode(['money' => $cents, 'applyReason' => 'Item errado', 'reason' => 'Item errado', 'code' => 'OTHERS', 'partRefund' => 0,
        'refundProducts' => [['name' => 'Coxinha', 'count' => 1, 'refundCount' => 1, 'priceWithGroup' => ['amount' => 800]]]]);
}

it('abre o pedido de reembolso do cliente com valor em reais e itens', function () {
    $ctx = keetaContext();
    $raw = keetaOrder($ctx);
    $order = receiveKeetaOrder($this, $ctx, $raw);

    postKeetaWebhook($this, keetaEvent('USER_REFUND_REQUEST', $raw['id'], ['metadata' => refundMetadata()]), $ctx['integration']->merchant_ref)->assertNoContent();

    $request = KeetaRequest::withoutGlobalScopes()->first();
    expect($request->kind)->toBe('refund')
        ->and($request->order_id)->toBe($order->id)
        ->and((float) $request->amount)->toBe(85.0)
        ->and($request->items[0]['amount'])->toEqual(8)
        ->and($request->isAnswerable())->toBeTrue()
        ->and(\App\Models\CompanyNotification::where('type', 'keeta_request')->exists())->toBeTrue();
});

it('aceita e recusa o reembolso pela API, e o REFUNDED registra no pedido', function () {
    $ctx = keetaContext();
    $raw = keetaOrder($ctx);
    $order = receiveKeetaOrder($this, $ctx, $raw);
    $ref = $ctx['integration']->merchant_ref;

    postKeetaWebhook($this, keetaEvent('USER_REFUND_REQUEST', $raw['id'], ['metadata' => refundMetadata()]), $ref)->assertNoContent();
    $request = KeetaRequest::withoutGlobalScopes()->first();

    expect(fn () => app(KeetaRequestService::class)->reject($request, 'XYZ', 'texto ok'))->toThrow(InvalidArgumentException::class);

    app(KeetaRequestService::class)->accept($request, null);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/v1/orders/{$raw['id']}/acceptRefund"));
    expect($request->fresh()->status)->toBe('accepted');

    postKeetaWebhook($this, keetaEvent('REFUNDED', $raw['id'], ['metadata' => refundMetadata(8800)]), $ref)->assertNoContent();
    expect($order->fresh()->keetaDetails()->get('refunds')[0])->toMatchArray(['status' => 'refunded', 'amount' => 88.0]);

    postKeetaWebhook($this, keetaEvent('USER_REFUND_REQUEST', $raw['id'], ['metadata' => refundMetadata(100)]), $ref)->assertNoContent();
    $second = KeetaRequest::withoutGlobalScopes()->latest('id')->first();
    app(KeetaRequestService::class)->reject($second, 'DISH_ALREADY_DONE', 'Pedido já foi preparado', null);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rejectRefund') && $r['code'] === 'DISH_ALREADY_DONE');
    expect($second->fresh()->status)->toBe('rejected');
});

it('cancelamento pedido pelo cliente vira aviso e fecha com o CANCELLED', function () {
    $ctx = keetaContext();
    $raw = keetaOrder($ctx);
    $order = receiveKeetaOrder($this, $ctx, $raw);
    $ref = $ctx['integration']->merchant_ref;

    postKeetaWebhook($this, keetaEvent('CANCELLATION_REQUESTED', $raw['id']), $ref)->assertNoContent();
    $request = KeetaRequest::withoutGlobalScopes()->first();
    expect($request->kind)->toBe('cancel')->and($request->isAnswerable())->toBeFalse();

    postKeetaWebhook($this, keetaEvent('CANCELLED', $raw['id'], ['metadata' => json_encode(['reason' => 'Cliente desistiu', 'code' => 'OTHERS'])]), $ref)->assertNoContent();

    expect($request->fresh()->status)->toBe('resolved')
        ->and($order->fresh()->status)->toBe('cancelled')
        ->and($order->fresh()->keetaDetails()->cancellation()['origin'])->toBe('platform');
});

it('eco do cancelamento pedido pela loja não abre solicitação', function () {
    $ctx = keetaContext();
    $raw = keetaOrder($ctx);
    $order = receiveKeetaOrder($this, $ctx, $raw);
    app(\App\Services\Keeta\KeetaOrderActionService::class)->requestCancel($order, 'OUTSIDE_DELIVERY_HOURS');

    postKeetaWebhook($this, keetaEvent('CANCELLATION_REQUESTED', $raw['id']), $ctx['integration']->merchant_ref)->assertNoContent();

    expect(KeetaRequest::withoutGlobalScopes()->count())->toBe(0);
});
