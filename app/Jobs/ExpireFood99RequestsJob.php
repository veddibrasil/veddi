<?php

namespace App\Jobs;

use App\Services\Food99\Food99RequestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Marca como expiradas as solicitações do cliente que passaram do prazo sem resposta da loja. */
class ExpireFood99RequestsJob implements ShouldQueue
{
    use Queueable;

    public function handle(Food99RequestService $requests): void
    {
        $requests->expireOverdue();
    }
}
