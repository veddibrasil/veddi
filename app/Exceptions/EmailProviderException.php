<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/** Falha do provedor de e-mail marketing. permanent=true: endereço/payload recusado, sem retry. */
class EmailProviderException extends RuntimeException
{
    /**
     * @param  bool  $accountLevel  problema da conta (chave inválida, domínio não verificado): vale para
     *                              todos os destinatários, então pausa a campanha em vez de falhar cada um
     */
    public function __construct(
        string $message,
        public readonly bool $permanent,
        int $code = 0,
        ?Throwable $previous = null,
        public readonly bool $accountLevel = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function account(string $message, int $code = 0, ?Throwable $previous = null): self
    {
        return new self($message, false, $code, $previous, accountLevel: true);
    }

    public static function permanent(string $message, int $code = 0, ?Throwable $previous = null): self
    {
        return new self($message, true, $code, $previous);
    }

    public static function temporary(string $message, int $code = 0, ?Throwable $previous = null): self
    {
        return new self($message, false, $code, $previous);
    }
}
