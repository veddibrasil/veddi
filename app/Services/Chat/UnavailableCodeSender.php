<?php

namespace App\Services\Chat;

use App\Contracts\ChatCodeSender;
use RuntimeException;

class UnavailableCodeSender implements ChatCodeSender
{
    public function send(int $companyId, string $phone, string $code, ?string $challengeId = null): void
    {
        throw new RuntimeException('Canal de autenticação indisponível.');
    }
}
