<?php

namespace App\Support;

/**
 * Converte o que o operador digitou num campo de valor (dinheiro recebido, desconto, abertura de
 * caixa...) em float. Aceita "12,5", "12.50", "1.234,56", "1,234.56" e "R$ 12,50" — o `(float)
 * str_replace(',', '.')` que existia antes lia "1.234,56" como 1.234.
 *
 * Um ponto isolado com três casas ("1.234") é tratado como decimal, porque é o que `<input
 * type="number">` envia; quem digita milhar precisa usar a vírgula decimal ("1.234,00").
 */
final class MoneyInput
{
    /** Null quando vazio ou não numérico — deixa o chamador decidir entre "exato" e "erro". */
    public static function parse(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $clean = preg_replace('/[^\d,.\-]/', '', trim((string) $value));

        if ($clean === null || $clean === '' || $clean === '-') {
            return null;
        }

        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Os dois presentes: o que vier por último é o separador decimal.
            $clean = $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $clean))
                : str_replace(',', '', $clean);
        } elseif ($lastComma !== false) {
            $clean = substr_count($clean, ',') > 1
                ? str_replace(',', '', $clean)
                : str_replace(',', '.', $clean);
        } elseif (substr_count($clean, '.') > 1) {
            $clean = str_replace('.', '', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    /** Vazio ou inválido vira 0.0 — para somas e comparações onde "sem valor" equivale a zero. */
    public static function toFloat(mixed $value): float
    {
        return self::parse($value) ?? 0.0;
    }
}
