<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\FiscalExport;
use App\Models\FiscalNote;
use App\Services\Fiscal\FiscalMonthlyExportService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PrepareFiscalExportDocument implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $exportId, public int $noteId)
    {
        $this->onQueue('low');
    }

    public function handle(FiscalMonthlyExportService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
        $export = FiscalExport::withoutGlobalScopes()->findOrFail($this->exportId);
        if ($export->status !== 'processing' || ! in_array($this->noteId, $export->note_ids, true)) {
            return;
        }
        $previousCompany = app()->bound('current.company') ? app('current.company') : null;
        app()->instance('current.company', Company::findOrFail($export->company_id));
        $result = ['id' => $this->noteId];
        try {
            $note = FiscalNote::where('company_id', $export->company_id)->findOrFail($this->noteId);
            $result += [
                'model' => $note->model_label,
                'status' => $note->status === 'cancelled' ? 'Cancelada' : 'Autorizada',
                'key' => $note->access_key,
                'cnpj' => $note->issuer_cnpj,
                'number' => $note->number,
                'series' => $note->series,
                'issued_at' => $service->issuedAt($note)->toIso8601String(),
            ];
            if (! in_array($note->status, ['authorized', 'cancelled'], true) || $note->environment !== $export->environment
                || ($export->branch_id && ($note->branch_id ?: $note->order?->branch_id) !== $export->branch_id)) {
                throw new \RuntimeException('A nota não corresponde aos filtros do lote.');
            }
            $result = array_merge($result, $service->document($note, $export));
        } catch (\Throwable $e) {
            Log::channel('fiscal')->warning('XML indisponível no lote mensal', ['export_id' => $export->id, 'note_id' => $this->noteId, 'error' => $e->getMessage()]);
            $result['error'] = 'XML indisponível ou inválido. Consulte a nota e gere um novo lote.';
        } finally {
            if ($previousCompany) {
                app()->instance('current.company', $previousCompany);
            } else {
                app()->forgetInstance('current.company');
            }
        }
        if (! Storage::disk('local')->put($export->directory().'/results/'.$this->noteId.'.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))) {
            throw new \RuntimeException('Não foi possível registrar o resultado da exportação.');
        }
        $export->touch();
    }
}
