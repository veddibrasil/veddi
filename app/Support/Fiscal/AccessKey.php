<?php

namespace App\Support\Fiscal;

/**
 * Chave de acesso de 44 posições. Com o CNPJ alfanumérico, as posições 7 a 20 (CNPJ do
 * emitente) podem ter letras; as demais seguem numéricas. Remover tudo que não é dígito
 * corromperia a chave, então a normalização tira só o prefixo "NFe" e separadores.
 */
final class AccessKey
{
    private const PATTERN = '/^\d{6}[0-9A-Z]{12}\d{26}$/';

    public static function normalize(?string $key): string
    {
        return preg_replace('/[^0-9A-Z]/', '', mb_strtoupper(preg_replace('/^NFe/i', '', trim((string) $key))));
    }

    public static function isValid(?string $key): bool
    {
        return (bool) preg_match(self::PATTERN, (string) $key);
    }

    /** CNPJ do emitente contido na chave (posições 7 a 20). */
    public static function issuerCnpj(string $key): string
    {
        return substr($key, 6, 14);
    }
}
