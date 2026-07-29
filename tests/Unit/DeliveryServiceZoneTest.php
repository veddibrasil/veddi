<?php

use App\Exceptions\DeliveryException;
use App\Models\DeliverySetting;
use App\Services\Order\DeliveryService;

it('charges the fee of the zone containing the point', function () {
    $settings = new DeliverySetting([
        'fee_type' => 'zone',
        'minimum_order_value' => 0,
        'active' => true,
        'zones' => [
            ['name' => 'Centro', 'fee' => 5.0, 'active' => true, 'polygon' => [[0, 0], [0, 10], [10, 10], [10, 0]]],
        ],
    ]);

    $service = new DeliveryService;

    $result = $service->validate($settings, 'Centro', 10, 5.0, 5.0);

    expect($result['fee'])->toBe(5.0);
    expect($result['free'])->toBeFalse();
});

it('gives priority to the first matching zone when zones overlap', function () {
    $settings = new DeliverySetting([
        'fee_type' => 'zone',
        'minimum_order_value' => 0,
        'active' => true,
        'zones' => [
            ['name' => 'Núcleo', 'fee' => 5.0, 'active' => true, 'polygon' => [[4, 4], [4, 6], [6, 6], [6, 4]]],
            ['name' => 'Anel', 'fee' => 15.0, 'active' => true, 'polygon' => [[0, 0], [0, 10], [10, 10], [10, 0]]],
        ],
    ]);

    $service = new DeliveryService;

    $insideBoth = $service->validate($settings, 'Centro', 10, 5.0, 5.0);
    expect($insideBoth['fee'])->toBe(5.0);

    $insideOuterOnly = $service->validate($settings, 'Centro', 10, 1.0, 1.0);
    expect($insideOuterOnly['fee'])->toBe(15.0);
});

it('skips inactive zones and falls through to the next match', function () {
    $settings = new DeliverySetting([
        'fee_type' => 'zone',
        'minimum_order_value' => 0,
        'active' => true,
        'zones' => [
            ['name' => 'Núcleo', 'fee' => 5.0, 'active' => false, 'polygon' => [[4, 4], [4, 6], [6, 6], [6, 4]]],
            ['name' => 'Anel', 'fee' => 15.0, 'active' => true, 'polygon' => [[0, 0], [0, 10], [10, 10], [10, 0]]],
        ],
    ]);

    $service = new DeliveryService;

    $result = $service->validate($settings, 'Centro', 10, 5.0, 5.0);

    expect($result['fee'])->toBe(15.0);
});

it('blocks delivery when the point is outside every zone', function () {
    $settings = new DeliverySetting([
        'fee_type' => 'zone',
        'minimum_order_value' => 0,
        'active' => true,
        'zones' => [
            ['name' => 'Centro', 'fee' => 5.0, 'active' => true, 'polygon' => [[0, 0], [0, 10], [10, 10], [10, 0]]],
        ],
    ]);

    $service = new DeliveryService;

    $service->validate($settings, 'Centro', 10, 20.0, 20.0);
})->throws(DeliveryException::class);
