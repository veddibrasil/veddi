<?php

use App\Exceptions\DeliveryException;
use App\Models\DeliverySetting;
use App\Services\Order\DeliveryService;

it('blocks delivery when outside service radius', function () {
    $settings = new DeliverySetting([
        'fee_type' => 'flat',
        'flat_fee' => 5,
        'minimum_order_value' => 0,
        'branch_latitude' => 0.0,
        'branch_longitude' => 0.0,
        'service_radius_km' => 1.0,
        'active' => true,
    ]);

    $service = new DeliveryService;

    $service->validate($settings, 'Centro', 10, 0.0, 0.01);
})->throws(DeliveryException::class);

it('allows delivery when inside service radius', function () {
    $settings = new DeliverySetting([
        'fee_type' => 'flat',
        'flat_fee' => 5,
        'minimum_order_value' => 0,
        'branch_latitude' => 0.0,
        'branch_longitude' => 0.0,
        'service_radius_km' => 2.0,
        'active' => true,
    ]);

    $service = new DeliveryService;

    $result = $service->validate($settings, 'Centro', 10, 0.0, 0.01);

    expect($result['fee'])->toBe(5.0);
    expect($result['free'])->toBeFalse();
});
