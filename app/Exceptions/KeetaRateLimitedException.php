<?php

namespace App\Exceptions;

/**
 * Limite de frequência da Keeta (HTTP 429) ou o nosso, que segura a chamada antes de gastar a
 * cota (polling: 2 por minuto por token). Jobs fazem release() com backoff em vez de falhar.
 */
class KeetaRateLimitedException extends KeetaApiException
{
    public function __construct(
        string $message,
        int $httpStatus = 429,
        ?string $title = null,
        string $operation = '',
        public readonly int $retryAfter = 30,
    ) {
        parent::__construct($message, $httpStatus, $title, $operation);
    }
}
