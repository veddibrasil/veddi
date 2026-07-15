<?php

namespace App\Contracts;

use App\DTOs\FiscalNoteDTO;
use App\DTOs\FiscalNoteResult;

interface FiscalNoteProviderInterface
{
    public function issue(FiscalNoteDTO $dto): FiscalNoteResult;

    public function cancel(string $providerReference, string $justification): FiscalNoteResult;

    /**
     * Consulta uma NFC-e pela referência usada na emissão (o `ref` enviado a /v2/nfce),
     * não pela chave de acesso (chave_nfe) — GET /v2/nfce/{referencia} na doc da Focus NFe.
     */
    public function query(string $providerReference): FiscalNoteResult;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCompany(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateCompany(string $focusCompanyId, array $payload): array;

    /**
     * Lista todos os webhooks (gatilhos) cadastrados para o token — GET /v2/hooks.
     * A Focus NFe não oferece filtro por cnpj/evento na API, então o filtro é feito
     * pelo chamador sobre o array retornado.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listWebhooks(): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createWebhook(array $payload): array;

    public function deleteWebhook(string $webhookId): void;
}
