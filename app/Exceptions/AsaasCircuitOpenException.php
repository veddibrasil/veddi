<?php

namespace App\Exceptions;

use RuntimeException;

class AsaasCircuitOpenException extends RuntimeException
{
    public function __construct(string $message = 'Asaas circuit está aberto — requisição bloqueada')
    {
        parent::__construct($message);
    }
}
