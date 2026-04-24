<?php

namespace App\Jobs;

use App\Models\WhatsAppSetting;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly string $phone,
        public readonly string $message,
        public readonly int $companyId,
    ) {
        $this->onQueue('whatsapp');
    }

    public function handle(WhatsAppService $service): void
    {
        $settings = WhatsAppSetting::where('company_id', $this->companyId)->first();

        $service->send($this->phone, $this->message, $settings);
    }
}
