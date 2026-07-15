<?php

namespace App\Exceptions;

use RuntimeException;

class FocusNfeCompanyRegistrationException extends RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $errors
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $errors = [],
        public readonly array $rawResponse = [],
    ) {
        parent::__construct($message);
    }
}
