<?php

namespace App\Services\Messaging;

use App\Exceptions\WhatsAppAuthException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Erros do WhatsApp que a equipe da plataforma precisa ver na hora (canal discord, o mesmo dos
 * pagamentos). Só ids e códigos: nunca token, PIN, telefone ou texto de cliente.
 */
final class WhatsAppCriticalLog
{
    /**
     * A Meta recusou o token de uma conexão (revogado, expirado ou app secret errado).
     * $origin diz de onde veio: envio, provisionamento ou onboarding.
     */
    public static function authRefused(string $origin, ?int $connectionId, ?int $companyId, WhatsAppAuthException $exception): void
    {
        Log::channel('discord')->error('WhatsApp: token recusado pela Meta', [
            'origin' => $origin,
            'company_id' => $companyId,
            'connection_id' => $connectionId,
            'meta_code' => $exception->getCode(),
            'http_status' => $exception->httpStatus,
            'meta_message' => Str::limit(self::scrub($exception->getMessage()), 300),
        ]);
    }

    /** Redige parâmetros sensíveis (token, secret, code, pin, etc.) de dentro de mensagens de erro. */
    public static function scrub(string $message): string
    {
        return preg_replace(
            '/\b(access_token|client_secret|code|input_token|appsecret_proof|fb_exchange_token|pin)=[^&\s"\']+/i',
            '$1=***',
            $message,
        ) ?? '';
    }

    /** A Meta desativou (baniu) a conta do WhatsApp Business. $connectionId nulo = WABA da própria plataforma. */
    public static function accountBanned(?int $connectionId, ?int $companyId, string $wabaId): void
    {
        Log::channel('discord')->critical($connectionId === null
            ? 'WhatsApp: a Meta desativou a conta da PLATAFORMA (banimento)'
            : 'WhatsApp: a Meta desativou a conta de um restaurante (banimento)', [
                'company_id' => $companyId,
                'connection_id' => $connectionId,
                'waba_id' => $wabaId,
            ]);
    }
}
