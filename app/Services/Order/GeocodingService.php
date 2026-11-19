<?php

namespace App\Services\Order;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GeocodingService
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    /** Política do Nominatim: no máximo 1 requisição por segundo. */
    private const MIN_INTERVAL_SECONDS = 1.0;

    private const HIT_TTL_DAYS = 30;

    private const MISS_TTL_HOURS = 6;

    /**
     * Geocodifica endereço → [latitude, longitude] ou null se não achar.
     *
     * Nominatim não tem numeração de casa para muitas ruas do Brasil. Por isso a busca
     * cai para a rua sem número (precisão de rua, suficiente para faixa de distância),
     * e por último para o centroide do CEP.
     *
     * @param  array{address: string, number: string, neighborhood: string, city: string, cep: string, state?: string}  $addressData
     * @return array{latitude: float, longitude: float}|null
     */
    public function geocode(array $addressData): ?array
    {
        $cacheKey = 'geocoding:'.sha1(json_encode($this->normalizedKey($addressData)));

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached['coords'];
        }

        $cep = $this->digitsOrNull($addressData['cep'] ?? null);
        $unavailable = false;

        foreach ($this->buildQueries($addressData) as $query) {
            $result = $this->nominatimSearch($query, $cep, $addressData);

            if ($result === false) {
                // Falha de rede/limite: não cacheia, para tentar de novo depois.
                $unavailable = true;

                break;
            }

            if ($result !== null) {
                Cache::put($cacheKey, ['coords' => $result], now()->addDays(self::HIT_TTL_DAYS));

                return $result;
            }
        }

        if (! $unavailable) {
            Cache::put($cacheKey, ['coords' => null], now()->addHours(self::MISS_TTL_HOURS));
        }

        return null;
    }

    /**
     * Consultas em ordem de precisão: com número, depois só rua, depois CEP.
     *
     * @return string[]
     */
    private function buildQueries(array $data): array
    {
        $address = trim((string) ($data['address'] ?? ''));
        $number = trim((string) ($data['number'] ?? ''));
        $neighborhood = trim((string) ($data['neighborhood'] ?? ''));
        $cep = $this->digitsOrNull($data['cep'] ?? null);
        $location = trim(($data['city'] ?? '').' '.($data['state'] ?? ''));

        $withNumber = $number !== '' ? trim($number.' '.$address) : $address;

        $queries = [];

        if ($cep !== null) {
            $queries[] = implode(', ', array_filter([$withNumber, $location, $cep, 'Brasil']));
        }

        $queries[] = implode(', ', array_filter([$withNumber, $neighborhood, $location, 'Brasil']));

        if ($number !== '') {
            // Rua sem número: Nominatim costuma ter a rua mesmo sem a casa.
            if ($cep !== null) {
                $queries[] = implode(', ', array_filter([$address, $location, $cep, 'Brasil']));
            }

            $queries[] = implode(', ', array_filter([$address, $neighborhood, $location, 'Brasil']));
        }

        // Centroide do CEP: último recurso, precisão de bairro/região.
        if ($cep !== null) {
            $queries[] = implode(', ', array_filter([$cep, $location, 'Brasil']));
        }

        return array_values(array_unique(array_filter($queries, fn ($q) => trim($q) !== '' && trim($q) !== 'Brasil')));
    }

    /**
     * @return array{latitude: float, longitude: float}|null|false null = não achou; false = serviço indisponível
     */
    private function nominatimSearch(string $query, ?string $expectedCep, array $addressData): array|null|false
    {
        $this->waitForNominatimSlot();

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'MisterCoxinha/1.0 (+https://app.veddi.com.br)',
            ])
                ->timeout(5)
                ->get(self::NOMINATIM_URL, [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 3,
                    'countrycodes' => 'br',
                    'addressdetails' => 1,
                ]);
        } catch (\Throwable $e) {
            Log::channel('chat')->warning('Geocoding indisponível (rede)', ['error' => class_basename($e)]);

            return false;
        }

        if ($response->status() === 429 || $response->serverError()) {
            Log::channel('chat')->warning('Geocoding indisponível (limite ou erro do Nominatim)', ['status' => $response->status()]);

            return false;
        }

        $results = $response->json();
        if (! is_array($results)) {
            return null;
        }

        foreach ($results as $candidate) {
            if (empty($candidate['lat']) || empty($candidate['lon'])) {
                continue;
            }

            if ($this->candidateMatches($candidate, $expectedCep, $addressData)) {
                return [
                    'latitude' => (float) $candidate['lat'],
                    'longitude' => (float) $candidate['lon'],
                ];
            }
        }

        return null;
    }

    /**
     * Rejeita match de outra região/cidade (Nominatim faz busca fuzzy e acha rua homônima).
     * Só compara quando o resultado traz o dado; OSM é esparso no Brasil.
     */
    private function candidateMatches(array $candidate, ?string $expectedCep, array $addressData): bool
    {
        $address = $candidate['address'] ?? [];

        if ($expectedCep !== null) {
            $returnedCep = $this->digitsOrNull($address['postcode'] ?? null);

            if ($returnedCep !== null && substr($returnedCep, 0, 3) !== substr($expectedCep, 0, 3)) {
                return false;
            }
        }

        $expectedCity = Str::lower(Str::ascii(trim((string) ($addressData['city'] ?? ''))));
        if ($expectedCity !== '') {
            $returnedCity = $address['city'] ?? $address['town'] ?? $address['municipality'] ?? $address['village'] ?? null;

            if ($returnedCity !== null && Str::lower(Str::ascii(trim($returnedCity))) !== $expectedCity) {
                return false;
            }
        }

        return true;
    }

    /**
     * Serializa chamadas ao Nominatim com intervalo mínimo de 1s entre elas (política de uso).
     */
    private function waitForNominatimSlot(): void
    {
        try {
            Cache::lock('geocoding:nominatim:throttle', 5)->block(5, function () {
                $last = (float) Cache::get('geocoding:nominatim:last', 0.0);
                $wait = self::MIN_INTERVAL_SECONDS - (microtime(true) - $last);

                if ($wait > 0) {
                    usleep((int) ($wait * 1_000_000));
                }

                Cache::put('geocoding:nominatim:last', microtime(true), 60);
            });
        } catch (LockTimeoutException) {
            // Fila cheia: segue sem esperar; o 429 (se vier) é tratado como indisponível.
        }
    }

    /** @return array<string, string> */
    private function normalizedKey(array $data): array
    {
        return [
            'a' => Str::lower(Str::ascii(trim((string) ($data['address'] ?? '')))),
            'n' => trim((string) ($data['number'] ?? '')),
            'b' => Str::lower(Str::ascii(trim((string) ($data['neighborhood'] ?? '')))),
            'c' => Str::lower(Str::ascii(trim((string) ($data['city'] ?? '')))),
            's' => Str::lower(trim((string) ($data['state'] ?? ''))),
            'p' => (string) $this->digitsOrNull($data['cep'] ?? null),
        ];
    }

    private function digitsOrNull(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits !== '' ? $digits : null;
    }
}
