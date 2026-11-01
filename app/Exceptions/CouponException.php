<?php

namespace App\Exceptions;

class CouponException extends OrderPlacementException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
