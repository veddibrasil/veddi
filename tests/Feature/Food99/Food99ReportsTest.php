<?php

use App\DTOs\SoldItemsFilters;
use App\Livewire\Admin\Fiscal\IssueControl;
use App\Models\Order;
use App\Models\User;
use App\Services\Food99\Food99OrderActionService;
use App\Services\Sales\SoldItemsReportService;
use App\Services\SuperAdmin\CompanyCommercialInsights;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function food99ConfirmedOrder(\Illuminate\Foundation\Testing\TestCase $test, array $ctx): Order
{
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $order = receiveFood99Order($test, $ctx, $info);
    app(Food99OrderActionService::class)->accept($order);

    return $order->fresh();
}

it('conta o canal 99Food à parte no relatório de itens vendidos', function () {
    $ctx = food99Context();
    food99ConfirmedOrder($this, $ctx);

    $filters = new SoldItemsFilters(CarbonImmutable::today()->subDay(), CarbonImmutable::today()->endOfDay());
    $report = app(SoldItemsReportService::class)->build($ctx['company']->id, $filters);

    expect($report['channels'])->toHaveKey('99food')
        ->and($report['channels'])->not->toHaveKey('chat')
        ->and(SoldItemsReportService::channelOf('99food', 'delivery'))->toBe('99food');

    $only99 = app(SoldItemsReportService::class)->build($ctx['company']->id, new SoldItemsFilters(CarbonImmutable::today()->subDay(), CarbonImmutable::today()->endOfDay(), channel: '99food'));
    $onlyChat = app(SoldItemsReportService::class)->build($ctx['company']->id, new SoldItemsFilters(CarbonImmutable::today()->subDay(), CarbonImmutable::today()->endOfDay(), channel: 'chat'));
    expect($only99['kpis']['orders'] ?? $only99['kpis']['count'] ?? null)->not->toBe(0)
        ->and($onlyChat['products'])->toBe([]);
});

it('filtra pedidos 99Food no controle fiscal', function () {
    $ctx = food99Context();
    $order = food99ConfirmedOrder($this, $ctx);
    $ctx['company']->forceFill(['fiscal_notes_enabled' => true])->save();
    app()->instance('current.company', $ctx['company']->fresh());

    Livewire::actingAs(User::factory()->create(['is_super_admin' => true]))
        ->test(IssueControl::class)
        ->set('channelFilter', '99food')
        ->assertSee($order->order_number)
        ->assertSee('99Food')
        ->set('channelFilter', 'chat')
        ->assertDontSee($order->order_number);
});

it('mostra pedidos 99Food na visão comercial do super admin', function () {
    $ctx = food99Context();
    food99ConfirmedOrder($this, $ctx);
    Http::fake();

    $data = app(CompanyCommercialInsights::class)->forCompany($ctx['company']);

    expect($data['usage']['food99_orders'])->toBe(1)
        ->and($data['usage']['ifood_orders'])->toBe(0);
});
