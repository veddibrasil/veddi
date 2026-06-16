<?php

namespace App\Enums;

enum CardBrand: string
{
    case Visa = 'visa';
    case Mastercard = 'mastercard';
    case Amex = 'amex';
    case Elo = 'elo';
    case Hipercard = 'hipercard';
    case Diners = 'diners';

    /** Yapay payment_method_id para cada bandeira */
    public function methodId(): string
    {
        return match ($this) {
            self::Visa => '3',
            self::Mastercard => '4',
            self::Amex => '5',
            self::Elo => '16',
            self::Hipercard => '20',
            self::Diners => '6',
        };
    }

    /**
     * Taxa fixa por bandeira.
     * Fonte: proposta Vindi CNPJ 66.539.173/0001-90.
     */
    public function rate(): float
    {
        return match ($this) {
            self::Visa => 0.0100,
            self::Mastercard => 0.0280,
            self::Elo => 0.0448,
            self::Amex => 0.0188,
            self::Hipercard => 0.0533,
            self::Diners => 0.0280,
        };
    }

    /** Detecta bandeira pelo número do cartão (BINs brasileiros). */
    public static function fromNumber(string $number): self
    {
        $n = preg_replace('/\D/', '', $number);

        // Elo ANTES de Visa — vários BINs Elo começam com 4xxx / 5xxx
        if (preg_match('/^(4011|4312|4389|4514|4576|5041|5066|5067|509|6277|6362|6504|6505|6516|6550)/', $n)) {
            return self::Elo;
        }
        if (preg_match('/^4/', $n)) {
            return self::Visa;
        }
        if (preg_match('/^5[1-5]|^2[2-7]/', $n)) {
            return self::Mastercard;
        }
        if (preg_match('/^3[47]/', $n)) {
            return self::Amex;
        }
        if (preg_match('/^(3841|6062|60)/', $n)) {
            return self::Hipercard;
        }
        if (preg_match('/^3[0689]/', $n)) {
            return self::Diners;
        }

        return self::Mastercard;
    }
}
