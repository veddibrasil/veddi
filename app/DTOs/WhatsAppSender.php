<?php

namespace App\DTOs;

use App\Models\WhatsAppConnection;

/**
 * Remetente de uma mensagem: par número + token. connectionId nulo = número da
 * plataforma (config), que não tem registro em whatsapp_connections.
 */
readonly class WhatsAppSender
{
    public function __construct(
        public string $phoneNumberId,
        #[\SensitiveParameter]
        public string $accessToken,
        public ?int $connectionId = null,
    ) {}

    public static function fromConnection(WhatsAppConnection $connection): self
    {
        return new self(
            phoneNumberId: (string) $connection->phone_number_id,
            accessToken: (string) $connection->access_token,
            connectionId: $connection->id,
        );
    }

    /** Número da plataforma (homologação/fallback); null se o config estiver incompleto. */
    public static function platform(): ?self
    {
        $phoneNumberId = config('services.whatsapp.platform.phone_number_id');
        $token = config('services.whatsapp.platform.token');

        if (blank($phoneNumberId) || blank($token)) {
            return null;
        }

        return new self((string) $phoneNumberId, (string) $token);
    }

    public function isPlatform(): bool
    {
        return $this->connectionId === null;
    }

    /** Evita vazar o token em dumps/logs acidentais. */
    public function __debugInfo(): array
    {
        return [
            'phoneNumberId' => $this->phoneNumberId,
            'accessToken' => '***',
            'connectionId' => $this->connectionId,
        ];
    }
}
