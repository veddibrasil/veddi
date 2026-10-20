<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FiscalNote;
use App\Services\Fiscal\FiscalDocumentService;
use Illuminate\Support\Facades\Storage;

class FiscalDocumentController extends Controller
{
    public function __invoke(int $note, string $type, FiscalDocumentService $documents)
    {
        $company = app('current.company');
        abort_unless(auth()->user()->hasPermission('fiscal.view', $company) || auth()->user()->hasPermission('fiscal.issue', $company), 403);
        $note = FiscalNote::where('company_id', $company->id)->findOrFail($note);
        abort_unless(in_array($type, ['xml', 'danfe'], true), 404);
        $path = $documents->store($note, $type);

        return Storage::disk('local')->download($path, $note->model_label.'-'.$note->id.'.'.($type === 'xml' ? 'xml' : 'pdf'));
    }
}
