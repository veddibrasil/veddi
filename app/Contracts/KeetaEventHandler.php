<?php

namespace App\Contracts;

use App\Models\KeetaWebhookEvent;

/** Tratador de um grupo de eventos da Keeta, chamado pelo ProcessKeetaEventJob. */
interface KeetaEventHandler
{
    public function handles(string $type): bool;

    /** Processa o evento; false quando não há nada a fazer com ele (vira "ignored"). */
    public function handle(KeetaWebhookEvent $event): bool;
}
