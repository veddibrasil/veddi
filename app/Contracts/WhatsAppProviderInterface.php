<?php

namespace App\Contracts;

use App\DTOs\WhatsAppSender;
use App\Exceptions\WhatsAppApiException;

interface WhatsAppProviderInterface
{
    /**
     * Envia uma mensagem de template e retorna o wamid (ID da mensagem na Meta).
     *
     * @param  array<int, string>  $bodyParams  Valores de {{1}}, {{2}}, ... na ordem.
     *
     * @throws WhatsAppApiException
     */
    public function sendTemplate(
        WhatsAppSender $sender,
        string $to,
        string $template,
        array $bodyParams,
        string $lang = 'pt_BR',
    ): string;
}
