<?php

namespace App\Services\Order;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodingService
{
    /**
     * Geocodifica endereço → [latitude, longitude] ou null se falhar.
     *
     * @param  array{address: string, number: string, neighborhood: string, city: string, cep: string}  $addressData
     * @return array{latitude: float, longitude: float}|null
     */
    public function geocode(array $addressData): ?array
    {
        foreach ($this->buildQueries($addressData) as $query) {
            $result = $this->nominatimSearch($query);

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

        // CEP-based query — most reliable anchor for Brazilian addresses
        if (! empty($data['cep'])) {
            $cep = preg_replace('/\D/', '', $data['cep']);
            $parts = array_filter([$data['number'] ?? '', $data['address'] ?? '', $cep]);
            $queries[] = implode(' ', $parts).', Brasil';
        }

        // Full address fallback
        $full = array_filter([
            trim(($data['number'] ?? '').' '.($data['address'] ?? '')),
            $data['neighborhood'] ?? '',
            $data['city'] ?? '',
            'Brasil',
        ]);
        $queries[] = implode(', ', $full);

        return array_unique($queries);
    }

    private function nominatimSearch(string $query): ?array
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
                ]);

            $results = $response->json();

            if (! empty($results[0]['lat']) && ! empty($results[0]['lon'])) {
                return [
                    'latitude' => (float) $results[0]['lat'],
                    'longitude' => (float) $results[0]['lon'],
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Geocoding failed', ['query' => $query, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
