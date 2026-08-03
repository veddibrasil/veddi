<?php

namespace App\Observers;

use App\Events\FiscalNoteAuthorized;
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
        if ($note->wasChanged('status') && $note->status === 'authorized') {
            FiscalNoteAuthorized::dispatch($note);
        }
    }
}
