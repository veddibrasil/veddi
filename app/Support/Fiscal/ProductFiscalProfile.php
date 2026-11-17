<?php

namespace App\Support\Fiscal;

/**
 * Perfil fiscal efetivo de um produto numa filial.
 *
 * O cadastro geral (products.fiscal_data) vale para todas as filiais. O perfil da filial
 * (branch_product.fiscal_data) guarda só as exceções: campo preenchido nele vale naquela
 * filial; campo vazio usa o cadastro geral.
 *
 * Exceção à herança: quando o perfil da filial e o cadastro geral são de regimes diferentes
 * (CST de 2 dígitos x CSOSN de 3 dígitos), o perfil vale sozinho. PIS, COFINS e alíquotas do
 * Simples nunca completam um perfil do regime normal (nem o contrário).
 *
 * Campos adicionais (taxes) acompanham o CST: alíquota de ICMS/PIS/COFINS do cadastro geral
 * só é herdada quando a filial não troca o CST daquele imposto.
 */
final class ProductFiscalProfile
{
    /** Campos do cadastro fiscal do produto, com o rótulo da tela. */
    public const FIELDS = [
        'ncm' => 'NCM',
        'cfop' => 'CFOP interno',
        'interstate_cfop' => 'CFOP interestadual',
        'icms_situation' => 'CST / CSOSN ICMS',
        'origin' => 'Origem',
        'unit' => 'Unidade comercial',
        'cest' => 'CEST',
        'pis_situation' => 'CST PIS',
        'cofins_situation' => 'CST COFINS',
    ];

    /** Prefixo do campo adicional => CST que ele acompanha. */
    private const TAX_SITUATION = [
        'icms_' => 'icms_situation',
        'pis_' => 'pis_situation',
        'cofins_' => 'cofins_situation',
    ];

    public static function filled(mixed $value): bool
    {
        return $value !== null && ! is_array($value) && trim((string) $value) !== '';
    }

    /** 'simples' (CSOSN, 3 dígitos), 'normal' (CST, 2 dígitos) ou null. */
    public static function regimeFamily(mixed $icmsSituation): ?string
    {
        $code = self::filled($icmsSituation) ? trim((string) $icmsSituation) : '';

        return match (strlen($code)) {
            3 => 'simples',
            2 => 'normal',
            default => null,
        };
    }

    /** Perfil da filial de regime diferente do cadastro geral: vale sozinho, sem herdar nada. */
    public static function isStandalone(?array $general, ?array $branch): bool
    {
        if ($branch === null) {
            return false;
        }
        $generalFamily = self::regimeFamily($general['icms_situation'] ?? null);
        $branchFamily = self::regimeFamily($branch['icms_situation'] ?? null);

        return $generalFamily !== null && $branchFamily !== null && $generalFamily !== $branchFamily;
    }

    /**
     * Perfil que a nota usa na filial. sources: campo => 'general' | 'branch' (só campos preenchidos).
     *
     * @return array{data: array<string, mixed>, sources: array<string, string>, standalone: bool, has_branch: bool}
     */
    public static function resolve(?array $general, ?array $branch): array
    {
        $general = $general ?? [];
        if ($branch === null) {
            return [
                'data' => $general,
                'sources' => array_map(fn () => 'general', array_filter(array_intersect_key($general, self::FIELDS), [self::class, 'filled'])),
                'standalone' => false,
                'has_branch' => false,
            ];
        }
        if (self::isStandalone($general, $branch)) {
            return [
                'data' => $branch,
                'sources' => array_map(fn () => 'branch', array_filter(array_intersect_key($branch, self::FIELDS), [self::class, 'filled'])),
                'standalone' => true,
                'has_branch' => true,
            ];
        }

        $data = array_diff_key($general, ['taxes' => true]);
        $sources = [];
        foreach (array_keys(self::FIELDS) as $field) {
            if (self::filled($branch[$field] ?? null)) {
                $data[$field] = $branch[$field];
                $sources[$field] = 'branch';
            } elseif (self::filled($general[$field] ?? null)) {
                $sources[$field] = 'general';
            }
        }

        $branchTaxes = is_array($branch['taxes'] ?? null) ? $branch['taxes'] : [];
        $generalTaxes = is_array($general['taxes'] ?? null) ? $general['taxes'] : [];
        $inherited = array_filter(
            $generalTaxes,
            fn ($value, $key) => ! array_key_exists($key, $branchTaxes) && ! self::taxFollowsBranchSituation((string) $key, $branch),
            ARRAY_FILTER_USE_BOTH,
        );
        $data['taxes'] = array_replace($inherited, $branchTaxes);

        return ['data' => $data, 'sources' => $sources, 'standalone' => false, 'has_branch' => true];
    }

