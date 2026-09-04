<?php

namespace App\Services\Ifood;

use App\Models\IfoodIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class IfoodAuthService
{
    /**
     * Margem de segurança antes da expiração real — evita usar um token que
     * expira no meio de uma chamada em andamento.
     */
    private const EXPIRY_MARGIN_SECONDS = 60;

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('ifood.api_base_url');
    }

    /**
     * Retorna um access_token válido para a integração, renovando se necessário.
     * Token é persistido na própria IfoodIntegration — nunca em cache compartilhado,
     * pra não arriscar vazamento de token entre empresas diferentes.
     */
    public function getAccessToken(IfoodIntegration $integration): string
    {
        if (! $this->isExpiredWithMargin($integration) && $integration->access_token) {
            return $integration->access_token;
        }

        return $this->refreshToken($integration);
    }

    public function refreshToken(IfoodIntegration $integration): string
    {
        $response = Http::asForm()->post("{$this->baseUrl}/authentication/v1.0/oauth/token", [
            'grantType' => 'refresh_token',
            'clientId' => config('ifood.partner_client_id'),
            'clientSecret' => config('ifood.partner_client_secret'),
            'refreshToken' => $integration->refresh_token,
        ]);

        if ($response->failed()) {
            Log::channel('ifood')->error('iFood: falha ao renovar token', [
                'ifood_integration_id' => $integration->id,
                'company_id' => $integration->company_id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException("iFood: falha ao renovar token integration_id={$integration->id} (status {$response->status()})");
        }

        $data = $response->json();
        $accessToken = $data['accessToken'] ?? null;
        $expiresIn = $data['expiresIn'] ?? null;

        if (! $accessToken) {
            Log::channel('ifood')->error('iFood: resposta de renovação sem accessToken', [
                'ifood_integration_id' => $integration->id,
                'body' => $data,
            ]);

            throw new RuntimeException("iFood: resposta de renovação sem accessToken (integration_id={$integration->id})");
        }

        $integration->access_token = $accessToken;
        // iFood pode rotacionar o refresh_token a cada renovação (comportamento comum
        // em provedores OAuth) — não confirmado em sandbox, mas persistir o novo valor
        // quando presente é inofensivo mesmo se ele não rotacionar de fato.
        $integration->refresh_token = $data['refreshToken'] ?? $integration->refresh_token;
        $integration->token_expires_at = $expiresIn ? now()->addSeconds((int) $expiresIn) : now()->addMinutes(30);
        $integration->save();

        return $accessToken;
    }

    /**
     * Inicia o fluxo de autorização "aplicativo distribuído" do iFood: pede um
     * userCode pra plataforma (aplicação parceira única, não mais por empresa) e
     * grava os dados que o restaurante vai usar pra autorizar no portal do iFood.
     * Chamada síncrona a partir da tela de conexão — usuário está esperando.
     */
    public function requestUserCode(IfoodIntegration $integration): void
    {
        $response = Http::asForm()->post("{$this->baseUrl}/authentication/v1.0/oauth/userCode", [
            'clientId' => config('ifood.partner_client_id'),
        ]);

        if ($response->failed()) {
            Log::channel('ifood')->error('iFood: falha ao solicitar userCode', [
                'ifood_integration_id' => $integration->id,
                'company_id' => $integration->company_id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException("iFood: falha ao solicitar userCode integration_id={$integration->id} (status {$response->status()})");
        }

        $data = $response->json();

        $integration->user_code = $data['userCode'] ?? null;
        $integration->authorization_code_verifier = $data['authorizationCodeVerifier'] ?? null;
        $integration->verification_url = $data['verificationUrlComplete'] ?? ($data['verificationUrl'] ?? null);
        $integration->user_code_expires_at = now()->addSeconds((int) ($data['expiresIn'] ?? 600));
        $integration->merchant_id = null;
        $integration->status = 'disconnected';
        $integration->save();
    }

    /**
     * Completa a autorização trocando o authorizationCode por um token.
     *
     * Confirmado contra sandbox real (2026-09-03): diferente do que a doc
     * pública sugere, o userCode NÃO serve como authorizationCode — depois que
     * o restaurante aprova no portal do iFood, o próprio portal exibe um
     * authorizationCode DIFERENTE (formato igual ao userCode, ex. "HTLM-KWVR"),
     * que o restaurante precisa colar de volta aqui. Não existe polling
     * automático possível: sem esse código colado manualmente, toda tentativa
     * de troca retorna 401 {"error":{"code":"Unauthorized","message":"Invalid
     * authorization code ..."}} — inclusive antes de qualquer aprovação. Por
     * isso este método é chamado de forma síncrona a partir do Livewire quando
     * o usuário confirma o código, não por um job em background.
     */
    public function completeAuthorization(IfoodIntegration $integration, string $authorizationCode): void
    {
        $response = Http::asForm()->post("{$this->baseUrl}/authentication/v1.0/oauth/token", [
            'grantType' => 'authorization_code',
            'clientId' => config('ifood.partner_client_id'),
            'clientSecret' => config('ifood.partner_client_secret'),
            'authorizationCode' => $authorizationCode,
            'authorizationCodeVerifier' => $integration->authorization_code_verifier,
        ]);

        if ($response->failed()) {
            Log::channel('ifood')->error('iFood: falha ao trocar authorizationCode por token', [
                'ifood_integration_id' => $integration->id,
                'company_id' => $integration->company_id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException("iFood: falha ao trocar authorizationCode integration_id={$integration->id} (status {$response->status()})");
        }

        $data = $response->json();
        $accessToken = $data['accessToken'] ?? null;

        if (! $accessToken) {
            throw new RuntimeException("iFood: resposta de troca de authorizationCode sem accessToken (integration_id={$integration->id})");
        }

        $merchants = $this->listMerchants($accessToken);

        if ($merchants === []) {
            throw new RuntimeException("iFood: nenhum merchant autorizado encontrado após troca de token (integration_id={$integration->id})");
        }

        $integration->access_token = $accessToken;
        $integration->refresh_token = $data['refreshToken'] ?? null;
        $integration->token_expires_at = isset($data['expiresIn'])
            ? now()->addSeconds((int) $data['expiresIn'])
            : now()->addMinutes(30);
        $integration->user_code = null;
        $integration->authorization_code_verifier = null;
        $integration->verification_url = null;
        $integration->user_code_expires_at = null;

        if (count($merchants) === 1) {
            $integration->merchant_id = $merchants[0]['id'];
            $integration->available_merchants = null;
            $integration->status = 'active';
        } else {
            // Autorização cobre mais de uma loja — não dá pra escolher sozinho, guarda
            // a lista e deixa status fora de 'active' até o admin escolher (ver
            // selectMerchant, chamado pela tela de configurações).
            Log::channel('ifood')->info('iFood: autorização cobre mais de um merchant, aguardando escolha do admin', [
                'ifood_integration_id' => $integration->id,
                'merchant_ids' => array_column($merchants, 'id'),
            ]);

            $integration->merchant_id = null;
            $integration->available_merchants = $merchants;
            $integration->status = 'disconnected';
        }

        $integration->save();
    }

    /**
     * Confirma a escolha de loja quando a autorização cobriu mais de uma
     * (ver completeAuthorization). $merchantId precisa estar entre os
     * available_merchants salvos — não aceita id arbitrário.
     */
    public function selectMerchant(IfoodIntegration $integration, string $merchantId): void
    {
        $merchants = $integration->available_merchants ?? [];
        $valid = in_array($merchantId, array_column($merchants, 'id'), true);

        if (! $valid) {
            throw new RuntimeException("iFood: merchant_id '{$merchantId}' não está entre os merchants autorizados desta integração (integration_id={$integration->id}).");
        }

        $integration->merchant_id = $merchantId;
        $integration->available_merchants = null;
        $integration->status = 'active';
        $integration->save();
    }

    /**
     * Endpoint/formato assumido via doc pública do iFood + pacote de referência
     * — confirmado em sandbox real só pro caso de 1 merchant (ver [[ifood_integration]]
     * na memória do projeto); caminho de múltiplos merchants ainda não exercido
     * contra o sandbox de verdade.
     *
     * @return array<int, array{id: string, name: ?string}>
     */
    private function listMerchants(string $accessToken): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withToken($accessToken)
            ->acceptJson()
            ->get('/merchant/v1.0/merchants');

        if ($response->failed()) {
            Log::channel('ifood')->error('iFood: falha ao listar merchants autorizados', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $merchants = $response->json() ?? [];

        return collect($merchants)
            ->filter(fn ($merchant) => ! empty($merchant['id']))
            ->map(fn ($merchant) => ['id' => $merchant['id'], 'name' => $merchant['name'] ?? $merchant['id']])
            ->values()
            ->all();
    }

    private function isExpiredWithMargin(IfoodIntegration $integration): bool
    {
        if ($integration->token_expires_at === null) {
            return true;
        }

        return now()->addSeconds(self::EXPIRY_MARGIN_SECONDS)->greaterThanOrEqualTo($integration->token_expires_at);
    }
}
