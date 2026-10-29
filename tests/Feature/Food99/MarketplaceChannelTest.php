<?php

use App\Enums\OrderChannel;
use App\Models\Order;
use App\Support\Ifood\IfoodOrderDetails;
use App\Support\Marketplace\MarketplaceOrderDetails;

it('trata iFood e 99Food como marketplace e o chat não', function () {
    expect(OrderChannel::Ifood->isMarketplace())->toBeTrue()
        ->and(OrderChannel::Food99->isMarketplace())->toBeTrue()
        ->and(OrderChannel::Chat->isMarketplace())->toBeFalse()
        ->and(OrderChannel::isMarketplaceValue('99food'))->toBeTrue()
        ->and(OrderChannel::isMarketplaceValue(null))->toBeFalse()
        ->and(OrderChannel::isMarketplaceValue('pdv'))->toBeFalse()
        ->and(OrderChannel::marketplaceValues())->toBe(['ifood', '99food'])
        ->and(OrderChannel::Food99->label())->toBe('99Food')
        ->and(OrderChannel::Food99->availabilityColumn())->toBe('available_in_99food')
        ->and(OrderChannel::Food99->logChannel())->toBe('food99');
});

it('expõe o snapshot do iFood pela interface de marketplace', function () {
    $order = new Order(['channel' => 'ifood', 'order_number' => 'MC-1', 'status' => 'pending']);
    $order->external_metadata = ['display_id' => '5396', 'ifood_status' => 'PLACED'];

    $details = $order->marketplaceDetails();

    expect($details)->toBeInstanceOf(MarketplaceOrderDetails::class)
        ->toBeInstanceOf(IfoodOrderDetails::class)
        ->and($details->channelLabel())->toBe('iFood')
        ->and($details->confirmationMinutes())->toBe(IfoodOrderDetails::CONFIRMATION_MINUTES)
        ->and($order->isMarketplaceOrder())->toBeTrue()
        ->and($order->isEditable())->toBeFalse()
        ->and($order->displayNumber())->toBe('iFood #5396')
        ->and($order->origin_label)->toBe('iFood');
});

it('mantém pedido do chat fora do marketplace', function () {
    $order = new Order(['channel' => 'chat', 'order_number' => 'MC-2', 'status' => 'pending', 'order_type' => 'delivery']);

    expect($order->marketplaceDetails())->toBeNull()
        ->and($order->isMarketplaceOrder())->toBeFalse()
        ->and($order->displayNumber())->toBe('MC-2')
        ->and($order->isEditable())->toBeTrue();
});
