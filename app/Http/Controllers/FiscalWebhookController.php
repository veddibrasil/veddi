<?php

namespace App\Http\Controllers;

use App\Models\FiscalNote;
use App\Services\Fiscal\FiscalNoteService;
use App\Services\Fiscal\FocusNfeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FiscalWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $ref = $data['ref'] ?? null;
        $event = $data['evento'] ?? null;

        // O segredo é por empresa (CompanyFiscalConfig::webhook_token), então a nota
        // precisa ser resolvida antes da checagem de autorização — sem ref ainda não
        // dá pra saber qual empresa validar, então cai no token global como fallback.
        $note = $ref
            ? FiscalNote::withoutGlobalScopes()->with('company.fiscalConfig')->where('provider_reference', $ref)->first()
            : null;

        $token = $request->header('x-focus-nfe-token', '');
        $expected = $note?->company?->fiscalConfig?->resolveWebhookToken() ?: config('fiscal.focus_nfe.webhook_token');

        if (empty($expected) || ! hash_equals((string) $expected, (string) $token)) {
            Log::channel('fiscal')->warning('Focus NFe webhook: token inválido', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        Log::channel('fiscal')->info('Focus NFe webhook recebido', [
            'ref' => $ref,
            'evento' => $event,
        ]);

        if (! $ref) {
            return response()->json(['error' => 'Missing ref'], 422);
        }

        if (! $note) {
            Log::channel('fiscal')->warning('Focus NFe webhook: nota não encontrada para a ref', [
                'ref' => $ref,
            ]);

            return response()->json(['status' => 'ok']);
        }

        $status = match ($data['status'] ?? '') {
            'autorizado' => 'authorized',
            'cancelado' => 'cancelled',
            'erro_autorizacao', 'denegado' => 'rejected',
            default => null,
        };

        if ($status) {
            $configuredEnvironment = $note->company->fiscalConfig?->environment ?: config('fiscal.environment');
            $effectiveEnvironment = FiscalNoteService::resolveEffectiveEnvironment($configuredEnvironment);
            $baseUrl = FiscalNoteService::resolveBaseUrl($effectiveEnvironment);

            $existing = $note->data ?? [];
            $existing['danfe_url'] = FocusNfeService::absoluteUrl($data['caminho_danfe'] ?? null, $baseUrl) ?? $existing['danfe_url'] ?? null;
            $existing['xml_url'] = FocusNfeService::absoluteUrl($data['caminho_xml_nota_fiscal'] ?? null, $baseUrl) ?? $existing['xml_url'] ?? null;
            $existing['error_message'] = $data['mensagem_sefaz'] ?? $existing['error_message'] ?? null;
            $existing['raw_response'] = $data;

            $note->update([
                'status' => $status,
                'access_key' => $data['chave_nfe'] ?? $note->access_key,
                'data' => $existing,
            ]);

            Log::channel('fiscal')->info('Focus NFe webhook: status da nota atualizado', [
                'fiscal_note_id' => $note->id,
                'order_id' => $note->order_id,
                'status' => $status,
            ]);
        } else {
            Log::channel('fiscal')->warning('Focus NFe webhook: status não mapeado', [
                'fiscal_note_id' => $note->id,
                'raw_status' => $data['status'] ?? null,
            ]);
        }

        return response()->json(['status' => 'ok']);
    }
}
