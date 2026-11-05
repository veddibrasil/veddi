<?php

namespace App\Services\Keeta;

use InvalidArgumentException;
use stdClass;

/**
 * Assinatura X-App-Signature da Keeta (Open Delivery), nos dois sentidos.
 *
 *   signature_string = URL_sem_query [+ "&" + k=v&k=v (chaves em ordem)] [+ "&" + corpo]
 *   X-App-Signature  = base64(HMAC-SHA256(signature_string, client_secret))
 *
 * O corpo entra canonicalizado em RFC 8785 (JCS): chaves ordenadas, sem espaços, números no
 * formato do JavaScript e sem escapar "/" nem acentos. Corpo vazio ou "{}" fica de fora.
 *
 * Nas chamadas de saída o VEDDI envia exatamente o JSON canônico que assinou: o servidor da
 * Keeta chega ao mesmo texto canonicalizando ou usando o corpo cru. Nos webhooks a doc diverge
 * entre "HMAC do corpo" e o algoritmo completo, então validWebhookSign aceita as duas formas,
 * com o corpo cru e com o canônico. [VALIDAR Keeta] qual delas a Keeta usa de fato.
 */
class KeetaSigner
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    /**
     * @param  array<string, scalar|null>  $query
     * @param  string  $body  corpo JSON exatamente como vai (ou veio) na requisição
     */
    public function signatureString(string $url, array $query, string $body): string
    {
        $string = $this->baseUrl($url);

        if ($query !== []) {
            ksort($query, SORT_STRING);
            foreach ($query as $key => $value) {
                $string .= '&'.$key.'='.$this->queryValue($value);
            }
        }

        $body = trim($body);
        if ($body !== '' && $body !== '{}') {
            $string .= '&'.$body;
        }

        return $string;
    }

    public function sign(string $url, array $query, string $body, ?string $secret = null): string
    {
        return base64_encode(hash_hmac('sha256', $this->signatureString($url, $query, $body), $secret ?? $this->secret(), true));
    }

    /**
     * Assinatura de um webhook recebido. $url é a URL chamada pela Keeta (sem query); $query,
     * os parâmetros dela.
     */
    public function validWebhookSign(string $rawBody, ?string $signature, string $url, array $query = []): bool
    {
        $secret = $this->secret();
        $signature = is_string($signature) ? trim($signature) : '';

        if ($secret === '' || $signature === '') {
            return false;
        }

        $bodies = [$rawBody];
        $canonical = $this->canonicalizeRaw($rawBody);
        if ($canonical !== null && $canonical !== $rawBody) {
            $bodies[] = $canonical;
        }

        foreach ($bodies as $body) {
            $candidates = [
                base64_encode(hash_hmac('sha256', $body, $secret, true)),
                $this->sign($url, $query, $body, $secret),
            ];

            foreach ($candidates as $expected) {
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * JSON canônico (RFC 8785) de um valor PHP. Lista vira array; array associativo e stdClass
     * viram objeto (use new stdClass ou (object) [] pra objeto vazio).
     */
    public function canonicalize(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => $this->number($value),
            is_string($value) => json_encode($value, self::JSON_FLAGS),
            $value instanceof stdClass => $this->object(get_object_vars($value)),
            is_array($value) && array_is_list($value) => '['.implode(',', array_map(fn ($item) => $this->canonicalize($item), $value)).']',
            is_array($value) => $this->object($value),
            $value instanceof \JsonSerializable => $this->canonicalize($value->jsonSerialize()),
            default => throw new InvalidArgumentException('Keeta: tipo não serializável em JSON canônico: '.get_debug_type($value)),
        };
    }

    /** Canonicaliza um JSON recebido; null quando o texto não é JSON válido. */
    public function canonicalizeRaw(string $json): ?string
    {
        if (trim($json) === '') {
            return null;
        }

        try {
            // Objetos como stdClass: preserva {} vazio (array PHP vazio viraria []).
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $this->canonicalize($decoded);
    }

    private function object(array $members): string
    {
        $keys = array_map('strval', array_keys($members));

        // RFC 8785 ordena pelas unidades UTF-16 do nome: UTF-16BE comparado byte a byte dá o mesmo.
        usort($keys, fn (string $a, string $b) => strcmp(
            mb_convert_encoding($a, 'UTF-16BE', 'UTF-8'),
            mb_convert_encoding($b, 'UTF-16BE', 'UTF-8'),
        ));

        $parts = [];
        foreach ($keys as $key) {
            $parts[] = json_encode($key, self::JSON_FLAGS).':'.$this->canonicalize($members[$key]);
        }

        return '{'.implode(',', $parts).'}';
    }

    /** Number.prototype.toString do JavaScript: 10.0 → "10", 1.0E-7 → "1e-7". */
    private function number(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('Keeta: número não finito no JSON.');
        }

        if ($value == 0.0) {
            return '0';
        }

        if (floor($value) === $value && abs($value) < 1e21) {
            return sprintf('%.0f', $value);
        }

        $text = (string) json_encode($value, JSON_THROW_ON_ERROR);

        if (stripos($text, 'e') === false) {
            return $text;
        }

        [$mantissa, $exponent] = explode('e', strtolower($text), 2);
        $mantissa = str_contains($mantissa, '.') ? rtrim(rtrim($mantissa, '0'), '.') : $mantissa;
        $exponent = (int) $exponent;

        return $mantissa.'e'.($exponent >= 0 ? '+' : '-').abs($exponent);
    }

    private function queryValue(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    private function baseUrl(string $url): string
    {
        $position = strpos($url, '?');

        return $position === false ? $url : substr($url, 0, $position);
    }

    private function secret(): string
    {
        return (string) config('keeta.client_secret');
    }
}
