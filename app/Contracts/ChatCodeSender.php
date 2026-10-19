<?php

namespace App\Contracts;

interface ChatCodeSender
{
    /** Must use an authentication channel; never log destination or code. */
    public function send(int $companyId, string $phone, string $code): void;
}
