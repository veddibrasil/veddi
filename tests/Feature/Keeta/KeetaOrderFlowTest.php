<?php

use App\Models\CompanyNotification;
use App\Models\KeetaWebhookEvent;
use App\Models\Order;
use App\Services\Keeta\KeetaOrderActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function keetaActions(): KeetaOrderActionService
{
    return app(KeetaOrderActionService::class);
}

it('importa o pedido do evento CREATED com itens, valores e cliente', function () {
    $ctx = keetaContext();
    $order = receiveKeetaOrder($this, $ctx, keetaOrder($ctx));

    $details = $order->keetaDetails();
    $linked = $order->items->firstWhere('product_id', $ctx['product']->id);
    $unlinked = $order->items->firstWhere('product_id', null);

    expect($order->status)->toBe('pending')
        ->and($order->payment_method)->toBe('keeta')
        ->and($order->displayNumber())->toBe('Keeta #1042')
        ->and($order->awaitingAcceptance())->toBeTrue()
        ->and($details->stage())->toBe('CREATED')
        ->and($details->isDeliveredByPlatform())->toBeTrue()
        ->and($details->pickupCode())->toBe('7731')
        ->and($details->customerPhone())->toBeNull()
        ->and((int) $linked->quantity)->toBe(2)
        ->and($unlinked->product_name ?? $unlinked->name)->toContain('Refrigerante do portal')
        ->and((float) $order->delivery_fee)->toBe(0.0)
        ->and((float) $details->get('reported_total'))->toBe(20.0)
        ->and((float) $details->get('keeta_order_amount'))->toBe(26.98)
        ->and((float) $details->get('shop_subsidy'))->toBe(1.5)
        ->and($order->customer->phone)->toStartWith('keeta:')
        ->and($order->customer->keeta_customer_id)->toBe('88123')
        ->and($order->customer->address)->toBe('Rua das Flores')
        ->and($order->customer->neighborhood)->toBe('')
        ->and($order->payments()->where('payment_gateway', 'keeta')->where('status', 'paid')->exists())->toBeTrue();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/orders/783441058793308') && $r->hasHeader('X-App-Signature') && $r->hasHeader('Authorization', 'Bearer keeta-token'));
    // Entrega Keeta: dados criptografados não são descriptografados.
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/v1/batchDecrypt'));
});

it('confirma, marca pronto e conclui pelos eventos da entrega Keeta', function () {
    $ctx = keetaContext();
    $raw = keetaOrder($ctx);
    $order = receiveKeetaOrder($this, $ctx, $raw);

    keetaActions()->accept($order);
    expect($order->fresh()->status)->toBe('preparing')
        ->and($order->fresh()->keetaDetails()->stage())->toBe('CONFIRMED');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/orders/783441058793308/confirm')
        && $r['orderExternalCode'] === $order->order_number
        && isset($r['createdAt']));

    keetaActions()->markReady($order->fresh());
    expect($order->fresh()->status)->toBe('ready')
        ->and($order->fresh()->keetaDetails()->waitingMessage())->toContain('entregador da Keeta');

    $ref = $ctx['integration']->merchant_ref;
    postKeetaWebhook($this, keetaEvent('PICKUP_ONGOING', $raw['id'], ['metadata' => json_encode(['pickupEtaDatetime' => now()->addMinutes(5)->utc()->toIso8601String()])]), $ref)->assertNoContent();
    expect($order->fresh()->keetaDetails()->rider()['pickup_eta'])->not->toBeNull();

    postKeetaWebhook($this, keetaEvent('DISPATCHED', $raw['id']), $ref)->assertNoContent();
    expect($order->fresh()->status)->toBe('out_for_delivery');

    postKeetaWebhook($this, keetaEvent('DELIVERED', $raw['id']), $ref)->assertNoContent();
    postKeetaWebhook($this, keetaEvent('CONCLUDED', $raw['id']), $ref)->assertNoContent();
    expect($order->fresh()->status)->toBe('delivered')
        ->and($order->fresh()->keetaDetails()->stage())->toBe('CONCLUDED');

    // Evento atrasado não faz o pedido voltar.
    postKeetaWebhook($this, keetaEvent('READY_FOR_PICKUP', $raw['id']), $ref)->assertNoContent();
    expect($order->fresh()->status)->toBe('delivered');
});

