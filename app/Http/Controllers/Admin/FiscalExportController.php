<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FiscalExport;
use Illuminate\Support\Facades\Storage;

class FiscalExportController extends Controller
{
    public function __invoke(int $export)
    {
        $company = app('current.company');
        abort_unless($company->fiscal_notes_enabled && auth()->user()->hasPermission('fiscal.view', $company), 403);
        $export = FiscalExport::where('company_id', $company->id)->findOrFail($export);
        abort_unless(auth()->user()->canAccessBranch($company, $export->branch_id), 403);
        abort_unless(in_array($export->status, ['ready', 'partial'], true) && $export->path && Storage::disk('local')->exists($export->path), 404);

        return Storage::disk('local')->download($export->path, $export->filename(), [
            'Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
