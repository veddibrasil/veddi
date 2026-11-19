<?php

namespace App\Services\Fiscal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FiscalPostalCodeLookup
{
    /** Endereço público do CEP; número e complemento continuam com o comprador. */
    public function find(string $postalCode): ?array
    {
        $postalCode = preg_replace('/\D/', '', $postalCode);
        if (strlen($postalCode) !== 8) {
            return null;
        }
        $key = 'fiscal-postal-code:'.$postalCode;
        if ($cached = Cache::get($key)) {
            return $cached;
        }
        try {
            $response = Http::connectTimeout(1)->timeout(3)->acceptJson()->get('https://viacep.com.br/ws/'.$postalCode.'/json/');
        } catch (ConnectionException) {
            return null;
        }
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ! empty($body['erro']) || ! is_string($body['cep'] ?? null)
            || preg_replace('/\D/', '', (string) ($body['cep'] ?? '')) !== $postalCode) {
            return null;
        }
        $data = [];
        foreach (['street' => 'logradouro', 'neighborhood' => 'bairro', 'city' => 'localidade', 'state' => 'uf'] as $field => $source) {
            if (is_string($body[$source] ?? null) && filled($body[$source])) {
                $data[$field] = mb_substr(trim($body[$source]), 0, $field === 'state' ? 2 : 60);
            }
        }
        Cache::put($key, $data, now()->addDay());

        return $data;
    }
}
