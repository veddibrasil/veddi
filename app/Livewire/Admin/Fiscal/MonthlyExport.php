<?php

namespace App\Livewire\Admin\Fiscal;

use App\Jobs\BuildFiscalMonthlyArchive;
use App\Jobs\PrepareFiscalExportDocument;
use App\Models\Branch;
use App\Models\FiscalExport;
use App\Services\Fiscal\FiscalMonthlyExportService;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\Rule;
use Livewire\Component;

class MonthlyExport extends Component
{
    public string $month = '';

    public string $environment = 'producao';

    public string $branchId = '';

    public function mount(): void
    {
        $this->authorizeExport();
        $this->month = now()->subMonthNoOverflow()->format('Y-m');
        $this->branchId = (string) (auth()->user()->lockedBranchId(app('current.company')) ?? '');
    }

    private function authorizeExport(): void
    {
        $company = app('current.company');
        abort_unless($company->fiscal_notes_enabled && auth()->user()->hasPermission('fiscal.view', $company), 403);
    }

    public function generate(): void
    {
        $this->authorizeExport();
        $company = app('current.company');
        $this->validate([
            'month' => ['required', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')],
            'environment' => ['required', Rule::in(['producao', 'homologacao'])],
            'branchId' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', $company->id)],
        ]);
        $branchId = $this->branchId !== '' ? (int) $this->branchId : null;
        $lockedBranchId = auth()->user()->lockedBranchId($company);
        abort_if($lockedBranchId !== null && $branchId !== $lockedBranchId, 403);
        $ids = app(FiscalMonthlyExportService::class)->noteIds($company->id, $this->month, $this->environment, $branchId);
        if (! $ids) {
            $this->addError('month', 'Nenhuma nota autorizada ou cancelada encontrada nesta competência, filial e ambiente.');

            return;
        }
        $export = FiscalExport::create([
            'company_id' => $company->id, 'branch_id' => $branchId, 'user_id' => auth()->id(),
            'month' => $this->month, 'environment' => $this->environment, 'note_ids' => $ids,
        ]);
        $exportId = $export->id;
        try {
            Bus::batch(array_map(fn ($id) => new PrepareFiscalExportDocument($exportId, $id), $ids))
                ->name('Lote mensal de XML #'.$exportId)->onQueue('low')->allowFailures()
                ->finally(static function (Batch $batch) use ($exportId) {
                    if (! $batch->cancelled()) {
                        BuildFiscalMonthlyArchive::dispatch($exportId);
                    }
                })->dispatch();
        } catch (\Throwable $e) {
            $export->update(['status' => 'failed']);
            report($e);
            $this->addError('month', 'Não foi possível iniciar o lote. Tente novamente.');
        }
    }

    public function render()
    {
        $this->authorizeExport();
        $company = app('current.company');
        $lockedBranchId = auth()->user()->lockedBranchId($company);

        return view('livewire.admin.fiscal.monthly-export', [
            'branches' => Branch::where('company_id', $company->id)->when($lockedBranchId, fn ($q) => $q->whereKey($lockedBranchId))->orderBy('name')->get(),
            'lockedBranchId' => $lockedBranchId,
            'exports' => FiscalExport::where('company_id', $company->id)->when($lockedBranchId, fn ($q) => $q->where('branch_id', $lockedBranchId))->latest('id')->limit(5)->get(),
        ]);
    }
}