it('confirma pelo evento quando a loja aceita no portal da Keeta', function () {
    $ctx = keetaContext();
    $raw = keetaOrder($ctx);
    $order = receiveKeetaOrder($this, $ctx, $raw);

    postKeetaWebhook($this, keetaEvent('CONFIRMED', $raw['id']), $ctx['integration']->merchant_ref)->assertNoContent();

    expect($order->fresh()->status)->toBe('preparing')
        ->and($order->fresh()->awaitingAcceptance())->toBeFalse();
});

it('confirma sozinho com o aceite automático da filial', function () {
    $ctx = keetaContext(integration: ['auto_accept' => true]);
    $order = receiveKeetaOrder($this, $ctx, keetaOrder($ctx));

    expect($order->fresh()->status)->toBe('preparing');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/confirm'));
});

it('aceite automático que falha deixa o pedido aguardando aceite', function () {
    $ctx = keetaContext(integration: ['auto_accept' => true]);
    $raw = keetaOrder($ctx);
    fakeKeetaApi($raw, ['*/v1/orders/*/confirm' => Http::response(['title' => 'erro', 'status' => 500], 500)]);

    postKeetaWebhook($this, keetaEvent('CREATED', $raw['id']), $ctx['integration']->merchant_ref)->assertNoContent();

    $order = Order::withoutGlobalScopes()->where('external_order_id', $raw['id'])->firstOrFail();
    expect($order->status)->toBe('pending')->and($order->awaitingAcceptance())->toBeTrue();
});

it('trata 422 como já feito quando a Keeta já está na etapa', function () {
    $ctx = keetaContext();
    $raw = keetaOrder($ctx);
    $order = receiveKeetaOrder($this, $ctx, $raw);

    fakeKeetaApi(array_merge($raw, ['lastEvent' => 'CONFIRMED']), [
        '*/v1/orders/*/confirm' => Http::response(['title' => 'order status is not in a valid state', 'status' => 422], 422),
    ]);

    keetaActions()->accept($order);

    expect($order->fresh()->status)->toBe('preparing');
});

it('repassa o 422 quando o pedido foi cancelado na Keeta', function () {
    $ctx = keetaContext();
    $raw = keetaOrder($ctx);
    $order = receiveKeetaOrder($this, $ctx, $raw);

    fakeKeetaApi(array_merge($raw, ['lastEvent' => 'CANCELLED']), [
        '*/v1/orders/*/confirm' => Http::response(['title' => 'order status is not in a valid state', 'status' => 422], 422),
    ]);

    expect(fn () => keetaActions()->accept($order))->toThrow(RuntimeException::class, 'cancelado na Keeta');
    expect($order->fresh()->status)->toBe('pending');
});

it('pede o cancelamento e só cancela quando chega o CANCELLED, devolvendo o estoque', function () {
    $ctx = keetaContext();
    \Illuminate\Support\Facades\DB::table('branch_product')->where('product_id', $ctx['product']->id)->update(['track_stock' => 1, 'quantity' => 10]);
    $raw = keetaOrder($ctx);
    $order = receiveKeetaOrder($this, $ctx, $raw);
    $stockAfterOrder = (int) \Illuminate\Support\Facades\DB::table('branch_product')->where('product_id', $ctx['product']->id)->value('quantity');

    keetaActions()->requestCancel($order, 'UNAVAILABLE_ITEM', null, null, ['359379560', 'id-de-outro-pedido']);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/requestCancellation')
        && $r['code'] === 'UNAVAILABLE_ITEM'
        && $r['mode'] === 'MANUAL'
        && $r['outOfStockItems'] === ['359379560']
        && $r['reason'] === 'Item indisponível');
    expect($order->fresh()->status)->toBe('pending')
        ->and($order->fresh()->keetaDetails()->cancellationPending())->toBeTrue()
        ->and(fn () => keetaActions()->requestCancel($order->fresh(), 'UNAVAILABLE_ITEM'))->toThrow(RuntimeException::class);

    postKeetaWebhook($this, keetaEvent('CANCELLED', $raw['id'], ['metadata' => json_encode(['reason' => 'Loja pediu', 'code' => 'INSUFFICIENT_DISHES'])]), $ctx['integration']->merchant_ref)->assertNoContent();

    $order = $order->fresh();
    expect($order->status)->toBe('cancelled')
        ->and($order->keetaDetails()->cancellation()['origin'])->toBe('store')
        ->and((int) \Illuminate\Support\Facades\DB::table('branch_product')->where('product_id', $ctx['product']->id)->value('quantity'))->toBe($stockAfterOrder + 2);
});

