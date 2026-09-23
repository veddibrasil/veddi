<?php

namespace App\Jobs;

use App\Services\Messaging\WhatsAppWebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Processa fora do request o payload já autenticado (assinatura X-Hub-Signature-256) do
 * webhook da WhatsApp Cloud API. O processamento é idempotente, então retry é seguro.
 */
class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    /** @param  array<string, mixed>  $payload */
    public function __construct(public array $payload)
    {
        $this->onQueue('whatsapp');
    }

    public function handle(WhatsAppWebhookProcessor $processor): void
    {
        $processor->process($this->payload);
    }
}
