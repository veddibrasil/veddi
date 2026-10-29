<?php

namespace App\Contracts;

use App\DTOs\ProviderEventData;
use App\DTOs\RenderedEmailData;
use App\Exceptions\EmailProviderException;
use App\Exceptions\InvalidEmailWebhookException;
use Illuminate\Http\Request;

/**
 * Provedor de envio do e-mail marketing. Trocar de provedor (Resend, SES, Postmark...) é
 * implementar este contrato; as regras (público, supressão, rastreio) não mudam.
 */
interface EmailMarketingProviderInterface
{
    public function name(): string;

    /**
     * Envia um e-mail e devolve o id da mensagem no provedor.
     *
     * @throws EmailProviderException permanent=true não deve ser retentado
     */
    public function send(RenderedEmailData $email): string;

    public function supportsWebhooks(): bool;

    /**
     * Valida a assinatura e normaliza os eventos do webhook.
     *
     * @return list<ProviderEventData>
     *
     * @throws InvalidEmailWebhookException assinatura ausente ou inválida
     */
    public function parseWebhook(Request $request): array;
}
