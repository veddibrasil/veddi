<?php

namespace App\DTOs;

/** E-mail marketing pronto para o provedor: HTML final, cabeçalhos e tags de rastreio. */
readonly class RenderedEmailData
{
    /**
     * @param  array<string, string>  $headers  ex.: List-Unsubscribe, List-Unsubscribe-Post
     * @param  array<string, string>  $tags  ex.: campaign_id, recipient_id (voltam no webhook)
     */
    public function __construct(
        public string $toEmail,
        public ?string $toName,
        public string $fromEmail,
        public string $fromName,
        public ?string $replyTo,
        public string $subject,
        public string $html,
        public string $text,
        public array $headers = [],
        public array $tags = [],
        public ?string $idempotencyKey = null,
    ) {}
}
