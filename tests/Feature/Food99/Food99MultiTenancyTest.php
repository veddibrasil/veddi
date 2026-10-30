<?php

use App\Models\Customer;
use App\Models\Food99Request;
use App\Models\Order;
use App\Services\Food99\Food99OrderActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('aplica o callback só no pedido da empresa dona da loja', function () {
    $a = food99Context('A');
    $b = food99Context('B');
    $infoA = food99OrderInfo($a);
    $infoB = food99OrderInfo($b);
    fakeFood99Api($infoB);

    $orderA = receiveFood99Order($this, $a, $infoA);
    $orderB = receiveFood99Order($this, $b, array_merge($infoB, ['order_id' => '5764607523034939001']));

    postFood99Webhook($this, food99Callback($b['integration'], 'orderConfirm', ['order_id' => '5764607523034939001']))->assertOk();

    expect($orderA->fresh()->status)->toBe('pending')
        ->and($orderB->fresh()->status)->toBe('preparing')
        ->and($orderA->company_id)->toBe($a['company']->id)
        ->and($orderB->company_id)->toBe($b['company']->id);
});

it('ignora callback de pedido de outra empresa enviado pela loja errada', function () {
    $a = food99Context('A');
    $b = food99Context('B');
    $infoA = food99OrderInfo($a);
    fakeFood99Api($infoA);
    $orderA = receiveFood99Order($this, $a, $infoA);

    // Loja B manda confirmação com o order_id do pedido da empresa A.
    postFood99Webhook($this, food99Callback($b['integration'], 'orderConfirm', ['order_id' => $infoA['order_id']]))->assertOk();
    postFood99Webhook($this, food99Callback($b['integration'], 'orderCancelApply', ['order_id' => $infoA['order_id'], 'apply_id' => '991']))->assertOk();

    expect($orderA->fresh()->status)->toBe('pending')
        ->and(Food99Request::withoutGlobalScopes()->sole()->order_id)->toBeNull()
        ->and(Food99Request::withoutGlobalScopes()->sole()->company_id)->toBe($b['company']->id);
});

it('opera o pedido sempre com o token da loja dona dele', function () {
    $a = food99Context('A');
    $b = food99Context('B');
    $infoB = food99OrderInfo($b);
    fakeFood99Api($infoB);
    $orderB = receiveFood99Order($this, $b, $infoB);

    app(Food99OrderActionService::class)->accept($orderB);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/confirm') && $r['auth_token'] === 'token-99-B');
    Http::assertNotSent(fn (Request $r) => $r['auth_token'] === 'token-99-A');
    expect(Customer::withoutGlobalScopes()->where('company_id', $a['company']->id)->count())->toBe(0)
        ->and(Order::withoutGlobalScopes()->where('company_id', $a['company']->id)->count())->toBe(0);
});
