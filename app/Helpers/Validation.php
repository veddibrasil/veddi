<?php

namespace App\Helpers;

class Validation
{
    // Regex patterns
    const PHONE_REGEX = '/^\(?\d{2}\)?[\s\-]?\d{4,5}[\-]?\d{4}$/';

    const CEP_REGEX = '/^\d{5}-?\d{3}$/';

    const HEX_COLOR_REGEX = '/^#[0-9A-Fa-f]{6}$/';

    const SLUG_REGEX = '/^[a-z0-9\-]+$/';

    const SUBDOMAIN_REGEX = '/^[a-z0-9\-]*$/';

    const ORDER_PREFIX_REGEX = '/^[A-Z0-9]+$/';

    const CPF_REGEX = '/^\d{3}\.?\d{3}\.?\d{3}-?\d{2}$/';

    const CNPJ_REGEX = '/^\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}$/';

    // Laravel validation rule strings
    const PHONE_RULE = 'regex:/^\(?\d{2}\)?[\s\-]?\d{4,5}[\-]?\d{4}$/';

    const CEP_RULE = 'regex:/^\d{5}-?\d{3}$/';

    const HEX_COLOR_RULE = 'regex:/^#[0-9A-Fa-f]{6}$/';

    const SLUG_RULE = 'regex:/^[a-z0-9\-]+$/';

    const SUBDOMAIN_RULE = 'regex:/^[a-z0-9\-]*$/';

    const ORDER_PREFIX_RULE = 'regex:/^[A-Z0-9]+$/';

    public static function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }

    public static function normalizeCep(string $cep): string
    {
        return preg_replace('/\D/', '', $cep);
    }

    public static function normalizeCpf(string $cpf): string
    {
        return preg_replace('/\D/', '', $cpf);
    }

    public static function normalizeCnpj(string $cnpj): string
    {
        return preg_replace('/\D/', '', $cnpj);
    }

    public static function isValidPhone(string $phone): bool
    {
        return (bool) preg_match(self::PHONE_REGEX, $phone);
    }

    public static function isValidCep(string $cep): bool
    {
        return (bool) preg_match(self::CEP_REGEX, $cep);
    }

    public static function isValidHexColor(string $color): bool
    {
        return (bool) preg_match(self::HEX_COLOR_REGEX, $color);
    }

    public static function isValidSlug(string $slug): bool
    {
        return (bool) preg_match(self::SLUG_REGEX, $slug);
    }

    public static function isValidCpf(string $cpf): bool
    {
        $cpf = self::normalizeCpf($cpf);

        if (strlen($cpf) !== 11 || preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += $cpf[$i] * ($t + 1 - $i);
            }
            $remainder = (10 * $sum) % 11;
            if ($cpf[$t] != ($remainder === 10 ? 0 : $remainder)) {
                return false;
            }
        }

        return true;
    }

    public static function isValidCnpj(string $cnpj): bool
    {
        $cnpj = self::normalizeCnpj($cnpj);

        if (strlen($cnpj) !== 14 || preg_match('/(\d)\1{13}/', $cnpj)) {
            return false;
        }

        $weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        foreach ([$weights1, $weights2] as $i => $weights) {
            $sum = 0;
            foreach ($weights as $j => $weight) {
                $sum += $cnpj[$j] * $weight;
            }
            $remainder = $sum % 11;
            $digit = $remainder < 2 ? 0 : 11 - $remainder;
            if ($cnpj[12 + $i] != $digit) {
                return false;
            }
        }

        return true;
    }
}
