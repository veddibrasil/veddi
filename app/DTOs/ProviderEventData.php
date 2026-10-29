<?php

namespace App\DTOs;

use App\Enums\EmailEventType;
use Carbon\CarbonImmutable;

/** Evento do webhook do provedor já validado e normalizado. */
readonly class ProviderEventData
{
    public function __construct(
        public string $eventId,
        public EmailEventType $type,
        public ?string $messageId,
        public ?int $recipientId,
        public CarbonImmutable $occurredAt,
        public ?string $detail = null,
    ) {}
}
