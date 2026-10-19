<?php

namespace App\Observers;

use App\Events\FiscalNoteAuthorized;
use App\Events\FiscalNoteFailed;
use App\Models\FiscalNote;

class FiscalNoteObserver
{
    /**
     * Único ponto de disparo do evento de autorização: cobre tanto a autorização
     * síncrona (FiscalNoteService::issue) quanto a assíncrona via webhook da Focus
     * NFe (FiscalWebhookController) — ambas só fazem update() direto no model.
     */
    public function updated(FiscalNote $note): void
    {
        if (! $note->wasChanged('status')) {
            return;
        }

        if ($note->status === 'authorized') {
            FiscalNoteAuthorized::dispatch($note);
        }

        // Rejeição/erro devolvido pela Focus (síncrono ou via webhook) — o job não falha
        // nesse caso, então é aqui que o PDV fica sabendo que não vai ter o que imprimir.
        if (in_array($note->status, ['rejected', 'error'], true) && $note->order_id) {
            FiscalNoteFailed::dispatch($note->order_id, $note->error_message);
        }
    }
}
