<?php

namespace App\Contracts;

use App\Models\Food99WebhookEvent;

/** Tratador de um grupo de callbacks da 99Food, chamado pelo ProcessFood99EventJob. */
interface Food99EventHandler
{
    public function handles(string $type): bool;

    /** Processa o evento; false quando não há nada a fazer com ele (vira "ignored"). */
    public function handle(Food99WebhookEvent $event): bool;
}
