<?php

namespace App\Jobs;

use App\Models\FiscalNote;
use App\Services\Fiscal\FiscalNoteService;
use App\Services\Fiscal\FocusNfeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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

    public function handle(): void
    {
        $notes = FiscalNote::withoutGlobalScopes()
            ->with('company.fiscalConfig')
            ->where('status', 'pending')
            ->whereNotNull('provider_reference')
            ->where('created_at', '<=', now()->subMinutes(10))
            ->get();

        foreach ($notes as $note) {
            $this->reconcile($note);
        }
    }

    private function reconcile(FiscalNote $note): void
    {
        $companyConfig = $note->company?->fiscalConfig;
        $environment = $companyConfig?->environment ?: config('fiscal.environment');
        $effectiveEnvironment = FiscalNoteService::resolveEffectiveEnvironment($environment);
        $token = $companyConfig?->tokenFor($effectiveEnvironment) ?: config('fiscal.focus_nfe.token');

        if (! $token) {
            return;
        }

        $provider = new FocusNfeService($token, FiscalNoteService::resolveBaseUrl($effectiveEnvironment));
        $result = $provider->query($note->provider_reference);

        if ($result->status === 'pending' || $result->status === 'error') {
            Log::channel('fiscal')->debug('ReconcilePendingFiscalNotes: nota segue pendente ou consulta falhou', [
                'fiscal_note_id' => $note->id,
                'status' => $result->status,
            ]);

            return;
        }

        $data = $note->data ?? [];
        $data['xml_url'] = $result->xmlUrl ?? $data['xml_url'] ?? null;
        $data['danfe_url'] = $result->danfeUrl ?? $data['danfe_url'] ?? null;
        $data['reconciled_at'] = now()->toIso8601String();

        $note->update([
            'status' => $result->status,
            'access_key' => $result->accessKey ?? $note->access_key,
            'data' => $data,
        ]);

        Log::channel('fiscal')->info('ReconcilePendingFiscalNotes: status atualizado via consulta manual', [
            'fiscal_note_id' => $note->id,
            'status' => $result->status,
        ]);
    }
}