    /**
     * Campos em que a filial é diferente do cadastro geral (o que a filial tem de próprio).
     *
     * @return array<string, mixed> campo => valor da filial
     */
    public static function overrides(?array $general, array $branch): array
    {
        $general = $general ?? [];
        $standalone = self::isStandalone($general, $branch);

        return array_filter(
            array_intersect_key($branch, self::FIELDS),
            fn ($value, $field) => self::filled($value) && ($standalone || ! self::same($value, $general[$field] ?? null)),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Perfil da filial só com o que difere do cadastro geral, para que mudanças no cadastro
     * geral cheguem à filial. Perfil de regime diferente fica inteiro. Null quando não sobra
     * nada próprio (a filial passa a usar só o cadastro geral).
     */
    public static function compact(?array $general, array $branch): ?array
    {
        $general = $general ?? [];
        $taxes = is_array($branch['taxes'] ?? null) ? $branch['taxes'] : [];
        if (self::isStandalone($general, $branch)) {
            $fields = array_filter(array_intersect_key($branch, self::FIELDS), [self::class, 'filled']);
            $fields = array_intersect_key(array_replace(self::FIELDS, $fields), $fields);

            return $fields === [] && $taxes === [] ? null : [...$fields, 'taxes' => $taxes];
        }

        $fields = self::overrides($general, $branch);
        $generalTaxes = is_array($general['taxes'] ?? null) ? $general['taxes'] : [];
        // CST igual ao geral só sai se os campos adicionais daquele imposto também forem iguais:
        // sem o CST na filial, as alíquotas do geral passariam a valer nela.
        foreach (self::TAX_SITUATION as $prefix => $situation) {
            if (! isset($fields[$situation]) && self::filled($branch[$situation] ?? null)
                && self::canonical(self::prefixed($taxes, $prefix)) !== self::canonical(self::prefixed($generalTaxes, $prefix))) {
                $fields[$situation] = $branch[$situation];
            }
        }
        $fields = array_intersect_key(array_replace(self::FIELDS, $fields), $fields);
        // Campo adicional igual ao herdado não precisa ficar na filial.
        $own = array_filter($taxes, fn ($value, $key) => ! array_key_exists($key, $generalTaxes)
            || self::taxFollowsBranchSituation((string) $key, $fields)
            || self::canonical($generalTaxes[$key]) !== self::canonical($value), ARRAY_FILTER_USE_BOTH);

        return $fields === [] && $own === [] ? null : [...$fields, 'taxes' => $own];
    }

    private static function prefixed(array $taxes, string $prefix): array
    {
        return array_filter($taxes, fn ($key) => str_starts_with((string) $key, $prefix), ARRAY_FILTER_USE_KEY);
    }

    private static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            ksort($value);
        }

        return json_encode(is_int($value) || is_float($value) ? (string) $value : $value);
    }

    public static function same(mixed $a, mixed $b): bool
    {
        return self::filled($a) && self::filled($b)
            && mb_strtoupper(trim((string) $a)) === mb_strtoupper(trim((string) $b));
    }

    /** Rótulos dos campos, na ordem da tela. */
    public static function labels(array $fields): array
    {
        return array_values(array_map(fn ($field) => self::FIELDS[$field] ?? $field, array_intersect(array_keys(self::FIELDS), $fields)));
    }

    private static function taxFollowsBranchSituation(string $key, array $branch): bool
    {
        foreach (self::TAX_SITUATION as $prefix => $field) {
            if (str_starts_with($key, $prefix)) {
                return self::filled($branch[$field] ?? null);
            }
        }

        return false;
    }
}
