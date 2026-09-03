<?php

use App\Services\Order\GeocodingService;
use Illuminate\Support\Facades\Http;

it('accepts a Nominatim result whose postcode matches the searched CEP', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            [
                'lat' => '-23.4152862',
                'lon' => '-51.4293961',
                'address' => ['postcode' => '86700-050'],
            ],
        ], 200),
    ]);

    $result = app(GeocodingService::class)->geocode([
        'address' => 'Avenida Arapongas',
        'number' => '115',
        'neighborhood' => 'Centro',
        'city' => 'Arapongas',
        'state' => 'PR',
        'cep' => '86700-050',
    ]);

    expect($result)->toBe([
        'latitude' => -23.4152862,
        'longitude' => -51.4293961,
    ]);
});

it('rejects a Nominatim result whose postcode belongs to a different region', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            [
                'lat' => '-25.4510709',
                'lon' => '-49.3907789',
                'address' => ['postcode' => '83609-800'],
            ],
        ], 200),
    ]);

    $result = app(GeocodingService::class)->geocode([
        'address' => 'Avenida Arapongas',
        'number' => '115',
        'neighborhood' => 'Centro',
        'city' => 'Arapongas',
        'state' => 'PR',
        'cep' => '86700-050',
    ]);

    expect($result)->toBeNull();
});

it('builds the CEP-anchored query with city and state to disambiguate same-named streets', function () {
    Http::fake(function ($request) {
        expect($request->url())->toContain('Arapongas');
        expect($request->url())->toContain('PR');

        return Http::response([], 200);
    });

    app(GeocodingService::class)->geocode([
        'address' => 'Avenida Arapongas',
        'number' => '115',
        'neighborhood' => 'Centro',
        'city' => 'Arapongas',
        'state' => 'PR',
        'cep' => '86700-050',
    ]);

    Http::assertSentCount(2);
});
