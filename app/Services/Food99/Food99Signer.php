<?php

namespace App\Services\Food99;

/**
 * Assinaturas do 99Food Protocol.
 *
 * - Endpoints de app (lojas autorizadas, vínculo, financeiro): sign = md5("k1=v1&k2=v2..." . app_secret),
 *   chaves em ordem ASCII, sem "sign" e sem parâmetros vazios.
 * - Callbacks: header didi-header-sign = md5(corpo bruto . app_secret).
 */
class Food99Signer
{
    public function appSign(array $params, ?string $secret = null): string
    {
        unset($params['sign']);
        ksort($params, SORT_STRING);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // Quirk documentado pela 99: o SDK PHP deles concatena array como a string
            // literal "Array" (ex.: shop_infos=Array). O servidor assina igual, então
            // reproduzimos de propósito.
            $pairs[] = $key.'='.(is_array($value) ? 'Array' : (is_bool($value) ? (int) $value : $value));
        }

        return md5(implode('&', $pairs).($secret ?? (string) config('food99.app_secret')));
    }

    public function webhookSign(string $rawBody, ?string $secret = null): string
    {
        return md5($rawBody.($secret ?? (string) config('food99.app_secret')));
    }

    public function validWebhookSign(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('food99.app_secret');

        return $secret !== '' && is_string($signature) && $signature !== ''
            && hash_equals($this->webhookSign($rawBody, $secret), strtolower(trim($signature)));
    }
}
