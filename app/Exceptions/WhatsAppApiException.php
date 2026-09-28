<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Erro devolvido pela Graph API (ou falha de conexão até ela). getCode() carrega
 * o código de erro da Meta (ex.: 131026), não o status HTTP.
 */
abstract class WhatsAppApiException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
