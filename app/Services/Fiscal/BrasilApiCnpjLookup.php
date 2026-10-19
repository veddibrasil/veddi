<?php

namespace App\Services\Fiscal;

use App\Contracts\CnpjLookupInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Consulta pública de CNPJ (dados da Receita via BrasilAPI) pra preencher o destinatário da
 * NF-e no PDV — o caixa só confere. Sem autenticação; falha ou CNPJ inexistente devolvem null
 * e o caixa digita os dados. Resultado fica em cache por um dia (cadastro da Receita muda pouco).
 */
class BrasilApiCnpjLookup implements CnpjLookupInterface
{
    private const BASE_URL = 'https://brasilapi.com.br/api/cnpj/v1/';

    public function find(string $cnpj): ?array
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);

        if (strlen($cnpj) !== 14) {
            return null;
        }

        if ($cached = Cache::get("cnpj-lookup:{$cnpj}")) {
            return $cached;
        }

        try {
            $response = Http::timeout(5)->acceptJson()->get(self::BASE_URL.$cnpj);
        } catch (ConnectionException $e) {
            Log::channel('fiscal')->warning('Consulta de CNPJ indisponível', ['cnpj' => $cnpj, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::channel('fiscal')->info('Consulta de CNPJ sem resultado', ['cnpj' => $cnpj, 'status' => $response->status()]);

            return null;
        }

        $body = $response->json() ?? [];
        $name = trim((string) ($body['razao_social'] ?? ''));

        if ($name === '') {
            return null;
        }

        $street = trim(($body['descricao_tipo_de_logradouro'] ?? '').' '.($body['logradouro'] ?? ''));
        $email = $body['email'] ?? null;

        // Limites de 60 caracteres são os mesmos de FiscalRecipient::validate() (layout da NF-e).
        $data = [
            'name' => mb_substr($name, 0, 60),
            'email' => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower($email) : null,
            'street' => $street !== '' ? mb_substr($street, 0, 60) : null,
            'number' => filled($body['numero'] ?? null) ? mb_substr((string) $body['numero'], 0, 60) : 'S/N',
            'complement' => filled($body['complemento'] ?? null) ? mb_substr((string) $body['complemento'], 0, 60) : null,
            'neighborhood' => filled($body['bairro'] ?? null) ? mb_substr((string) $body['bairro'], 0, 60) : null,
            'city' => $body['municipio'] ?? null,
            'state' => $body['uf'] ?? null,
            'zip' => preg_replace('/\D/', '', (string) ($body['cep'] ?? '')) ?: null,
            'status' => $body['descricao_situacao_cadastral'] ?? null,
        ];

        Cache::put("cnpj-lookup:{$cnpj}", $data, now()->addDay());

        return $data;
    }
}
