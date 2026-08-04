<?php

namespace App\Jobs;

use App\Models\FiscalNote;
use App\Services\Fiscal\FiscalNoteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Notas presas em "pending" acontecem quando o webhook da Focus NFe nunca chega
 * (registro falhou silenciosamente, evento perdido) — sem isso a nota fica pending
 * pra sempre e o lojista não tem nenhum jeito de saber se a NFC-e foi autorizada.
 * Roda periodicamente (routes/console.php) e consulta a Focus (GET /v2/nfce/{ref})
 * pra fechar o status manualmente.
 */
class ReconcilePendingFiscalNotesJob implements ShouldQueue
{
    use Queueable;

    public function handle(FiscalNoteService $service): void
    {
        $notes = FiscalNote::withoutGlobalScopes()
            ->with('company.fiscalConfig')
            ->where('status', 'pending')
            ->whereNotNull('provider_reference')
            ->where('created_at', '<=', now()->subMinutes(10))
            ->get();

        foreach ($notes as $note) {
            $service->reconcile($note);
        }
    }
}
