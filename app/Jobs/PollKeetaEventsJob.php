<?php

namespace App\Jobs;

use App\Exceptions\KeetaApiException;
use App\Exceptions\KeetaRateLimitedException;
use App\Services\Keeta\KeetaOrderPollingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Polling de eventos da Keeta pras lojas sem webhook saudável (agendado a cada 30 s). */
class PollKeetaEventsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 60;

    public function __construct()
    {
        $this->onQueue('critical');
    }

    public function handle(KeetaOrderPollingService $polling): void
    {
        try {
            $polling->poll();
        } catch (KeetaRateLimitedException) {
            // Próxima rodada tenta de novo.
        } catch (Throwable $e) {
            Log::channel('keeta')->warning('Keeta: falha no polling de eventos', ['error' => KeetaApiException::forLog($e)]);
        }
    }
}
