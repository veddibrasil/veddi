<?php

namespace App\Services\Chat;

use App\Contracts\ChatCodeSender;
use App\Exceptions\WhatsAppAuthenticationNotReady;
use App\Models\Company;
use RuntimeException;

class WhatsAppCodeSender implements ChatCodeSender
{
    /** Configuration only: rendering the chat must not contact a provider. */
    public static function isEnabled(): bool
    {
        return config('chat_security.whatsapp_auth_enabled')
            && (config('chat_security.whatsapp_meta_enabled') || WuzapiCodeSender::isConfigured());
    }

    public function send(int $companyId, #[\SensitiveParameter] string $phone, #[\SensitiveParameter] string $code, ?string $challengeId = null): void
    {
        if (! self::isEnabled()) {
            throw new RuntimeException('Autenticação pelo WhatsApp ainda não habilitada.');
        }
        // Authentication must preserve the exact phone and the six-digit code, including zeros.
        if (! preg_match('/^\d{10,11}$/', $phone) || ! preg_match('/^\d{6}$/', $code)) {
            throw new RuntimeException('Dados de autenticação inválidos.');
        }
        Company::findOrFail($companyId);

        try {
            app(MetaWhatsAppCodeSender::class)->send($companyId, $phone, $code, $challengeId);
        } catch (WhatsAppAuthenticationNotReady $e) {
            // Never catch send failures/timeouts here: Meta may already have accepted the code.
            if (! WuzapiCodeSender::isConfigured()) {
                throw $e;
            }
            app(WuzapiCodeSender::class)->send($companyId, $phone, $code, $challengeId);
        }
    }
}
