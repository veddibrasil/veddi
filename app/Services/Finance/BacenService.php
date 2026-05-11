<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BacenService
{
    private const CDI_SERIES = 11;

    private const CACHE_TTL = 86400;

    public function getDailyCdiRate(): float
    {
        $today = now()->toDateString();
        $cacheKey = "bacen_cdi_daily_{$today}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            try {
                $response = Http::timeout(10)->get(
                    'https://api.bcb.gov.br/dados/serie/bcdata.sgs.'.self::CDI_SERIES.'/dados/ultimos/1?formato=json'
                );

                if ($response->successful()) {
                    $data = $response->json();

                    if (! empty($data[0]['valor'])) {
                        return (float) $data[0]['valor'] / 100;
                    }
                }
            } catch (\Throwable $e) {
                Log::channel('payments')->warning('BacenService: falha ao buscar CDI do SGS', [
                    'error' => $e->getMessage(),
                ]);
            }

            return (float) config('services.stark.cdi_rate_fallback_daily', 0.000527);
        });
    }
}
