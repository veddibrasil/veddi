<?php

namespace App\Services\Marketing;

/**
 * Troca {{variavel}} por um token alfanumérico antes de qualquer passagem pelo DOMDocument
 * (que codificaria as chaves dentro de href) e desfaz depois.
 */
final class Placeholders
{
    private const PATTERN = '/\{\{\s*([a-z_]+)\s*\}\}/';

    private const TOKEN = '/VDDVAR_([a-z_]+?)_VDDVAR/';

    public static function protect(string $html): string
    {
        return preg_replace(self::PATTERN, 'VDDVAR_$1_VDDVAR', $html) ?? $html;
    }

    public static function restore(string $html): string
    {
        return preg_replace(self::TOKEN, '{{$1}}', $html) ?? $html;
    }

    public static function isProtected(string $value): bool
    {
        return (bool) preg_match('/^VDDVAR_[a-z_]+?_VDDVAR$/', $value);
    }

    /** @return list<string> nomes das variáveis usadas no texto */
    public static function names(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Substitui só as variáveis da lista fechada. Nada aqui passa pelo Blade: "{{ $x }}" ou
     * "{{ phpinfo() }}" continuam texto inerte.
     *
     * @param  array<string, string>  $values
     */
    public static function replace(string $text, array $values, bool $escape = true): string
    {
        return preg_replace_callback(self::PATTERN, function (array $match) use ($values, $escape) {
            if (! array_key_exists($match[1], $values)) {
                return $match[0];
            }

            $value = (string) $values[$match[1]];

            return $escape ? e($value) : $value;
        }, $text) ?? $text;
    }
}
