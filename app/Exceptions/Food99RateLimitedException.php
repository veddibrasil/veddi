<?php

namespace App\Exceptions;

/**
 * Limite de frequência da 99Food (errno 10005/12021) ou o nosso, que segura a chamada antes
 * de gastar a cota. Jobs fazem release() com backoff em vez de falhar.
 */
class Food99RateLimitedException extends Food99ApiException
{
    public function __construct(
        string $message,
        int $errno = 10005,
        ?string $errmsg = null,
        ?string $requestId = null,
        string $operation = '',
        public readonly int $retryAfter = 60,
    ) {
        parent::__construct($message, $errno, $errmsg, $requestId, $operation);
    }
}
