<?php

namespace App\Jobs;

use App\Models\FiscalNote;
use App\Services\Fiscal\FiscalDocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ArchiveFiscalDocuments implements ShouldQueue
{
    use Queueable;

    public function handle(FiscalDocumentService $documents): void
    {
        FiscalNote::withoutGlobalScopes()->whereIn('status', ['authorized', 'cancelled'])
            ->whereNull('data->archived_at')->chunkById(100, function ($notes) use ($documents) {
                foreach ($notes as $note) {
                    try {
                        // DANFE da NFC-e é página HTML da Focus, não arquivo — só o XML é guardado.
                        $archivesDanfe = ! $documents->danfeIsHtml($note);
                        if (! $note->xml_url || ($archivesDanfe && ! $note->danfe_url)) {
                            continue;
                        }
                        $documents->store($note, 'xml');
                        if ($archivesDanfe) {
                            $documents->store($note, 'danfe');
                        }
                        // JSON path update avoids overwriting a concurrent webhook's data.
                        FiscalNote::withoutGlobalScopes()->whereKey($note->id)->update(['data->archived_at' => now()->toIso8601String()]);
                    } catch (\Throwable $e) {
                        Log::channel('fiscal')->warning('Não foi possível arquivar documentos fiscais', ['fiscal_note_id' => $note->id, 'error' => $e->getMessage()]);
                    }
                }
            });
    }
}