it('recusa motivo de entrega própria em pedido com entrega Keeta', function () {
    $ctx = keetaContext();
    $order = receiveKeetaOrder($this, $ctx, keetaOrder($ctx));

    expect(fn () => keetaActions()->requestCancel($order, 'RESTAURANT_WITHOUT_DELIVERY_PERSON'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => keetaActions()->requestCancel($order, 'INTERNAL_DIFFICULTIES_OF_THE_RESTAURANT', 'oi'))->toThrow(InvalidArgumentException::class);
});

it('não volta pedido entregue para cancelado e avisa a loja', function () {
    $ctx = keetaContext();
    $raw = keetaOrder($ctx);
    $order = receiveKeetaOrder($this, $ctx, $raw);
    $ref = $ctx['integration']->merchant_ref;

    postKeetaWebhook($this, keetaEvent('CONCLUDED', $raw['id']), $ref)->assertNoContent();
    postKeetaWebhook($this, keetaEvent('CANCELLED', $raw['id'], ['metadata' => json_encode(['reason' => 'Suporte', 'code' => 'OTHERS'])]), $ref)->assertNoContent();

    expect($order->fresh()->status)->toBe('delivered')
        ->and($order->fresh()->keetaDetails()->get('post_delivery_cancellation')['code'])->toBe('OTHERS')
        ->and(CompanyNotification::where('company_id', $ctx['company']->id)->where('title', 'like', '%depois da entrega%')->exists())->toBeTrue();
});

it('entrega própria descriptografa contato e despacha/entrega pela API', function () {
    $ctx = keetaContext();
    $raw = keetaOrder($ctx, ['delivery' => ['deliveredBy' => 'MERCHANT'], 'otherFees' => [
        ['name' => 'Entrega', 'type' => 'DELIVERY_FEE', 'receivedBy' => 'MERCHANT', 'price' => ['value' => 5.0, 'currency' => 'BRL']],
    ]]);

    fakeKeetaApi($raw, ['*/v1/batchDecrypt' => Http::response(['plainInfos' => [
        ['cipherText' => 'ENC_ab12cd34', 'plainText' => '11999990000', 'errorCode' => 0, 'errorMsg' => ''],
        ['cipherText' => 'ENC_num', 'plainText' => '123', 'errorCode' => 0, 'errorMsg' => ''],
        ['cipherText' => 'ENC_dist', 'plainText' => 'Centro', 'errorCode' => 0, 'errorMsg' => ''],
    ]], 200)]);
    postKeetaWebhook($this, keetaEvent('CREATED', $raw['id']), $ctx['integration']->merchant_ref)->assertNoContent();
    $order = Order::withoutGlobalScopes()->where('external_order_id', $raw['id'])->firstOrFail();

    expect($order->keetaDetails()->isSelfDelivery())->toBeTrue()
        ->and($order->keetaDetails()->customerPhone())->toBe('11999990000')
        ->and($order->customer->number)->toBe('123')
        ->and($order->customer->neighborhood)->toBe('Centro')
        ->and((float) $order->delivery_fee)->toBe(5.0);

    keetaActions()->accept($order);
    keetaActions()->dispatch($order->fresh());
    keetaActions()->markDelivered($order->fresh());

    expect($order->fresh()->status)->toBe('delivered')
        ->and(keetaCalls())->toContain('POST /v1/orders/783441058793308/dispatch', 'POST /v1/orders/783441058793308/delivered');
});

it('propaga para a Keeta o "pronto" marcado fora do serviço de ações', function () {
    $ctx = keetaContext();
    $order = receiveKeetaOrder($this, $ctx, keetaOrder($ctx));
    keetaActions()->accept($order);
    Http::fake(['*/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 7200]), '*' => Http::response('', 202)]);

    $order->fresh()->update(['status' => 'ready']);
    \App\Events\OrderStatusUpdated::dispatch($order->fresh());

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/readyForPickup'));
    expect($order->fresh()->keetaDetails()->stage())->toBe('READY');
});

it('evento que chega antes do pedido volta pra fila até o pedido existir', function () {
    $ctx = keetaContext();
    $raw = keetaOrder($ctx);
    fakeKeetaApi($raw);

    KeetaWebhookEvent::create([
        'event_id' => 'pre-1', 'keeta_integration_id' => $ctx['integration']->id, 'event_type' => 'CREATED',
        'external_order_id' => $raw['id'], 'source' => 'webhook', 'payload' => keetaEvent('CREATED', $raw['id']), 'status' => 'processing',
    ]);
    $confirmed = KeetaWebhookEvent::create([
        'event_id' => 'pre-2', 'keeta_integration_id' => $ctx['integration']->id, 'event_type' => 'CONFIRMED',
        'external_order_id' => $raw['id'], 'source' => 'webhook', 'payload' => keetaEvent('CONFIRMED', $raw['id']), 'status' => 'pending',
    ]);

    expect(fn () => (new \App\Jobs\ProcessKeetaEventJob($confirmed->id))->handle())->toThrow(RuntimeException::class, 'ainda sendo criado');
    expect($confirmed->fresh()->status)->toBe('pending')->and($confirmed->fresh()->attempts)->toBe(1);
});

it('tela do pedido mostra o resumo Keeta, cancela pelo modal e responde reembolso', function () {
    $ctx = keetaContext();
    $raw = keetaOrder($ctx);
    $order = receiveKeetaOrder($this, $ctx, $raw);
    keetaActions()->accept($order);
    $admin = \App\Models\User::factory()->create();
    $admin->companies()->attach($ctx['company']->id, ['role' => 'company_admin']);

    postKeetaWebhook($this, keetaEvent('USER_REFUND_REQUEST', $raw['id'], ['metadata' => json_encode(['money' => 1600, 'applyReason' => 'Frio'])]), $ctx['integration']->merchant_ref)->assertNoContent();
    app()->instance('current.company', $ctx['company']);

    \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Admin\Orders\Show::class, ['order' => $order->fresh()])
        ->assertSeeHtml('data-testid="keeta-order-summary"')
        ->assertSee('#1042')
        ->assertSee('Código de coleta')
        ->assertSeeHtml('data-testid="keeta-request"')
        ->assertSee('Aceitar reembolso')
        ->call('acceptKeetaRequest', \App\Models\KeetaRequest::withoutGlobalScopes()->first()->id)
        ->assertHasNoErrors()
        ->call('openKeetaCancelModal', $order->id)
        ->assertSet('keetaCancelOrderId', $order->id)
        ->assertSet('keetaCancelAccepted', true)
        ->set('keetaCancelReason', 'OUTDATED_MENU')
        ->set('keetaCancelItems', ['359379561'])
        ->call('confirmKeetaCancel')
        ->assertSet('keetaCancelOrderId', null);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/acceptRefund'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/requestCancellation') && $r['invalidItems'] === ['359379561'] && $r['code'] === 'OUTDATED_MENU');
    expect($order->fresh()->keetaDetails()->cancellationPending())->toBeTrue();
});

it('kanban leva pedido Keeta a pronto pela API', function () {
    $ctx = keetaContext();
    $order = receiveKeetaOrder($this, $ctx, keetaOrder($ctx));
    keetaActions()->accept($order);
    $admin = \App\Models\User::factory()->create(['is_super_admin' => true]);

    \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Admin\Orders\Index::class)
        ->set('channelFilter', 'keeta')
        ->call('updateOrderStatus', $order->id, 'ready')
        ->assertHasNoErrors();

    expect($order->fresh()->status)->toBe('ready');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/readyForPickup'));
});
