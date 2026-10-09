<?php

namespace App\Exceptions;

use RuntimeException;

class IfoodMappingException extends RuntimeException
{
    /** $userMessage vai pro aviso do restaurante; $message (com ids) fica só no log. */
    public function __construct(string $message, private readonly ?string $userMessage = null)
    {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return $this->userMessage ?? 'Um item do pedido não está ligado a nenhum produto desta filial.';
    }
}
