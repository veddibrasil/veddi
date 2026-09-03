<?php

namespace App\Services\Order;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodingService
{
    /**
     * Geocodifica endereço → [latitude, longitude] ou null se falhar.
     *
     * @param  array{address: string, number: string, neighborhood: string, city: string, cep: string, state?: string}  $addressData
     * @return array{latitude: float, longitude: float}|null
     */
    public function geocode(array $addressData): ?array
    {
        $cep = ! empty($addressData['cep']) ? preg_replace('/\D/', '', $addressData['cep']) : null;

        foreach ($this->buildQueries($addressData) as $query) {
            $result = $this->nominatimSearch($query, $cep);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function buildQueries(array $data): array
    {
        $queries = [];

        $city = $data['city'] ?? '';
        $state = $data['state'] ?? '';
        $location = trim($city.' '.$state);

        // CEP-based query, anchored with city/state to avoid matching a
        // same-named street in an unrelated municipality.
        if (! empty($data['cep'])) {
            $cep = preg_replace('/\D/', '', $data['cep']);
            $parts = array_filter([$data['number'] ?? '', $data['address'] ?? '', $location, $cep]);
            $queries[] = implode(', ', $parts).', Brasil';
        }

        // Full address fallback
        $full = array_filter([
            trim(($data['number'] ?? '').' '.($data['address'] ?? '')),
            $data['neighborhood'] ?? '',
            $location,
            'Brasil',
        ]);
        $queries[] = implode(', ', $full);

        return array_unique($queries);
    }

    private function nominatimSearch(string $query, ?string $expectedCep): ?array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'MisterCoxinha/1.0 guilhermeieski@gmail.com',
            ])
                ->timeout(5)
                ->retry(2, 500, throw: false)
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 1,
                    'countrycodes' => 'br',
                    'addressdetails' => 1,
                ]);

            $results = $response->json();

            if (empty($results[0]['lat']) || empty($results[0]['lon'])) {
                return null;
            }

            // Reject a match whose returned CEP doesn't belong to the same
            // "region" as the CEP we searched for — Nominatim's free-text
            // search happily matches a same-named street in a different
            // city/state, which produces wildly wrong coordinates.
            if ($expectedCep !== null) {
                $returnedCep = preg_replace('/\D/', '', $results[0]['address']['postcode'] ?? '');

                if ($returnedCep !== '' && substr($returnedCep, 0, 5) !== substr($expectedCep, 0, 5)) {
                    Log::warning('Geocoding rejeitado: CEP retornado diverge do CEP buscado', [
                        'query' => $query,
                        'expected_cep' => $expectedCep,
                        'returned_cep' => $returnedCep,
                    ]);

                    return null;
                }
            }

            return [
                'latitude' => (float) $results[0]['lat'],
                'longitude' => (float) $results[0]['lon'],
            ];
        } catch (\Exception $e) {
            Log::warning('Geocoding failed', ['query' => $query, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
