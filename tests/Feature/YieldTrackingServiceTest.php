<?php

use App\Models\PlatformYieldSnapshot;
use App\Services\Finance\BacenService;
use App\Services\Finance\YieldTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('BacenService parses response correctly', function () {
    Http::fake([
        'api.bcb.gov.br/*' => Http::response([
            ['data' => '09/05/2026', 'valor' => '0.052611'],
        ], 200),
    ]);

    Cache::flush();

    $rate = app(BacenService::class)->getDailyCdiRate();

    expect(round($rate, 8))->toBe(0.00052611);
});

it('BacenService returns fallback when BACEN unavailable', function () {
    Http::fake([
        'api.bcb.gov.br/*' => Http::response([], 500),
    ]);

    Cache::flush();
    config()->set('services.stark.cdi_rate_fallback_daily', 0.000527);

    $rate = app(BacenService::class)->getDailyCdiRate();

    expect($rate)->toBe(0.000527);
});

it('BacenService uses cache on second call', function () {
    Http::fake([
        'api.bcb.gov.br/*' => Http::response([
            ['data' => '09/05/2026', 'valor' => '0.052611'],
        ], 200),
    ]);

    Cache::flush();

    $service = app(BacenService::class);
    $service->getDailyCdiRate();
    $service->getDailyCdiRate();

    Http::assertSentCount(1);
});

it('recordSnapshot creates record with correct values', function () {
    $mockBacen = Mockery::mock(BacenService::class);
    $mockBacen->shouldReceive('getDailyCdiRate')->andReturn(0.000527);

    $service = new YieldTrackingService($mockBacen);
    $snapshot = $service->recordSnapshot(10000.00, 500.00);

    expect($snapshot->vindi_balance)->toEqual('10000.00')
        ->and($snapshot->asaas_balance)->toEqual('500.00')
        ->and($snapshot->total_float)->toEqual('10500.00')
        ->and((float) $snapshot->yield_amount)->toEqual(round(10500.00 * 0.000527, 2))
        ->and($snapshot->snapshot_date->toDateString())->toBe(now()->toDateString());
});

it('recordSnapshot is idempotent', function () {
    $mockBacen = Mockery::mock(BacenService::class);
    $mockBacen->shouldReceive('getDailyCdiRate')->once()->andReturn(0.000527);

    $service = new YieldTrackingService($mockBacen);
    $first = $service->recordSnapshot(10000.00, 500.00);
    $second = $service->recordSnapshot(10000.00, 500.00);

    expect($first->id)->toBe($second->id);
    expect(PlatformYieldSnapshot::count())->toBe(1);
});

it('monthlyYield sums records correctly', function () {
    $mockBacen = Mockery::mock(BacenService::class);
    $service = new YieldTrackingService($mockBacen);

    PlatformYieldSnapshot::create([
        'snapshot_date' => now()->startOfMonth()->toDateString(),
        'vindi_balance' => 10000,
        'asaas_balance' => 500,
        'total_float' => 10500,
        'cdi_rate_annual' => 13.75,
        'cdi_rate_daily' => 0.000527,
        'yield_amount' => 5.53,
        'cumulative_yield_month' => 5.53,
        'cumulative_yield_total' => 5.53,
    ]);

    PlatformYieldSnapshot::create([
        'snapshot_date' => now()->startOfMonth()->addDay()->toDateString(),
        'vindi_balance' => 10000,
        'asaas_balance' => 500,
        'total_float' => 10500,
        'cdi_rate_annual' => 13.75,
        'cdi_rate_daily' => 0.000527,
        'yield_amount' => 5.53,
        'cumulative_yield_month' => 11.06,
        'cumulative_yield_total' => 11.06,
    ]);

    $total = $service->monthlyYield(now()->year, now()->month);

    expect($total)->toEqual(11.06);
});
