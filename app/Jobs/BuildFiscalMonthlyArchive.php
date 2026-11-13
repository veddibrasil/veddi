<?php

namespace App\Jobs;

use App\Models\FiscalExport;
use App\Services\Fiscal\FiscalMonthlyExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class BuildFiscalMonthlyArchive implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $exportId)
    {
        $this->onQueue('low');
    }

    public function handle(FiscalMonthlyExportService $service): void
    {
        $export = FiscalExport::withoutGlobalScopes()->findOrFail($this->exportId);
        if ($export->status === 'processing') {
            $service->finish($export);
        }
    }

    public function failed(?Throwable $exception): void
    {
        FiscalExport::withoutGlobalScopes()->whereKey($this->exportId)->where('status', 'processing')->update(['status' => 'failed']);
    }
}
