<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FiscalNote;
use App\Services\Fiscal\FiscalDocumentService;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FiscalDocumentController extends Controller
{
    public function __invoke(Request $request, int $note, string $type, FiscalDocumentService $documents)
    {
        $company = app('current.company');
        abort_unless(auth()->user()->hasPermission('fiscal.view', $company) || auth()->user()->hasPermission('fiscal.issue', $company), 403);
        $note = FiscalNote::where('company_id', $company->id)->findOrFail($note);
        abort_unless(in_array($type, ['xml', 'danfe'], true), 404);

        try {
            if ($type === 'danfe' && $documents->danfeIsHtml($note)) {
                return redirect()->away($documents->providerUrl($note, 'danfe'));
            }

            $path = $documents->store($note, $type);
        } catch (\RuntimeException|HttpClientException $e) {
            Log::channel('fiscal')->warning('Documento fiscal indisponível', [
                'fiscal_note_id' => $note->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Não foi possível abrir o '.($type === 'xml' ? 'XML' : 'DANFE').' desta nota agora. Tente novamente em instantes.');
        }

        $filename = $note->model_label.'-'.$note->id.'.'.($type === 'xml' ? 'xml' : 'pdf');

        // ?inline=1 vem do botão "Imprimir": abre o PDF no navegador em vez de baixar.
        return $request->boolean('inline')
            ? Storage::disk('local')->response($path, $filename)
            : Storage::disk('local')->download($path, $filename);
    }
}
