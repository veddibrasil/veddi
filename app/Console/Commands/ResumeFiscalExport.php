<?php

namespace App\Console\Commands;

use App\Jobs\BuildFiscalMonthlyArchive;
use App\Jobs\PrepareFiscalExportDocument;
use App\Models\FiscalExport;
use App\Services\Fiscal\FiscalMonthlyExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ResumeFiscalExport extends Command
{
    protected $signature = 'fiscal:resume-export {export : ID do lote mensal interrompido}';

    protected $description = 'Retoma um lote mensal interrompido, aproveitando XMLs já preparados, sem depender do worker';

    public function handle(FiscalMonthlyExportService $service): int
    {
        $export = FiscalExport::withoutGlobalScopes()->find($this->argument('export'));
        if (! $export) {
            $this->error('Lote não encontrado.');

            return self::FAILURE;
        }
        if (in_array($export->status, ['ready', 'partial'], true)) {
            $this->info('Este lote já foi concluído.');

            return self::SUCCESS;
        }
        $lock = Cache::lock('fiscal-export:resume:'.$export->id, 3600);
        if (! $lock->get()) {
            $this->error('Este lote já está sendo retomado.');

            return self::FAILURE;
        }
        try {
            // Jobs perdidos antes de atualizar o batch deixam pending_jobs preso para sempre.
            $batchIds = DB::connection(config('queue.batching.database'))
                ->table(config('queue.batching.table', 'job_batches'))
                ->where('name', 'Lote mensal de XML #'.$export->id)
                ->whereNull('finished_at')->whereNull('cancelled_at')->pluck('id');
            foreach ($batchIds as $batchId) {
                Bus::findBatch($batchId)?->cancel();
            }
            $export->update(['status' => 'processing']);
            foreach ($export->note_ids as $noteId) {
                if (! Storage::disk('local')->exists($export->directory().'/results/'.$noteId.'.json')) {
                    (new PrepareFiscalExportDocument($export->id, $noteId))->handle($service);
                }
            }
            (new BuildFiscalMonthlyArchive($export->id))->handle($service);
            $export->refresh();
            $this->info("Lote #{$export->id} concluído: {$export->documents_count} XML(s), {$export->errors_count} pendência(s).");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $export->update(['status' => 'failed']);
            report($e);
            $this->error('Não foi possível retomar o lote. Consulte o log da aplicação.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
