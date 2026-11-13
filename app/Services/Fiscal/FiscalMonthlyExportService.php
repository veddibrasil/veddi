<?php

namespace App\Services\Fiscal;

use App\Models\FiscalExport;
use App\Models\FiscalNote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class FiscalMonthlyExportService
{
    /** A competência acompanha a emissão enviada ao provedor, nunca a data da venda. */
    public function issuedAt(FiscalNote $note): CarbonImmutable
    {
        return CarbonImmutable::parse($note->data['request_payload']['data_emissao']
            ?? $note->data['raw_response']['data_emissao']
            ?? $note->created_at)->setTimezone(config('app.timezone'));
    }

    public function noteIds(int $companyId, string $month, string $environment, ?int $branchId): array
    {
        return FiscalNote::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereIn('status', ['authorized', 'cancelled'])
            // Notas legadas sem ambiente aparecem como pendência, sem presumir produção.
            ->where(fn ($q) => $q->where('environment', $environment)->orWhereNull('environment'))
            ->when($branchId, fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $branchId)
                ->orWhere(fn ($q) => $q->whereNull('branch_id')->whereHas('order', fn ($q) => $q->withoutGlobalScopes()->where('company_id', $companyId)->where('branch_id', $branchId)))))
            ->lazyById(200)->filter(fn ($note) => $this->issuedAt($note)->format('Y-m') === $month)
            ->pluck('id')->all();
    }

    /** Valida também arquivos já arquivados; nunca inclui HTML/XML malformado no lote. */
    public function document(FiscalNote $note, FiscalExport $export): array
    {
        $path = app(FiscalDocumentService::class)->store($note, 'xml');
        $body = Storage::disk('local')->get($path);
        if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            throw new RuntimeException('XML contém declaração externa não permitida.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
            if (! $xml || $xml->getName() !== 'nfeProc') {
                throw new RuntimeException('XML processado da nota fiscal inválido.');
            }
            $xml->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
            $info = ($xml->xpath('/n:nfeProc/n:NFe/n:infNFe') ?: [])[0] ?? null;
            $protocol = ($xml->xpath('/n:nfeProc/n:protNFe/n:infProt') ?: [])[0] ?? null;
            if (! $info || ! $protocol) {
                throw new RuntimeException('XML sem nota ou protocolo de autorização.');
            }
            $info = $info->children('http://www.portalfiscal.inf.br/nfe');
            $protocol = $protocol->children('http://www.portalfiscal.inf.br/nfe');
            $key = (string) $protocol->chNFe;
            $cnpj = (string) $info->emit->CNPJ;
            $id = (string) (($xml->xpath('/n:nfeProc/n:NFe/n:infNFe/@Id') ?: [])[0] ?? '');
            if (! preg_match('/^\d{44}$/', $key) || $id !== 'NFe'.$key
                || ! preg_match('/^\d{14}$/', $cnpj) || substr($key, 6, 14) !== $cnpj
                || substr($key, 20, 2) !== $note->model
                || ($note->access_key && preg_replace('/^NFe/', '', $note->access_key) !== $key)
                || ! in_array((string) $protocol->cStat, ['100', '150'], true)
                || (string) $info->ide->mod !== $note->model
                || (string) $info->ide->tpAmb !== ($export->environment === 'producao' ? '1' : '2')
                || ($note->issuer_cnpj && $cnpj !== $note->issuer_cnpj)) {
                throw new RuntimeException('XML diverge da nota, do emitente, do ambiente ou da autorização.');
            }
            $date = (string) ($info->ide->dhEmi ?: $info->ide->dEmi);
            if (! $date || CarbonImmutable::parse($date)->setTimezone(config('app.timezone'))->format('Y-m') !== $export->month) {
                throw new RuntimeException('Data de emissão do XML fora da competência selecionada.');
            }

            return [
                'path' => $path,
                'filename' => 'xml/'.$info->emit->CNPJ.'/'.$note->model.'/'.$note->status.'/'.$key.'-'.$note->id.'.xml',
                'key' => $key,
                'number' => (string) $info->ide->nNF,
                'series' => (string) $info->ide->serie,
                'cnpj' => (string) $info->emit->CNPJ,
                'issued_at' => $date,
                'total' => (string) $info->total->ICMSTot->vNF,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function finish(FiscalExport $export): void
    {
        $disk = Storage::disk('local');
        $path = $export->directory().'/'.$export->filename();
        $disk->makeDirectory($export->directory());
        $temporary = $disk->path($path.'.tmp');
        $csv = fopen('php://temp/maxmemory:2097152', 'w+');
        $zip = new ZipArchive;
        $open = false;
        try {
            if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Não foi possível criar o lote fiscal.');
            }
            $open = true;
            fwrite($csv, "\xEF\xBB\xBF");
            $this->csv($csv, ['Nota', 'Modelo', 'Situação', 'CNPJ emitente', 'Número', 'Série', 'Chave de acesso', 'Emissão', 'Valor da nota', 'Arquivo XML', 'Pendência']);
            $count = $errors = 0;
            foreach ($export->note_ids as $id) {
                $resultPath = $export->directory().'/results/'.$id.'.json';
                $result = $disk->exists($resultPath) ? json_decode($disk->get($resultPath), true, 512, JSON_THROW_ON_ERROR) : ['id' => $id, 'error' => 'Processamento do XML não concluído.'];
                $error = $result['error'] ?? '';
                if (! $error) {
                    if (! $disk->exists($result['path']) || ! $zip->addFile($disk->path($result['path']), $result['filename'])) {
                        $error = 'XML arquivado indisponível.';
                    } else {
                        $count++;
                    }
                }
                if ($error) {
                    $errors++;
                }
                $this->csv($csv, [$id, $result['model'] ?? '', $result['status'] ?? '', $result['cnpj'] ?? '', $result['number'] ?? '', $result['series'] ?? '', $result['key'] ?? '', $result['issued_at'] ?? '', $result['total'] ?? '', $error ? '' : $result['filename'], $error]);
            }
            rewind($csv);
            if (! $zip->addFromString('relatorio.csv', stream_get_contents($csv))
                || ! $zip->addFromString('LEIA-ME.txt', "Competência: {$export->month}\nAmbiente: {$export->environment}\nNotas selecionadas: ".count($export->note_ids)."\nXMLs incluídos: {$count}\nPendências: {$errors}\n\nO relatório identifica autorizadas e canceladas. Valores são os valores das notas, não um total de vendas líquidas.\nEste lote contém XMLs das notas; XMLs de eventos (cancelamento, carta de correção e inutilização) devem ser obtidos separadamente no provedor.\nConfira as pendências antes de enviar ao escritório. Notas sem ambiente registrado são sinalizadas como pendência.\n")) {
                throw new RuntimeException('Não foi possível gravar o relatório do lote.');
            }
            if (! $zip->close()) {
                throw new RuntimeException('Não foi possível concluir o lote fiscal.');
            }
            $open = false;
            if (! $disk->move($path.'.tmp', $path)) {
                throw new RuntimeException('Não foi possível armazenar o lote fiscal.');
            }
            $export->update(['status' => $errors ? 'partial' : 'ready', 'documents_count' => $count, 'errors_count' => $errors, 'path' => $path]);
        } finally {
            if ($open) {
                $zip->close();
            }
            fclose($csv);
            $disk->delete($path.'.tmp');
        }
    }

    private function csv($stream, array $values): void
    {
        // Evita execução de fórmulas ao abrir o relatório em planilhas.
        $values = array_map(fn ($value) => preg_match('/^[\s]*[=+@\-]/u', (string) $value) ? "'".$value : $value, $values);
        if (fputcsv($stream, $values, ';', '"', '') === false) {
            throw new RuntimeException('Não foi possível gravar o relatório fiscal.');
        }
    }
}
