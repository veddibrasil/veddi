<?php

namespace App\Services\Fiscal;

use App\Models\CompanyFiscalConfig;
use App\Models\FiscalNote;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class FiscalDocumentService
{
    /**
     * DANFE da NFC-e na Focus é uma página HTML pública (não PDF) — o painel manda o
     * navegador direto pra ela em vez de baixar e servir pelo nosso domínio, o que
     * levaria os scripts da Focus pra origem do painel. Só o DANFE da NF-e é arquivo.
     */
    public function danfeIsHtml(FiscalNote $note): bool
    {
        return $note->model !== '55';
    }

    /** URL do documento na Focus, só se for do host do ambiente da nota. */
    public function providerUrl(FiscalNote $note, string $type): string
    {
        return $this->source($note, $type)['url'];
    }

    public function store(FiscalNote $note, string $type): string
    {
        if (! in_array($type, ['xml', 'danfe'], true)) {
            throw new \InvalidArgumentException('Tipo de documento inválido.');
        }
        if ($type === 'danfe' && $this->danfeIsHtml($note)) {
            throw new \RuntimeException('O DANFE da NFC-e é uma página do provedor fiscal, não um arquivo.');
        }
        $extension = $type === 'xml' ? 'xml' : 'pdf';
        $path = 'fiscal/'.$note->company_id.'/'.$note->id.'/'.$type.'.'.$extension;
        if (Storage::disk('local')->exists($path)) {
            return $path;
        }
        ['url' => $url, 'config' => $config, 'environment' => $environment] = $this->source($note, $type);
        $token = $config?->tokenFor($environment) ?: config('fiscal.focus_nfe.token');
        if (! $token) {
            throw new \RuntimeException('Token fiscal não configurado.');
        }
        // Redirects are disabled so credentials never follow a document URL to another host.
        $response = Http::withBasicAuth($token, '')->withoutRedirecting()->timeout(30)->get($url)->throw();
        $body = $response->body();
        if (($type === 'danfe' && ! str_starts_with($body, '%PDF-'))
            || ($type === 'xml' && (! str_contains($body, '<') || str_contains(strtolower($body), '<html')))) {
            throw new \RuntimeException('O provedor retornou um documento inválido.');
        }
        if (! Storage::disk('local')->put($path, $body)) {
            throw new \RuntimeException('Não foi possível armazenar o documento fiscal.');
        }

        return $path;
    }

    /**
     * @return array{url: string, config: ?CompanyFiscalConfig, environment: string}
     */
    private function source(FiscalNote $note, string $type): array
    {
        $url = $type === 'xml' ? $note->xml_url : $note->danfe_url;
        $config = app(FiscalConfigResolver::class)->forNote($note);
        $environment = FiscalNoteService::resolveEffectiveEnvironment($note->environment ?: ($config?->environment ?: 'homologacao'));
        $baseUrl = FiscalNoteService::resolveBaseUrl($environment);
        if (! $url || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== parse_url($baseUrl, PHP_URL_HOST)) {
            throw new \RuntimeException('Documento indisponível no provedor fiscal.');
        }

        return ['url' => $url, 'config' => $config, 'environment' => $environment];
    }
}
