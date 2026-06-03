<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalNoteProviderInterface;
use App\DTOs\FiscalNoteDTO;
use App\DTOs\FiscalNoteResult;
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
            $response = Http::withToken($this->token)
                ->post("{$this->baseUrl}/v2/nfce?ref={$ref}", $payload);

            $body = $response->json() ?? [];

            Log::channel('webhook')->info('Focus NFe: emissão solicitada', [
                'ref' => $ref,
                'status' => $response->status(),
            ]);

            if ($response->successful() || $response->status() === 422) {
                $status = $this->mapStatus($body['status'] ?? 'processing');

                return new FiscalNoteResult(
                    status: $status,
                    providerReference: $ref,
                    accessKey: $body['chave_nfe'] ?? null,
                    xmlUrl: $body['caminho_xml_nota_fiscal'] ?? null,
                    danfeUrl: $body['caminho_danfe'] ?? null,
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
            Log::channel('webhook')->error('Focus NFe: exceção ao emitir', [
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
            $response = Http::withToken($this->token)
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

    public function query(string $accessKey): FiscalNoteResult
    {
        try {
            $response = Http::withToken($this->token)
                ->get("{$this->baseUrl}/v2/nfce/{$accessKey}");

            $body = $response->json() ?? [];
            $status = $this->mapStatus($body['status'] ?? 'error');

            return new FiscalNoteResult(
                status: $status,
                providerReference: $accessKey,
                accessKey: $body['chave_nfe'] ?? null,
                xmlUrl: $body['caminho_xml_nota_fiscal'] ?? null,
                danfeUrl: $body['caminho_danfe'] ?? null,
                rawResponse: $body,
            );
        } catch (\Throwable $e) {
            return new FiscalNoteResult(
                status: 'error',
                accessKey: $accessKey,
                errorMessage: $e->getMessage(),
            );
        }
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

        $payload = [
            'natureza_operacao' => 'Venda de mercadoria',
            'forma_pagamento' => 0,
            'emitente' => [
                'cnpj' => preg_replace('/\D/', '', $dto->emitenteCnpj),
                'nome' => $dto->emitenteNome,
                'logradouro' => $dto->emitenteLogradouro,
                'numero' => $dto->emitenteNumero,
                'bairro' => $dto->emitenteBairro,
                'municipio' => $dto->emitenteMunicipio,
                'uf' => $dto->emitenteUf,
                'cep' => preg_replace('/\D/', '', $dto->emitenteCep),
                'regime_tributario' => $dto->emitenteCrt,
            ],
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
}
