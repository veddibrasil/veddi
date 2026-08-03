<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalNoteProviderInterface;
use App\DTOs\FiscalNoteDTO;
use App\DTOs\FiscalNoteResult;
use App\Exceptions\FocusNfeCompanyRegistrationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FocusNfeService implements FiscalNoteProviderInterface
{
    private string $baseUrl;

    private string $token;

    public function __construct(string $token, string $baseUrl)
    {
        $this->token = $token;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function issue(FiscalNoteDTO $dto): FiscalNoteResult
    {
        $payload = $this->buildPayload($dto);
        $ref = 'order_'.$dto->orderId.'_'.time();

        try {
            $response = Http::withBasicAuth($this->token, '')
                ->post("{$this->baseUrl}/v2/nfce?ref={$ref}", $payload);

            $body = $response->json() ?? [];

            Log::channel('fiscal')->info('Focus NFe: emissão solicitada', [
                'ref' => $ref,
                'status' => $response->status(),
            ]);

            if ($response->status() === 422 && isset($body['codigo']) && ! isset($body['status'])) {
                // 422 com 'codigo' (ex.: erro_validacao_schema) é rejeição imediata do Focus
                // antes de submeter à SEFAZ — não tem 'status' assíncrono, é erro definitivo.
                return new FiscalNoteResult(
                    status: 'error',
                    providerReference: $ref,
                    errorMessage: $body['mensagem'] ?? 'Erro de validação no Focus NFe',
                    rawResponse: $body,
                );
            }

            if ($response->successful() || $response->status() === 422) {
                $status = $this->mapStatus($body['status'] ?? 'processing');

                return new FiscalNoteResult(
                    status: $status,
                    providerReference: $ref,
                    accessKey: $body['chave_nfe'] ?? null,
                    xmlUrl: self::absoluteUrl($body['caminho_xml_nota_fiscal'] ?? null, $this->baseUrl),
                    danfeUrl: self::absoluteUrl($body['caminho_danfe'] ?? null, $this->baseUrl),
                    errorMessage: $body['mensagem_sefaz'] ?? ($body['erros'][0]['mensagem'] ?? null),
                    rawResponse: $body,
                );
            }

            return new FiscalNoteResult(
                status: 'error',
                providerReference: $ref,
                errorMessage: $body['mensagem'] ?? 'Erro na comunicação com Focus NFe',
                rawResponse: $body,
            );
        } catch (\Throwable $e) {
            Log::channel('fiscal')->error('Focus NFe: exceção ao emitir', [
                'order_id' => $dto->orderId,
                'error' => $e->getMessage(),
            ]);

            return new FiscalNoteResult(
                status: 'error',
                errorMessage: $e->getMessage(),
            );
        }
    }

    public function cancel(string $providerReference, string $justification): FiscalNoteResult
    {
        try {
            $response = Http::withBasicAuth($this->token, '')
                ->delete("{$this->baseUrl}/v2/nfce/{$providerReference}", [
                    'justificativa' => $justification,
                ]);

            $body = $response->json() ?? [];

            $status = $response->successful() ? 'cancelled' : 'error';

            return new FiscalNoteResult(
                status: $status,
                providerReference: $providerReference,
                errorMessage: $body['mensagem'] ?? null,
                rawResponse: $body,
            );
        } catch (\Throwable $e) {
            return new FiscalNoteResult(
                status: 'error',
                providerReference: $providerReference,
                errorMessage: $e->getMessage(),
            );
        }
    }

    public function query(string $providerReference): FiscalNoteResult
    {
        try {
            $response = Http::withBasicAuth($this->token, '')
                ->get("{$this->baseUrl}/v2/nfce/{$providerReference}");

            $body = $response->json() ?? [];
            $status = $this->mapStatus($body['status'] ?? 'error');

            return new FiscalNoteResult(
                status: $status,
                providerReference: $providerReference,
                accessKey: $body['chave_nfe'] ?? null,
                xmlUrl: self::absoluteUrl($body['caminho_xml_nota_fiscal'] ?? null, $this->baseUrl),
                danfeUrl: self::absoluteUrl($body['caminho_danfe'] ?? null, $this->baseUrl),
                rawResponse: $body,
            );
        } catch (\Throwable $e) {
            return new FiscalNoteResult(
                status: 'error',
                providerReference: $providerReference,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCompany(array $payload): array
    {
        return $this->requestCompany('post', '/v2/empresas', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateCompany(string $focusCompanyId, array $payload): array
    {
        return $this->requestCompany('put', "/v2/empresas/{$focusCompanyId}", $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function requestCompany(string $method, string $path, array $payload): array
    {
        $response = Http::withBasicAuth($this->token, '')->{$method}("{$this->baseUrl}{$path}", $payload);
        $body = $response->json() ?? [];

        if (! $response->successful()) {
            // 'erros[0].mensagem' é mais específico que 'mensagem' (que costuma ser
            // genérico, ex.: "Erro de validação") — prioriza o detalhe quando existir.
            throw new FocusNfeCompanyRegistrationException(
                message: $body['erros'][0]['mensagem'] ?? ($body['mensagem'] ?? 'Erro ao registrar empresa na Focus NFe'),
                statusCode: $response->status(),
                errors: $body['erros'] ?? [],
                rawResponse: $body,
            );
        }

        return $body;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listWebhooks(): array
    {
        $response = Http::withBasicAuth($this->token, '')->get("{$this->baseUrl}/v2/hooks");

        if (! $response->successful()) {
            throw new FocusNfeCompanyRegistrationException(
                message: $response->json('mensagem') ?? 'Erro ao listar webhooks na Focus NFe',
                statusCode: $response->status(),
                errors: $response->json('erros') ?? [],
                rawResponse: $response->json() ?? [],
            );
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createWebhook(array $payload): array
    {
        $response = Http::withBasicAuth($this->token, '')->post("{$this->baseUrl}/v2/hooks", $payload);
        $body = $response->json() ?? [];

        if (! $response->successful()) {
            throw new FocusNfeCompanyRegistrationException(
                message: $body['mensagem'] ?? ($body['erros'][0]['mensagem'] ?? 'Erro ao criar webhook na Focus NFe'),
                statusCode: $response->status(),
                errors: $body['erros'] ?? [],
                rawResponse: $body,
            );
        }

        return $body;
    }

    public function deleteWebhook(string $webhookId): void
    {
        $response = Http::withBasicAuth($this->token, '')->delete("{$this->baseUrl}/v2/hooks/{$webhookId}");

        if (! $response->successful()) {
            $body = $response->json() ?? [];

            throw new FocusNfeCompanyRegistrationException(
                message: $body['mensagem'] ?? 'Erro ao remover webhook na Focus NFe',
                statusCode: $response->status(),
                errors: $body['erros'] ?? [],
                rawResponse: $body,
            );
        }
    }

    /**
     * Focus NFe retorna caminho_danfe/caminho_xml_nota_fiscal como paths relativos
     * ao host da API (ex.: "/v2/nfce/xxx.pdf"), não URLs absolutas. Sem prefixar o
     * host, o link no navegador resolve contra o domínio do painel, não da Focus.
     */
    public static function absoluteUrl(?string $path, string $baseUrl): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function mapStatus(string $focusStatus): string
    {
        return match ($focusStatus) {
            'autorizado' => 'authorized',
            'cancelado' => 'cancelled',
            'erro_autorizacao', 'denegado' => 'rejected',
            'processing', 'processando_autorizacao' => 'pending',
            default => 'error',
        };
    }

    private function buildPayload(FiscalNoteDTO $dto): array
    {
        $items = [];
        foreach ($dto->items as $i => $item) {
            $items[] = [
                'numero_item' => $i + 1,
                'codigo_produto' => (string) ($i + 1),
                'descricao' => $item['name'],
                'cfop' => $item['cfop'] ?? '5102',
                'unidade_comercial' => 'UN',
                'quantidade_comercial' => $item['quantity'],
                'valor_unitario_comercial' => $item['unit_price'],
                'valor_bruto' => $item['subtotal'],
                'codigo_ncm' => $item['ncm'] ?? '21069090',
                'icms_situacao_tributaria' => $item['icms_situation'] ?? '400',
                'icms_origem' => 0,
                'pis_situacao_tributaria' => '07',
                'cofins_situacao_tributaria' => '07',
            ];
        }

        // Focus NFe identifica o emitente pelo CNPJ já cadastrado no painel deles
        // (endpoint /v2/empresas) — não se reenvia razão social/endereço/CRT a cada nota.
        $payload = [
            'natureza_operacao' => 'Venda de mercadoria',
            'data_emissao' => now()->toIso8601String(),
            'cnpj_emitente' => preg_replace('/\D/', '', $dto->emitenteCnpj),
            'serie' => (int) $dto->nfceSerie,
            'modalidade_frete' => '9',
            'local_destino' => '1',
            'presenca_comprador' => $this->mapPresencaComprador($dto->orderType),
            'items' => $items,
            'formas_pagamento' => [
                [
                    'forma_pagamento' => $this->mapPaymentMethod($dto->paymentMethod),
                    'valor_pagamento' => $dto->total,
                ],
            ],
        ];

        if ($dto->customerDocument) {
            $doc = preg_replace('/\D/', '', $dto->customerDocument);
            $payload['CPF'] = strlen($doc) === 11 ? $doc : null;
            $payload['CNPJ'] = strlen($doc) === 14 ? $doc : null;
        }

        return $payload;
    }

    private function mapPaymentMethod(string $method): string
    {
        return match (strtolower($method)) {
            'pix' => '17',
            'credit_card', 'cartao_credito' => '03',
            'debit_card', 'cartao_debito' => '04',
            'cash', 'dinheiro' => '01',
            default => '99',
        };
    }

    private function mapPresencaComprador(?string $orderType): string
    {
        return match ($orderType) {
            'delivery' => '4', // entrega a domicílio
            'pickup', 'pdv' => '1', // presencial (retirada no local ou balcão)
            default => '9', // não se aplica / não informado
        };
    }
}
