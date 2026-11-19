<?php

use App\Services\Order\GeocodingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function nominatimQuery(\Illuminate\Http\Client\Request $request): string
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);

    return (string) ($params['q'] ?? '');
}

function addressWithoutNumber(): array
{
    return [
        'address' => 'Avenida Bento Munhoz da Rocha Netto',
        'number' => '162',
        'neighborhood' => 'Zona 07',
        'city' => 'Maringá',
        'state' => 'PR',
        'cep' => '87030-010',
    ];
}

beforeEach(fn () => Cache::flush());

it('accepts a Nominatim result whose postcode matches the searched CEP', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            [
                'lat' => '-23.4152862',
                'lon' => '-51.4293961',
                'address' => ['postcode' => '86700-050', 'city' => 'Arapongas'],
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
                'address' => ['postcode' => '83609-800', 'city' => 'Campo Largo'],
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

it('rejects a result from another city even when the postcode prefix matches', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            [
                'lat' => '-23.1',
                'lon' => '-51.1',
                'address' => ['postcode' => '86700-000', 'town' => 'Sertanópolis'],
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

    // Com número: CEP, bairro; sem número: CEP, bairro; centroide do CEP.
    Http::assertSentCount(5);
});

it('falls back to the street without house number when the number is not in OSM', function () {
    Http::fake(function ($request) {
        $q = nominatimQuery($request);

        // Nominatim não tem a casa 162 nesta rua.
        if (str_starts_with($q, '162 ')) {
            return Http::response([], 200);
        }

        return Http::response([
            [
                'lat' => '-23.4186432',
                'lon' => '-51.9249037',
                'address' => ['postcode' => '87030-010', 'suburb' => 'Zona 10', 'city' => 'Maringá'],
            ],
        ], 200);
    });

    $result = app(GeocodingService::class)->geocode(addressWithoutNumber());

    expect($result)->toBe([
        'latitude' => -23.4186432,
        'longitude' => -51.9249037,
    ]);
});

it('falls back to the CEP centroid when the street is not found', function () {
    Http::fake(function ($request) {
        $q = nominatimQuery($request);

        if (str_starts_with($q, '87030010, ')) {
            return Http::response([
                ['lat' => '-23.41786', 'lon' => '-51.92337', 'address' => ['postcode' => '87030-010', 'city' => 'Maringá']],
            ], 200);
        }

        return Http::response([], 200);
    });

    $result = app(GeocodingService::class)->geocode(addressWithoutNumber());

    expect($result)->toBe([
        'latitude' => -23.41786,
        'longitude' => -51.92337,
    ]);
});

it('caches a found address so repeated lookups do not call Nominatim again', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            ['lat' => '-23.4186432', 'lon' => '-51.9249037', 'address' => ['postcode' => '87030-010', 'city' => 'Maringá']],
        ], 200),
    ]);

    $first = app(GeocodingService::class)->geocode(addressWithoutNumber());
    $sentAfterFirst = Http::recorded()->count();

    $second = app(GeocodingService::class)->geocode(addressWithoutNumber());

    expect($second)->toBe($first);
    expect(Http::recorded()->count())->toBe($sentAfterFirst);
});

it('does not cache when Nominatim rate limits, so a later attempt can succeed', function () {
    $rateLimited = true;

    Http::fake(function () use (&$rateLimited) {
        return $rateLimited
            ? Http::response('Rate limited', 429)
            : Http::response([
                ['lat' => '-23.4186432', 'lon' => '-51.9249037', 'address' => ['postcode' => '87030-010', 'city' => 'Maringá']],
            ], 200);
    });

    expect(app(GeocodingService::class)->geocode(addressWithoutNumber()))->toBeNull();

    $rateLimited = false;

    expect(app(GeocodingService::class)->geocode(addressWithoutNumber()))->toBe([
        'latitude' => -23.4186432,
        'longitude' => -51.9249037,
    ]);
});
