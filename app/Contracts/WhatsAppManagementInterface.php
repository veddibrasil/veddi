<?php

namespace App\Contracts;

use App\Exceptions\WhatsAppApiException;

/**
 * Operações de gestão da WhatsApp Cloud API (onboarding e templates), separadas do envio
 * de mensagens (WhatsAppProviderInterface). Todas lançam WhatsAppApiException em caso de erro.
 */
interface WhatsAppManagementInterface
{
    /**
     * Troca o code do Embedded Signup por um token de acesso do restaurante.
     * O code é de uso único e expira em segundos.
     *
     * @throws WhatsAppApiException
     */
    public function exchangeCode(string $code): string;

    /**
     * Inspeciona o token com o token do app. waba_ids reúne os target_ids dos escopos
     * whatsapp_business_management/messaging (as WABAs que o restaurante autorizou).
     *
     * @return array{is_valid: bool, app_id: ?string, expires_at: int, scopes: array<int, string>, granular_scopes: array<int, array{scope: string, target_ids: array<int, string>}>, waba_ids: array<int, string>}
     *
     * @throws WhatsAppApiException
     */
    public function debugToken(#[\SensitiveParameter] string $token): array;

    /**
     * @return array<int, array{id: string, display_phone_number: ?string, verified_name: ?string, quality_rating: ?string}>
     *
     * @throws WhatsAppApiException
     */
    public function listPhoneNumbers(string $wabaId, #[\SensitiveParameter] string $token): array;

    /** @throws WhatsAppApiException */
    public function subscribeApp(string $wabaId, #[\SensitiveParameter] string $token): void;

    /** @throws WhatsAppApiException */
    public function unsubscribeApp(string $wabaId, #[\SensitiveParameter] string $token): void;

    /** @throws WhatsAppApiException */
    public function registerPhone(string $phoneNumberId, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $pin): void;

    /**
     * @return array{verified_name: ?string, display_phone_number: ?string, quality_rating: ?string, messaging_limit_tier: ?string}
     *
     * @throws WhatsAppApiException
     */
    public function getPhoneNumber(string $phoneNumberId, #[\SensitiveParameter] string $token): array;

    /**
     * Cria um template de mensagem na WABA (com example.body_text, exigido pela Meta).
     *
     * @param  array{name: string, language: string, category: string, body: string, example: array<int, string>}  $definition
     * @return array{id: ?string, status: string, category: ?string}
     *
     * @throws WhatsAppApiException
     */
    public function createTemplate(string $wabaId, #[\SensitiveParameter] string $token, array $definition): array;

    /**
     * Todos os templates da WABA (segue a paginação).
     *
     * @return array<int, array{id: string, name: string, language: string, status: string, category: ?string, rejected_reason: ?string}>
     *
     * @throws WhatsAppApiException
     */
    public function listTemplates(string $wabaId, #[\SensitiveParameter] string $token): array;
}
