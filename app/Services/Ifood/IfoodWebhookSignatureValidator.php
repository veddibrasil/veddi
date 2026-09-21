<?php

namespace App\Services\Ifood;

class IfoodWebhookSignatureValidator
{
    /**
     * HMAC-SHA256 do corpo bruto da request, assinado com o client_secret da
     * aplicação parceira (platform-wide — modelo hub, não mais por integração).
     * Comparação constant-time (hash_equals) — homologação do iFood testa
     * ativamente o envio de assinaturas inválidas.
     */
    public function isValid(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = config('ifood.partner_client_secret');

        if (! $signatureHeader || ! is_string($secret) || $secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
