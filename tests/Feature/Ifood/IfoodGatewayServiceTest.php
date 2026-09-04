<?php

use App\Services\Ifood\IfoodGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('updateOrderStatus(out_for_delivery) chama dispatch com deliveredBy=MERCHANT', function () {
    $ctx = ifoodContext('gw1');

    Http::fake(['*/order/v1.0/orders/*/dispatch' => Http::response([], 202)]);

    (new IfoodGatewayService(app(\App\Services\Ifood\IfoodAuthService::class)))
        ->updateOrderStatus($ctx['integration'], 'ifood-order-x', 'out_for_delivery');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/order/v1.0/orders/ifood-order-x/dispatch')
        && $request['deliveredBy'] === 'MERCHANT');
});

test('updateOrderStatus(preparing) chama startPreparation sem body', function () {
    $ctx = ifoodContext('gw2');

    Http::fake(['*/order/v1.0/orders/*/startPreparation' => Http::response([], 202)]);

    (new IfoodGatewayService(app(\App\Services\Ifood\IfoodAuthService::class)))
        ->updateOrderStatus($ctx['integration'], 'ifood-order-x', 'preparing');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/startPreparation')
        && $request->data() === []);
});

test('updateOrderStatus(ready) chama readyToPickup sem body', function () {
    $ctx = ifoodContext('gw3');

    Http::fake(['*/order/v1.0/orders/*/readyToPickup' => Http::response([], 202)]);

    (new IfoodGatewayService(app(\App\Services\Ifood\IfoodAuthService::class)))
        ->updateOrderStatus($ctx['integration'], 'ifood-order-x', 'ready');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/readyToPickup')
        && $request->data() === []);
});

test('updateOrderStatus com status sem endpoint correspondente lança exceção', function () {
    $ctx = ifoodContext('gw4');

    expect(fn () => (new IfoodGatewayService(app(\App\Services\Ifood\IfoodAuthService::class)))
        ->updateOrderStatus($ctx['integration'], 'ifood-order-x', 'delivered'))
        ->toThrow(RuntimeException::class);
});

test('createCategory com 409 de nome duplicado reaproveita o id do conflito em vez de lançar', function () {
    $ctx = ifoodContext('gw5');

    Http::fake([
        '*/catalog/v2.0/merchants/*/catalogs' => Http::response([['catalogId' => 'catalog-1']], 200),
        '*/catalog/v2.0/merchants/*/catalogs/*/categories' => Http::response([
            'error' => [
                'code' => 'Conflict',
                'message' => 'Cannot have two categories with same name, names that already exists: [Salgados]: [f9886bb3-890a-4a1f-a450-80022c44cb8c]',
                'conflictingResources' => ['f9886bb3-890a-4a1f-a450-80022c44cb8c'],
            ],
        ], 409),
    ]);

    $categoryId = (new IfoodGatewayService(app(\App\Services\Ifood\IfoodAuthService::class)))
        ->createCategory($ctx['integration'], 'Salgados');

    expect($categoryId)->toBe('f9886bb3-890a-4a1f-a450-80022c44cb8c');
});

test('createCategory com 409 sem conflictingResources ainda lança exceção', function () {
    $ctx = ifoodContext('gw6');

    Http::fake([
        '*/catalog/v2.0/merchants/*/catalogs' => Http::response([['catalogId' => 'catalog-1']], 200),
        '*/catalog/v2.0/merchants/*/catalogs/*/categories' => Http::response([
            'error' => ['code' => 'Conflict', 'message' => 'algo diferente'],
        ], 409),
    ]);

    expect(fn () => (new IfoodGatewayService(app(\App\Services\Ifood\IfoodAuthService::class)))
        ->createCategory($ctx['integration'], 'Salgados'))
        ->toThrow(RuntimeException::class);
});
