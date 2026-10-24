<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Cartão recusado pelo Asaas na cobrança ou na criação da assinatura. Quando sai daqui,
 * nada ficou cobrado nem pendurado no Asaas: quem lança já desfez os passos anteriores.
 */
class CardPaymentDeclinedException extends RuntimeException
{
    public function __construct(public readonly ?string $reason = null, ?Throwable $previous = null)
    {
        parent::__construct('Cartão recusado'.($reason ? ": {$reason}" : ''), 0, $previous);
    }

    public static function fromCharge(array $charge): self
    {
        return new self($charge['creditCard']['declineReason'] ?? $charge['failReason'] ?? null);
    }
}
